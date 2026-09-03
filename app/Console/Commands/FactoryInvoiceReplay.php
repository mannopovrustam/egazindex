<?php

namespace App\Console\Commands;

use App\Services\DbTriggers\TbFactoryIntegrationTriggers;
use App\Services\DbTriggers\TriggerBus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * `integration_logs` dagi FactoryInvoice payload larini QAYTA yurgizadi:
 * tb_factory_integration + tb_fc_invoices ga yozadi.
 *
 * NEGA KERAK. Zavod integratsiyasi (1C → POST /factory-invoice) kelgan
 * payload ni AVVAL `integration_logs` ga yozadi, KEYIN uni jadvallarga
 * o'tkazadi — App\Services\FactoryInvoice::pullRequest():
 *
 *     integration_logs        ← har doim yoziladi (log)
 *        ↓ tekshiruv (factory / hgt_filial ro'yxati)
 *     tb_factory_integration  ← `mysql` (egaz_idxdb) + nusxa `pgsql`
 *        ↓ tb_factory_integration_bi triggeri (kod → egaz org id)
 *     tb_fc_invoices          ← `mysql1` (brrgz) + nusxa `pgsql1`
 *
 * Ikkinchi bosqich yiqilsa payload logda QOLADI, lekin jadvallarga
 * TUSHMAYDI — servis xatoni faqat javob matni sifatida qaytaradi, 1C esa
 * uni ko'rmaydi. Shu buyruq o'sha "logda bor, jadvalda yo'q" qatorlarni
 * topadi, SABABINI aytadi va yozib qo'yadi.
 *
 * ⚠ integration_logs da `id` ustuni YO'Q (module, request, payload, value,
 *   created_at). Shuning uchun qatorlar `created_at` bo'yicha ajratiladi va
 *   takror yozib yubormaslik uchun har bir payload jadvalda BOR-YO'QLIGI
 *   alohida tekshiriladi:
 *     tb_factory_integration → UNIQUE (numb, dt)
 *     tb_fc_invoices         → descr = 'Zavod Integratsiya:<tb_factory_integration.id>'
 *
 * MISOLLAR:
 *   # avval FAQAT tekshirish — nima yozilishini va nega tushmaganini ko'rsatadi
 *   php artisan factory-invoice:replay --from="2026-09-03 16:00:14" --dry-run
 *
 *   # haqiqiy yozish
 *   php artisan factory-invoice:replay --from="2026-09-03 16:00:14"
 *
 *   # tb_factory_integration to'lgan, faqat tb_fc_invoices chala qolgan bo'lsa
 *   php artisan factory-invoice:replay --from="2026-09-03 16:00:14" --only-invoices
 *
 *   # loglar PostgreSQL nusxasidan o'qilsin
 *   php artisan factory-invoice:replay --logs=pgsql --integration=pgsql --invoices=pgsql1
 *
 * @see \App\Services\FactoryInvoice::pullRequest()
 * @see \App\Services\DbTriggers\TbFactoryIntegrationTriggers
 */
class FactoryInvoiceReplay extends Command
{
    protected $signature = 'factory-invoice:replay
                            {--from= : integration_logs.created_at > shu payt (Y-m-d H:i:s)}
                            {--to= : integration_logs.created_at <= shu payt}
                            {--module=FactoryInvoice : integration_logs.module qiymati}
                            {--logs= : integration_logs qaysi ulanishda (standart: standart ulanish)}
                            {--integration= : tb_factory_integration qaysi ulanishda (standart: --logs bilan bir xil)}
                            {--invoices=mysql1 : tb_fc_invoices qaysi ulanishda}
                            {--only-invoices : tb_factory_integration ga tegmaydi, faqat yetishmagan tb_fc_invoices ni yozadi}
                            {--limit=0 : Ko`pi bilan shuncha log qatori (0 = cheksiz)}
                            {--dry-run : Bazaga yozmaydi — faqat tashxis}
                            {--show-errors=20 : Hisobotda ko`rsatiladigan muammoli qatorlar soni}';

    protected $description = 'integration_logs (FactoryInvoice) payload larini tb_factory_integration va tb_fc_invoices ga qayta yozadi';

    /** Servisdagi ro'yxatning AYNAN o'zi — FactoryInvoice::pullRequest() */
    private static $factories = ['000000002', '000000004', '000000006', '000000009', '000000010'];

    private static $filials = [
        '000000001', '000000002', '000000003', '000000004', '000000005',
        '000000006', '000000007', '000000008', '000000009', '000000010',
        '000000011', '000000012', '000000013',
    ];

    /** @var string|null */
    private $logsConn;
    /** @var string|null */
    private $intConn;
    /** @var string */
    private $invConn;
    /** @var bool */
    private $dry;

    /** Sabab => nechta. Tashxis hisoboti shu yerdan quriladi. */
    private $stat = [];
    /** Muammoli qatorlarning namunalari: [sabab, created_at, numb, izoh] */
    private $samples = [];

    public function handle()
    {
        $this->logsConn = $this->option('logs') ?: null;
        $this->intConn  = $this->option('integration') ?: $this->logsConn;
        $this->invConn  = $this->option('invoices');
        $this->dry      = (bool) $this->option('dry-run');

        $module = $this->option('module');
        $from   = $this->normalizeDt($this->option('from'));
        $to     = $this->normalizeDt($this->option('to'));
        $limit  = max(0, (int) $this->option('limit'));

        if ($from === null && $to === null) {
            $this->error('Kamida --from bering, masalan: --from="2026-09-03 16:00:14"');
            return 1;
        }

        $this->line('');
        $this->info('=== FactoryInvoice — integration_logs dan qayta yurgizish ===');
        $this->line('  integration_logs      : ' . $this->connLabel($this->logsConn));
        $this->line('  tb_factory_integration: ' . $this->connLabel($this->intConn));
        $this->line('  tb_fc_invoices        : ' . $this->connLabel($this->invConn));
        $this->line('  module                : ' . $module);
        $this->line('  Davr                  : ' . ($from ?: '(boshidan)') . ' … ' . ($to ?: '(hozirgacha)'));
        $this->line('  Rejim                 : ' . ($this->dry ? 'DRY RUN — bazaga yozilmaydi' : 'YOZISH')
            . ($this->option('only-invoices') ? ' | FAQAT tb_fc_invoices' : ''));

        // ---- 1. Ulanishlar joyidami ------------------------------------------
        if (! $this->checkConnections()) return 1;

        // ---- 2. Loglarni o'qish ----------------------------------------------
        try {
            $q = DB::connection($this->logsConn)->table('integration_logs')
                ->where('module', $module);
            if ($from !== null) $q->where('created_at', '>', $from);
            if ($to !== null)   $q->where('created_at', '<=', $to);
            $q->orderBy('created_at');
            if ($limit) $q->limit($limit);

            $rows = $q->get();
        } catch (\Exception $e) {
            $this->error('integration_logs o`qilmadi: ' . $e->getMessage());
            return 1;
        }

        $total = count($rows);
        $this->line('  Log qatorlari         : ' . $total);
        $this->line('');

        if (! $total) {
            $this->warn('Bu davrda FactoryInvoice logi yo`q.');
            $this->line('  Demak muammo BUNDAN OLDINROQ: so`rov umuman kelmayapti yoki');
            $this->line('  autentifikatsiyada (FACTORYINVOICE_LOGIN / _PSW) to`xtayapti —');
            $this->line('  chunki integration_logs ga yozuv tekshiruvdan KEYIN qo`shiladi.');
            $this->line('');
            return 0;
        }

        // ---- 3. Jadval ustunlari ---------------------------------------------
        $intCols = $this->columns($this->intConn, 'tb_factory_integration');
        if ($intCols === null) return 1;

        // ---- 4. Har bir logni yurgizish ---------------------------------------
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $writtenInt = 0;
        $writtenInv = 0;

        foreach ($rows as $row) {
            $res = $this->replayOne($row, $intCols);
            $writtenInt += $res[0];
            $writtenInv += $res[1];
            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->line('');

        // ---- 5. Hisobot -------------------------------------------------------
        $this->report($total, $writtenInt, $writtenInv);

        return 0;
    }

    /**
     * Bitta log qatorini yurgizadi.
     *
     * @return array [tb_factory_integration ga yozildimi, tb_fc_invoices ga yozildimi]
     */
    private function replayOne($row, array $intCols)
    {
        $createdAt = isset($row->created_at) ? (string) $row->created_at : '';
        $payload   = isset($row->payload) ? $row->payload : null;

        $data = json_decode((string) $payload, true);
        if (! is_array($data)) {
            $this->problem('payload JSON emas', $createdAt, '', substr((string) $payload, 0, 120));
            return [0, 0];
        }

        // --- servisdagi tayyorlash bosqichining AYNAN o'zi ---
        unset($data['model_auto']);

        if (! isset($data['dt']) || $data['dt'] === '') {
            $this->problem('dt yo`q', $createdAt, isset($data['numb']) ? $data['numb'] : '');
            return [0, 0];
        }
        $ts = strtotime($data['dt']);
        if ($ts === false) {
            $this->problem('dt o`qilmadi', $createdAt, isset($data['numb']) ? $data['numb'] : '', (string) $data['dt']);
            return [0, 0];
        }
        $data['dt'] = date('Y-m-d H:i:s', $ts);

        $numb = isset($data['numb']) ? (string) $data['numb'] : '';
        if ($numb === '') {
            $this->problem('numb bo`sh', $createdAt, '', substr(json_encode($data), 0, 120));
            return [0, 0];
        }

        // --- servisdagi tekshiruvlar: shu yerda "jim" rad javob qaytadi ---
        $factory = isset($data['factory']) ? (string) $data['factory'] : '';
        if (! in_array($factory, self::$factories, true)) {
            $this->problem('Invalid factory! (servis rad etadi)', $createdAt, $numb, 'factory=' . $factory);
            return [0, 0];
        }
        $filial = isset($data['hgt_filial']) ? (string) $data['hgt_filial'] : '';
        if (! in_array($filial, self::$filials, true)) {
            $this->problem('Invalid hgt_filial! (servis rad etadi)', $createdAt, $numb, 'hgt_filial=' . $filial);
            return [0, 0];
        }

        // --- jadvalda bo'lmagan ustunlar (1C yangi maydon qo'shgan bo'lishi mumkin) ---
        $extra = array_diff(array_keys($data), $intCols);
        if ($extra) {
            $this->note('payload da ortiqcha ustun: ' . implode(', ', $extra));
            $data = array_intersect_key($data, array_flip($intCols));
        }

        // `tb_factory_integration_bi` triggeri qiladigan ishni oldindan bajaramiz:
        // MySQL da baza triggeri baribir qayta yozadi (natija bir xil), PG da esa
        // DB trigger yo'q — PHP triggeri bayrog'i o'chiq bo'lsa ham qator to'g'ri
        // ketadi (hgt_filial_egaz NOT NULL).
        if (in_array('hgt_filial_egaz', $intCols, true) && ! isset($data['hgt_filial_egaz'])) {
            $data['hgt_filial_egaz'] = TbFactoryIntegrationTriggers::egazFilial($filial);
        }
        if (in_array('factory_egaz', $intCols, true) && ! isset($data['factory_egaz'])) {
            $data['factory_egaz'] = TbFactoryIntegrationTriggers::egazFactory($factory);
        }

        // ---- tb_factory_integration ------------------------------------------
        $doneInt = 0;
        try {
            $fac = DB::connection($this->intConn)->table('tb_factory_integration')
                ->where('numb', $numb)->where('dt', $data['dt'])->first();
        } catch (\Exception $e) {
            $this->problem('tb_factory_integration o`qilmadi', $createdAt, $numb, $e->getMessage());
            return [0, 0];
        }

        if ($fac === null) {
            if ($this->option('only-invoices')) {
                $this->problem('tb_factory_integration da yo`q (--only-invoices)', $createdAt, $numb);
                return [0, 0];
            }
            if ($this->dry) {
                // id hali yo'q — tb_fc_invoices tekshiruvini o'tkazib bo'lmaydi
                $this->count('tb_factory_integration + tb_fc_invoices ga YOZILADI');
                return [0, 0];
            }
            try {
                $id  = TriggerBus::insertGetId('tb_factory_integration', $data, $this->intConn);
                $fac = DB::connection($this->intConn)->table('tb_factory_integration')->where('id', $id)->first();
                if ($fac === null) {
                    $this->problem('yozildi, lekin qayta o`qilmadi', $createdAt, $numb, 'id=' . $id);
                    return [1, 0];
                }
                $doneInt = 1;
                $this->count('tb_factory_integration ga yozildi');
                Log::info('FactoryInvoiceReplay: tb_factory_integration id=' . $id . ' numb=' . $numb . ' dt=' . $data['dt']);
            } catch (\Exception $e) {
                $this->problem('tb_factory_integration INSERT xatosi', $createdAt, $numb, $e->getMessage());
                return [0, 0];
            }
        } else {
            $this->count('tb_factory_integration da allaqachon bor');
        }

        // ---- tb_fc_invoices ---------------------------------------------------
        $descr = 'Zavod Integratsiya:' . $fac->id;

        try {
            $exists = DB::connection($this->invConn)->table('tb_fc_invoices')
                ->where('descr', $descr)->exists();
        } catch (\Exception $e) {
            $this->problem('tb_fc_invoices o`qilmadi', $createdAt, $numb, $e->getMessage());
            return [$doneInt, 0];
        }

        if ($exists) {
            $this->count('tb_fc_invoices da allaqachon bor');
            return [$doneInt, 0];
        }

        if ($this->dry) {
            $this->count('tb_fc_invoices ga YOZILADI (tb_factory_integration da bor)');
            return [$doneInt, 0];
        }

        try {
            DB::connection($this->invConn)->table('tb_fc_invoices')->insert($this->invoiceRow($fac));
            $this->count('tb_fc_invoices ga yozildi');
            Log::info('FactoryInvoiceReplay: tb_fc_invoices ' . $descr . ' numb=' . $numb);
            return [$doneInt, 1];
        } catch (\Exception $e) {
            $this->problem('tb_fc_invoices INSERT xatosi', $createdAt, $numb, $e->getMessage());
            return [$doneInt, 0];
        }
    }

    /**
     * tb_fc_invoices qatori — FactoryInvoice::pullRequest() dagi `$inv_detail`
     * ning AYNAN nusxasi (bir joyda o'zgarsa, ikkinchisi ham o'zgarishi kerak).
     */
    private function invoiceRow($fac)
    {
        return [
            'numb'           => $fac->numb,
            'numb_plomb'     => 1,
            'id_factory'     => $fac->factory_egaz,
            'dt'             => date('Y-m-d', strtotime($fac->dt)),
            'qty_output'     => $fac->qty_output * 1000,
            'qty_accepted'   => 0.000,
            'id_oblgaz'      => $fac->hgt_filial_egaz,
            'out_tp'         => $fac->out_tp == 'auto' ? 'avto' : 'vagon',
            'vagon_drv_name' => $fac->vagon_drv_name,
            'descr'          => 'Zavod Integratsiya:' . $fac->id,
            'numb_auto'      => $fac->numb_auto,
            'numb_pricep'    => $fac->numb_pricep,
            'entry_by'       => 0,
            'brutto'         => $fac->brutto,
            'netto'          => $fac->netto,
            'created_at'     => $fac->created_at,
        ];
    }

    // ------------------------------------------------------------------ //
    //  Yordamchilar                                                       //
    // ------------------------------------------------------------------ //

    /** Har bir ulanish ochiladimi — ochilmasa buyruq boshlanmaydi. */
    private function checkConnections()
    {
        $ok = true;
        foreach ([[$this->logsConn, 'integration_logs'],
                  [$this->intConn, 'tb_factory_integration'],
                  [$this->invConn, 'tb_fc_invoices']] as $pair) {
            list($name, $table) = $pair;
            try {
                DB::connection($name)->getPdo();
            } catch (\Exception $e) {
                $this->error('  ✗ ' . $this->connLabel($name) . ' (' . $table . ') — ulanmadi: ' . $e->getMessage());
                $ok = false;
            }
        }
        if (! $ok) {
            $this->line('');
            $this->line('  Ulanishlarni tekshirish: php artisan pg:check');
        }
        return $ok;
    }

    /** Jadval ustunlari ro'yxati; jadval yo'q bo'lsa null. */
    private function columns($conn, $table)
    {
        try {
            $cols = Schema::connection($conn)->getColumnListing($table);
        } catch (\Exception $e) {
            $this->error($table . ' ustunlari o`qilmadi (' . $this->connLabel($conn) . '): ' . $e->getMessage());
            return null;
        }
        if (! $cols) {
            $this->error($table . ' jadvali ' . $this->connLabel($conn) . ' da topilmadi.');
            return null;
        }
        return $cols;
    }

    /** '2026-09-03' ni ham, to'liq paytni ham qabul qiladi. */
    private function normalizeDt($v)
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        $ts = strtotime($v);
        if ($ts === false) {
            $this->warn('Sana o`qilmadi: ' . $v);
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function connLabel($name)
    {
        return $name ?: (config('database.default') . ' (standart)');
    }

    private function count($reason)
    {
        if (! isset($this->stat[$reason])) $this->stat[$reason] = 0;
        $this->stat[$reason]++;
    }

    private function note($reason)
    {
        $this->count('OGOHLANTIRISH: ' . $reason);
    }

    private function problem($reason, $createdAt, $numb, $detail = '')
    {
        $this->count('XATO: ' . $reason);
        if (count($this->samples) < (int) $this->option('show-errors')) {
            $this->samples[] = [$reason, $createdAt, $numb, $this->firstLine($detail)];
        }
    }

    private function firstLine($s)
    {
        $s = trim(preg_replace('/\s+/', ' ', (string) $s));
        return strlen($s) > 140 ? substr($s, 0, 140) . '…' : $s;
    }

    private function report($total, $writtenInt, $writtenInv)
    {
        $this->info('--- Natija ---');

        $table = [];
        foreach ($this->stat as $reason => $n) {
            $table[] = [$reason, $n];
        }
        if ($table) {
            $this->table(['Holat / sabab', 'Soni'], $table);
        }

        if ($this->samples) {
            $this->line('');
            $this->warn('Muammoli qatorlar (birinchi ' . count($this->samples) . ' ta):');
            $this->table(['Sabab', 'log created_at', 'numb', 'Tafsilot'], $this->samples);
        }

        $this->line('');
        if ($this->dry) {
            $this->line('  DRY RUN edi — hech narsa yozilmadi. Yozish uchun --dry-run ni olib tashlang.');
        } else {
            $this->info('  tb_factory_integration : ' . $writtenInt . ' ta yangi qator');
            $this->info('  tb_fc_invoices         : ' . $writtenInv . ' ta yangi qator');
        }
        $this->line('  Jami ko`rilgan log     : ' . $total);
        $this->line('');
    }
}
