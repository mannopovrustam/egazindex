<?php

namespace App\Console\Commands;

use App\Services\DbTriggers\IRealDetailsTriggers;
use App\Services\DbTriggers\TriggerBus;
use App\Services\DbTriggers\TriggerFlags;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `insert_i_real_details` (AFTER INSERT ON i_real_details) triggerini QO'LDA yurgizish.
 *
 * Trigger nima qiladi: har bir yangi `i_real_details` qatorini tashkilot/mahalla/kun
 * kesimida `i_real_orgs` ga yig'adi (amount_total += amount, total_qty += 1).
 * Manba: docs/idxdb_triggers.sql, PHP versiyasi:
 * App\Services\DbTriggers\IRealDetailsTriggers::insertIRealDetails().
 *
 * Bu buyruq YANGI mantiq yozmaydi — mavjud qatorlarni o'sha triggerning o'zidan
 * (TriggerBus::afterInsert → IRealDetailsTriggers) o'tkazadi. Ya'ni natija
 * trigger o'z vaqtida yongan holat bilan aynan bir xil bo'ladi.
 *
 * NEGA KERAK: trigger na bazada, na PHP bayrog'ida yoqilgan bo'lishi mumkin
 * (`php artisan triggers:status` → "HECH QAYERDA YO'Q"), yoki `i_real_details`
 * ga qatorlar trigger yonmaydigan yo'l bilan tushgan bo'lishi mumkin
 * (TRUNCATE + qayta yuklash, `pg:sync`, tashqi sinxronizatsiya). Bunda
 * `i_real_orgs` agregati bo'sh yoki chala qoladi.
 *
 * ⚠ Juftlik triggeri `minus_deposit_orgs` (organizations.deposit -= amount) bu
 *   yerda ATAYLAB o'chirilgan: buyruq faqat agregatni tiklaydi, depozitga
 *   tegmaydi (aks holda har takrorlashda depozit qayta kamayardi).
 *
 * ⚙ IKKI BAZA (config/dual_write.php + db_triggers.php_connections):
 *   Agregat HAR BIR bazada alohida tiklanadi — buyruq qaysi ulanishda
 *   ishlasa, o'sha bazaning i_real_orgs ini qayta quradi:
 *
 *     php artisan triggers:irl-detail --date=2026-08-25                  # MySQL (egaz_idxdb)
 *     php artisan triggers:irl-detail --date=2026-08-25 --connection=pgsql   # PostgreSQL nusxasi
 *
 *   Sabab: `db_triggers.php_connections` odatda faqat ['pgsql','pgsql1'] —
 *   ya'ni MySQL da PHP triggeri o'chiq (u yerda BAZA triggeri bor). Bu buyruq
 *   esa ataylab qo'lda yurgizish uchun, shuning uchun ish davomida o'sha
 *   cheklovni VAQTINCHA olib turadi va tanlangan ulanishda triggerni bajaradi.
 *   Buyruqning o'zi DELETE/upsert ni to'g'ridan-to'g'ri o'sha ulanishda
 *   bajaradi — dual write ikkinchi nusxaga qayta yozmaydi, chunki har bir
 *   bazani alohida yurgizasiz.
 */
class InsertIRealDetailsReplay extends Command
{
    protected $signature = 'triggers:irl-detail
                            {--date= : Bitta kun (Y-m-d)}
                            {--from= : Davr boshi (Y-m-d)}
                            {--to= : Davr oxiri (Y-m-d)}
                            {--org= : Faqat shu tashkilot (i_real_details.id_org)}
                            {--append : i_real_orgs ni tozalamaslik — sof trigger takrori (JAMLANADI!)}
                            {--dry-run : Bazaga yozmaydi, faqat hisobot va log}
                            {--chunk=1000 : Bir martada o`qiladigan qatorlar soni}
                            {--connection= : Ulanish nomi (standart: config db_triggers.connection)}
                            {--force : Baza triggeri hali turgan bo`lsa ham davom etish}';

    protected $description = '`insert_i_real_details` triggerini qo`lda yurgizadi: i_real_details -> i_real_orgs agregati';

    /** @var string|null */
    private $conn;

    public function handle()
    {
        $this->conn = $this->option('connection') ?: TriggerFlags::connection();

        // ---- 1. Davrni aniqlash ------------------------------------------------
        $scope = $this->resolveScope();
        if ($scope === null) return 1;
        list($from, $to, $org) = $scope;

        $dry    = (bool) $this->option('dry-run');
        $append = (bool) $this->option('append');
        $chunk  = max(1, (int) $this->option('chunk'));

        $this->line('');
        $this->info('=== insert_i_real_details — qo`lda yurgizish ===');
        $this->line('  Ulanish : ' . ($this->conn ?: 'mysql (standart, egaz_idxdb)'));
        $this->line('  Davr    : ' . $from . ' … ' . $to . ($org ? '   id_org=' . $org : ''));
        $this->line('  Rejim   : ' . ($dry ? 'DRY RUN (yozilmaydi)' : 'YOZISH')
            . ($append ? ' | APPEND (tozalanmaydi)' : ' | i_real_orgs qayta quriladi'));

        // ---- 2. Baza triggeri hali turibdimi? ---------------------------------
        $inDb = $this->dbTriggerExists(IRealDetailsTriggers::T_ORGS);
        if ($inDb === true) {
            $this->warn('  Baza triggeri `insert_i_real_details` HALI TURIBDI — agregat allaqachon yuritilayotgan bo`lishi mumkin.');
            if ($append && !$this->option('force')) {
                $this->error('  --append bilan bu IKKI MARTA hisoblashga olib keladi. --force bering yoki --append ni olib tashlang.');
                return 1;
            }
        } elseif ($inDb === false) {
            $this->line('  Baza triggeri : yo`q (o`chirilgan)');
        } else {
            $this->warn('  Baza triggeri : aniqlanmadi (information_schema o`qilmadi)');
        }
        $this->line('  PHP bayrog`i  : ' . (TriggerFlags::enabledOn(IRealDetailsTriggers::T_ORGS, $this->conn) ? 'yoqiq' : 'o`chiq')
            . ' (bu buyruq uni shu ish uchun vaqtincha yoqadi)');
        $this->line('  Batafsil holat: php artisan triggers:status'
            . ($this->conn ? ' --connection=' . $this->conn : ''));

        // ---- 3. Manba qatorlar --------------------------------------------------
        try {
            $src   = $this->detailsQuery($from, $to, $org);
            $total = (clone $src)->count();
        } catch (\Exception $e) {
            $this->error('i_real_details o`qilmadi: ' . $e->getMessage());
            Log::error('triggers:irl-detail — manba o`qilmadi: ' . $e->getMessage());
            return 1;
        }

        if (!$total) {
            $this->line('');
            $this->info('Bu davrda i_real_details da qator yo`q — bajariladigan ish topilmadi.');
            return 0;
        }
        $this->line('  Qatorlar : ' . number_format($total, 0, '.', ' '));

        $before = $this->aggSnapshot($from, $to, $org);

        // ---- 4. Agregatni tozalash (trigger yonmagan holatni tiklash) -----------
        $deleted = 0;
        if (!$append) {
            try {
                $delQ = DB::connection($this->conn)->table('i_real_orgs')->whereBetween('dt', [$from, $to]);
                if ($org) $delQ->where('id_org', $org);
                $deleted = $dry ? (clone $delQ)->count() : $delQ->delete();
                $this->line('  i_real_orgs ' . ($dry ? 'o`chirilishi kerak' : 'o`chirildi') . ': ' . $deleted . ' qator');
            } catch (\Exception $e) {
                $this->error('i_real_orgs tozalanmadi: ' . $e->getMessage());
                Log::error('triggers:irl-detail — i_real_orgs tozalanmadi: ' . $e->getMessage());
                return 1;
            }
        }

        // ---- 5. Triggerni yoqib, har bir qator uchun yurgizish ------------------
        $restore = $this->enableTrigger($dry);

        $done = 0; $failed = 0; $errors = [];

        $this->line('');
        $this->output->progressStart($total);

        try {
            $src->orderBy('yy')->orderBy('dt')->orderBy('ballon_kod')->orderBy('abonent_kod')
                ->chunk($chunk, function ($rows) use (&$done, &$failed, &$errors) {
                    foreach ($rows as $row) {
                        try {
                            // AYNAN trigger yo'li: TriggerBus AFTER INSERT handlerlarini
                            // bazadagi tartibda chaqiradi (insert_i_real_details, keyin
                            // minus_deposit_orgs — ikkinchisi bayrog'i o'chiq, no-op).
                            TriggerBus::afterInsert('i_real_details', (array) $row, $this->conn);
                            $done++;
                        } catch (\Throwable $e) {
                            $failed++;
                            if (count($errors) < 10) {
                                $errors[] = $this->rowKey($row) . ' -> ' . $e->getMessage();
                            }
                            Log::error('triggers:irl-detail — qator xatosi ' . $this->rowKey($row) . ': ' . $e->getMessage());
                        }
                        $this->output->progressAdvance();
                    }
                });
        } catch (\Throwable $e) {
            $this->output->progressFinish();
            $restore();
            $this->line('');
            $this->error('To`xtatildi: ' . $e->getMessage());
            $this->warn('Bajarilgan: ' . $done . ' qator. i_real_orgs YARIM holatda — buyruqni o`sha davr uchun qayta ishga tushiring.');
            Log::error('triggers:irl-detail — uzildi: ' . $e->getMessage());
            return 1;
        }

        $this->output->progressFinish();
        $restore();

        // ---- 6. Natija ----------------------------------------------------------
        $after = $this->aggSnapshot($from, $to, $org);

        $this->line('');
        $this->table(
            ['Ko`rsatkich', 'i_real_details (manba)', 'i_real_orgs (oldin)', 'i_real_orgs (keyin)'],
            [
                ['qatorlar / total_qty', number_format($total, 0, '.', ' '), $this->n($before['qty']), $this->n($after['qty'])],
                ['summa / amount_total', $this->n($this->detailsSum($from, $to, $org), 2), $this->n($before['amount'], 2), $this->n($after['amount'], 2)],
                ['agregat qatorlar', '—', $this->n($before['rows']), $this->n($after['rows'])],
            ]
        );

        if ($failed) {
            $this->error('Xato bilan tugagan qatorlar: ' . $failed . ' / ' . $total);
            foreach ($errors as $e) $this->line('  - ' . $e);
            if ($failed >= 10) $this->line('  … qolganlari laravel.log da (triggers:irl-detail)');
        }

        if ($dry) {
            $this->line('');
            $this->warn('DRY RUN — bazaga hech narsa yozilmadi. Haqiqiy bajarish uchun --dry-run ni olib tashlang.');
            return $failed ? 1 : 0;
        }

        // Nazorat: agregat manbaga to'g'ri keldimi (faqat to'liq, tashkilot bo'yicha kesilmagan qayta qurishda)
        if (!$append && !$failed) {
            $srcQty = $total;
            $srcSum = round((float) $this->detailsSum($from, $to, $org), 2);
            $aggQty = (int) $after['qty'];
            $aggSum = round((float) $after['amount'], 2);

            if ($srcQty === $aggQty && abs($srcSum - $aggSum) < 0.01) {
                $this->info('Tayyor: ' . $this->n($done) . ' qator qayta hisoblandi, i_real_orgs manbaga to`liq mos.');
                return 0;
            }

            $this->warn('Tayyor: ' . $this->n($done) . ' qator qayta hisoblandi, LEKIN agregat manbaga to`liq mos emas:');
            $this->line('  qatorlar: ' . $this->n($srcQty) . ' vs ' . $this->n($aggQty)
                . '   summa: ' . $this->n($srcSum, 2) . ' vs ' . $this->n($aggSum, 2));
            $this->line('  Sabab bo`lishi mumkin: id_region topilmagan tashkilot (i_real_orgs.id_region NOT NULL),');
            $this->line('  yoki davr chetidagi qatorlar boshqa kunga tushgan. Log: DBTRG [i_real_details.insert_i_real_details]');
            return 1;
        }

        $this->info('Tayyor: ' . $this->n($done) . ' qator qayta hisoblandi.');
        return $failed ? 1 : 0;
    }

    // ---------------------------------------------------------------------
    // Yordamchilar
    // ---------------------------------------------------------------------

    /**
     * `--date` yoki `--from/--to` dan davrni oladi va tekshiradi.
     *
     * @return array|null  [from, to, org] yoki xato bo'lsa null
     */
    private function resolveScope()
    {
        $date = $this->option('date');
        $from = $this->option('from');
        $to   = $this->option('to');

        if ($date) $from = $to = $date;
        if (!$from && !$to) {
            $this->error('Davr ko`rsatilmagan: --date=Y-m-d yoki --from=Y-m-d --to=Y-m-d bering.');
            return null;
        }
        if (!$from) $from = $to;
        if (!$to)   $to   = $from;

        foreach (['--from' => $from, '--to' => $to] as $opt => $v) {
            if ($v !== date('Y-m-d', strtotime($v))) {
                $this->error('Sana formati noto`g`ri (' . $opt . '=' . $v . '), Y-m-d kutilgan.');
                return null;
            }
        }
        if ($from > $to) {
            $this->error('--from (' . $from . ') --to (' . $to . ') dan katta.');
            return null;
        }

        $org = $this->option('org');
        if ($org !== null && $org !== '' && !ctype_digit((string) $org)) {
            $this->error('--org butun son bo`lishi kerak.');
            return null;
        }

        return [$from, $to, $org ? (int) $org : null];
    }

    /** Manba qatorlar so'rovi */
    private function detailsQuery($from, $to, $org)
    {
        $q = DB::connection($this->conn)->table('i_real_details')->whereBetween('dt', [$from, $to]);
        if ($org) $q->where('id_org', $org);
        return $q;
    }

    private function detailsSum($from, $to, $org)
    {
        return $this->detailsQuery($from, $to, $org)->sum('amount');
    }

    /**
     * Agregatning joriy holati (nazorat uchun).
     *
     * DIALEKT: `IFNULL` faqat MySQL da bor — `COALESCE` ikkala dialektda ham
     * ishlaydi, shuning uchun shu ishlatiladi. Alias lar kichik harfda:
     * PG qo'shtirnoqsiz nomlarni kichik harfga keltiradi.
     */
    private function aggSnapshot($from, $to, $org)
    {
        try {
            $q = DB::connection($this->conn)->table('i_real_orgs')->whereBetween('dt', [$from, $to]);
            if ($org) $q->where('id_org', $org);
            $r = $q->selectRaw('COUNT(*) as rows_cnt, COALESCE(SUM(total_qty),0) as qty, COALESCE(SUM(amount_total),0) as amount')->first();

            return ['rows' => $r ? $r->rows_cnt : 0, 'qty' => $r ? $r->qty : 0, 'amount' => $r ? $r->amount : 0];
        } catch (\Exception $e) {
            Log::error('triggers:irl-detail — i_real_orgs holati o`qilmadi: ' . $e->getMessage());
            return ['rows' => 0, 'qty' => 0, 'amount' => 0];
        }
    }

    /**
     * Triggerni SHU ISH uchun vaqtincha yoqadi (config/.env ga tegmaydi) va
     * oldingi holatni qaytaruvchi closure beradi.
     *
     * Juftlik triggeri `minus_deposit_orgs` ataylab o'chiq qoldiriladi.
     *
     * ⚠ `php_connections` ham vaqtincha OLIB TURILADI (null). Aks holda buyruq
     *   MySQL ulanishida hech narsa qilmasdi: ro'yxatda faqat PostgreSQL
     *   ulanishlari turadi (u yerda DB trigger yo'q). Bu buyruq esa ataylab
     *   qo'lda yurgizish uchun — qaysi ulanish berilsa, o'shanda ishlashi kerak.
     *
     * @return \Closure
     */
    private function enableTrigger($dry)
    {
        $prevEnabled = config('db_triggers.enabled');
        $prevDry     = config('db_triggers.dry_run');
        $prevConns   = config('db_triggers.php_connections');

        config(['db_triggers.enabled' => true]);
        config(['db_triggers.php_connections' => null]);
        if ($dry) config(['db_triggers.dry_run' => true]);

        TriggerFlags::override(IRealDetailsTriggers::T_ORGS, true);
        TriggerFlags::override(IRealDetailsTriggers::T_DEPOSIT, false);

        return function () use ($prevEnabled, $prevDry, $prevConns) {
            TriggerFlags::forget(IRealDetailsTriggers::T_ORGS);
            TriggerFlags::forget(IRealDetailsTriggers::T_DEPOSIT);
            config([
                'db_triggers.enabled'         => $prevEnabled,
                'db_triggers.dry_run'         => $prevDry,
                'db_triggers.php_connections' => $prevConns,
            ]);
        };
    }

    /**
     * Trigger bazada hali turibdimi.
     *
     * DIALEKT: `DATABASE()` va katta harfli ustunlar — MySQL ga xos.
     * PostgreSQL da `current_schema()` va kichik harfli ustunlar.
     *
     * @return bool|null  null — information_schema o'qilmadi
     */
    private function dbTriggerExists($fullName)
    {
        $parts = explode('.', $fullName, 2);
        $short = isset($parts[1]) ? $parts[1] : $fullName;

        try {
            $c = DB::connection($this->conn);

            $sql = $c->getDriverName() === 'pgsql'
                ? 'SELECT trigger_name FROM information_schema.triggers '
                    . 'WHERE trigger_schema = current_schema() AND trigger_name = ? LIMIT 1'
                : 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
                    . 'WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? LIMIT 1';

            return !empty($c->select($sql, [$short]));
        } catch (\Exception $e) {
            return null;
        }
    }

    /** i_real_details da `id` yo'q — qatorni PK bo'yicha nomlaymiz */
    private function rowKey($row)
    {
        $r = (array) $row;
        return 'dt=' . (isset($r['dt']) ? $r['dt'] : '?')
            . ' ballon=' . (isset($r['ballon_kod']) ? $r['ballon_kod'] : '?')
            . ' abonent=' . (isset($r['abonent_kod']) ? $r['abonent_kod'] : '?');
    }

    private function n($v, $dec = 0)
    {
        return number_format((float) $v, $dec, '.', ' ');
    }
}
