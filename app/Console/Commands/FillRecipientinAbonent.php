<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `a_user_relations.recipientin_abonent` ustunini to'ldiradi.
 *
 * MUAMMO: a_user_relations da bir abonentning "qarindoshi" (pinfl) sifatida
 * turgan shaxs aslida BOSHQA abonentning ballonini olib ketayotgan bo'lishi
 * mumkin — ya'ni u haqiqatda o'sha boshqa abonentning odami.
 *
 * YECHIM: har bir a_user_relations qatori uchun i_face_id_detail dan SHU PINFL
 * bo'yicha muvaffaqiyatli (result = 1) yuz tasdiqlashlar olinadi va ulardan
 * ENG OXIRGISI (MAX(id)) ning id_abonent i qatorning o'z id_abonent idan
 * FARQ QILSA — o'sha id_abonent recipientin_abonent ga yoziladi.
 *
 * Ya'ni: eng oxirgi tasdiqlash qatorning o'z abonenti bo'lsa, undan oldingi
 * (id bo'yicha keyingi eng yangi) BOSHQA abonent olinadi. Mos nomzod
 * topilmasa ustun NULL bo'lib qoladi.
 *
 * REJIMLAR:
 *   php artisan fill:recipientin              — faqat recipientin_abonent NULL qatorlar
 *   php artisan fill:recipientin --refresh    — hamma qatorlar qaytadan hisoblanadi
 *                                               (eskirgan qiymat NULL ga tushishi mumkin)
 *
 * Misollar:
 *   php artisan fill:recipientin --dry-run                 # hech narsa yozmaydi
 *   php artisan fill:recipientin --id-abonent=123456
 *   php artisan fill:recipientin --pinfl=12345678901234
 *   php artisan fill:recipientin --refresh --limit=5000
 *   php artisan fill:recipientin --connection=pgsql        # to'g'ridan-to'g'ri PG nusxasida
 *
 * ⚙ DUAL WRITE: standart `mysql` ulanishida ishlaganda UPDATE lar so'rov
 *   quruvchisidan o'tadi, ya'ni AYNAN o'sha yangilanish `pgsql` nusxasiga ham
 *   tushadi (config/dual_write.php). `--connection=pgsql` esa faqat nusxada
 *   ishlaydi va MySQL ga tegmaydi — odatda kerak emas.
 *
 * Ishlash tartibi: a_user_relations id bo'yicha bo'laklab (--chunk) o'qiladi,
 * har bo'lakning pinfl lari uchun i_face_id_detail dan BITTA guruhlangan
 * so'rov bilan nomzodlar olinadi, yangilanish esa bir xil qiymatli id lar
 * bo'yicha guruhlab (whereIn) bajariladi.
 */
class FillRecipientinAbonent extends Command
{
    protected $signature = 'fill:recipientin
        {--connection=mysql : Ulanish (mysql = egaz_idxdb, pgsql = egaz_idxpost nusxasi)}
        {--refresh : NULL bo\'lmagan qatorlarni ham qaytadan hisoblaydi}
        {--id-abonent= : Faqat shu id_abonent qatorlari}
        {--pinfl= : Faqat shu pinfl qatorlari}
        {--limit=0 : Ko\'pi bilan shuncha qator ko\'riladi (0 = cheklovsiz)}
        {--chunk=1000 : Bir marta o\'qiladigan qatorlar soni}
        {--dry-run : Hech narsa yozmaydi, faqat hisoblab ko\'rsatadi}';

    protected $description = 'a_user_relations.recipientin_abonent ni i_face_id_detail dagi oxirgi BOSHQA abonent bilan to\'ldiradi';

    public function handle()
    {
        ini_set('memory_limit', '2G');

        $conn    = $this->option('connection');
        $refresh = (bool) $this->option('refresh');
        $isDry   = (bool) $this->option('dry-run');
        $limit   = (int) $this->option('limit');
        $chunk   = (int) $this->option('chunk');
        if ($chunk < 1) $chunk = 1000;

        $db = DB::connection($conn);

        if (!Schema::connection($conn)->hasColumn('a_user_relations', 'recipientin_abonent')) {
            $this->error("[$conn] a_user_relations da recipientin_abonent ustuni yo'q. Avval ustunni qo'shing:");
            $this->line('  ALTER TABLE a_user_relations ADD COLUMN recipientin_abonent INT NULL;');
            return 1;
        }

        $base = $db->table('a_user_relations')
            ->select('id', 'id_abonent', 'pinfl', 'recipientin_abonent')
            ->whereNotNull('pinfl')
            ->where('pinfl', '!=', '');

        if (!$refresh)                   $base->whereNull('recipientin_abonent');
        if ($this->option('id-abonent')) $base->where('id_abonent', (int) $this->option('id-abonent'));
        if ($this->option('pinfl'))      $base->where('pinfl', $this->option('pinfl'));

        $total = (clone $base)->count();
        if ($limit > 0 && $limit < $total) $total = $limit;

        $this->info("[$conn] Ko'riladigan qatorlar: $total"
            . ($refresh ? ' [refresh]' : ' [faqat NULL]')
            . ($isDry ? ' [dry-run]' : ''));

        if ($total == 0) {
            $this->info("Yangilanadigan qator yo'q.");
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $seen    = 0;   // ko'rilgan qatorlar
        $filled  = 0;   // qiymat yozilgan qatorlar
        $cleared = 0;   // refresh da NULL ga tushirilgan qatorlar
        $same    = 0;   // qiymati o'zgarmagan qatorlar
        $nocand  = 0;   // mos boshqa abonent topilmagan qatorlar
        $lastId  = 0;

        while (true) {
            $take = $chunk;
            if ($limit > 0 && ($limit - $seen) < $take) $take = $limit - $seen;
            if ($take < 1) break;

            $rows = (clone $base)->where('id', '>', $lastId)->orderBy('id')->limit($take)->get();
            if (!count($rows)) break;

            $pinfls = [];
            foreach ($rows as $r) {
                $pinfls[trim($r->pinfl)] = true;
                $lastId = $r->id;
            }

            $cand = $this->candidatesByPinfl($db, array_keys($pinfls));

            $updates = [];  // qiymat => yangilanadigan a_user_relations.id lar
            foreach ($rows as $r) {
                $seen++;
                $bar->advance();

                $target  = $this->pickOther($cand, trim($r->pinfl), (int) $r->id_abonent);
                $current = is_null($r->recipientin_abonent) ? null : (int) $r->recipientin_abonent;

                if (is_null($target)) $nocand++;

                if ($target === $current) {
                    $same++;
                    continue;
                }
                if (is_null($target) && !$refresh) {
                    continue;   // NULL rejimida yozadigan narsa yo'q
                }

                $key = is_null($target) ? 'null' : $target;
                $updates[$key][] = $r->id;

                if (is_null($target)) $cleared++; else $filled++;
            }

            if (!$isDry) {
                foreach ($updates as $value => $ids) {
                    $db->table('a_user_relations')
                        ->whereIn('id', $ids)
                        ->update(['recipientin_abonent' => $value === 'null' ? null : (int) $value]);
                }
            }

            if ($limit > 0 && $seen >= $limit) break;
        }

        $bar->finish();
        $this->output->newLine(2);

        $this->info('Natija' . ($isDry ? ' [dry-run — yozilmadi]' : '') . ':');
        $this->info("  Ko'rildi:            $seen");
        $this->info("  Yozildi (abonent):   $filled");
        $this->info("  NULL ga tushirildi:  $cleared");
        $this->info("  O'zgarmadi:          $same");
        $this->info("  Nomzod topilmadi:    $nocand");

        return 0;
    }

    /**
     * Berilgan pinfl lar uchun i_face_id_detail dan nomzodlarni oladi:
     * har (pinfl, id_abonent) juftligi uchun eng oxirgi (MAX(id)) yozuv.
     *
     * Qaytaradi: array( pinfl => array( array('abon' => int, 'last' => int), ... ) )
     * Ro'yxat "last" bo'yicha kamayish tartibida saralangan.
     */
    private function candidatesByPinfl($db, array $pinfls)
    {
        $map = [];
        if (empty($pinfls)) return $map;

        $rows = $db->table('i_face_id_detail')
            ->select('pinfl', 'id_abonent', DB::raw('MAX(id) as last_id'))
            ->whereIn('pinfl', $pinfls)
            ->whereNotNull('id_abonent')
            ->where('id_abonent', '>', 0)
            ->where('result', '1')          // faqat muvaffaqiyatli yuz tasdiqlash
            ->groupBy('pinfl', 'id_abonent')
            ->get();

        foreach ($rows as $row) {
            $map[trim($row->pinfl)][] = [
                'abon' => (int) $row->id_abonent,
                'last' => (int) $row->last_id,
            ];
        }

        foreach ($map as $pinfl => $list) {
            usort($list, function ($a, $b) {
                return $b['last'] - $a['last'];
            });
            $map[$pinfl] = $list;
        }

        return $map;
    }

    /**
     * Shu pinfl bo'yicha eng oxirgi — lekin $ownAbonent dan FARQLI — id_abonent.
     * Topilmasa null.
     */
    private function pickOther(array $cand, $pinfl, $ownAbonent)
    {
        if (!isset($cand[$pinfl])) return null;

        foreach ($cand[$pinfl] as $c) {
            if ($c['abon'] !== $ownAbonent) return $c['abon'];
        }

        return null;
    }
}
