<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * `i_real_ballons_mahallas` jadvalini to'ldiradi — MAHALLA kesimida
 * "necha kun oldin g/b olgan" bo'yicha abonentlar soni.
 *
 * `real:ballons` (egaz loyihasidagi i_real_ballons_orgs) ning shu loyihaga
 * ko'chirilgan, MAHALLA darajasidagi va MAYDAROQ oraliqli varianti:
 * 71 kundan keyin ham 5 kunlik qadam davom etadi (71to75 ... 96to100),
 * undan keyingisi 100tomore.
 *
 * MANBA:   brrgz.cms_users — `--source` ulanishi (standart `mysql1`, egaz-indexator
 *          dagidek). `--source=pgsql1` berilsa PostgreSQL nusxasidan o'qiydi.
 * NATIJA:  egaz_idxdb.i_real_ballons_mahallas (DEFAULT `mysql` ulanishi) —
 *          DUAL_WRITE=true bo'lsa dual write orqali `pgsql` nusxasiga ham.
 *
 * HISOB: d = <dt> dan u.rdt gacha bo'lgan kunlar soni.
 *        d 0..30 → 1to30, 31..35 → 31to35, ..., 96..100 → 96to100,
 *        d >= 101 → 100tomore. Kelajakdagi rdt (d < 0) hisobga OLINMAYDI.
 *
 * ABONENT FILTRI (i_real_ballons_orgs bilan bir xil):
 *   id_cms_privileges = 4, status = 'Active', tp <> 'GG', rdt IS NOT NULL.
 *
 * Har viloyat uchun BITTA guruhlangan SQL bajariladi (cms_users id_region
 * bo'yicha partition qilingan — shuning uchun viloyatma-viloyat tez ishlaydi),
 * natija esa paketlab (--chunk) INSERT qilinadi.
 *
 * Misollar:
 *   php artisan real:ballons-mah                    # bugungi sana, hamma viloyat
 *   php artisan real:ballons-mah --dt=2026-08-01
 *   php artisan real:ballons-mah --region=7
 *   php artisan real:ballons-mah --dry-run          # yozmaydi, faqat ko'rsatadi
 *   php artisan real:ballons-mah --source=pgsql1    # manba PostgreSQL nusxasi (egaz-push)
 *
 * Qayta yurgizilsa — o'sha `dt` (va tanlangan viloyat) qatorlari avval
 * O'CHIRILADI, keyin qaytadan yoziladi. DELETE ham, INSERT ham so'rov
 * quruvchisidan o'tadi, ya'ni DUAL_WRITE=true bo'lsa ikkalasi ham PostgreSQL
 * nusxasiga tushadi.
 *
 * Jadval QO'LDA yaratiladi (loyihada migration ishlatilmaydi):
 *   mysql -u <user> -p egaz_idxdb < database/sql/i_real_ballons_mahallas.sql
 *   psql  -U <user> -d egaz_idxpost -f database/sql/i_real_ballons_mahallas.pg.sql
 */
class RealBallonsMahalla extends Command
{
    protected $signature = 'real:ballons-mah
        {--dt= : Hisob sanasi (Y-m-d, default bugun)}
        {--region= : Faqat shu id_region}
        {--source=mysql1 : cms_users olinadigan ulanish (mysql1 = brrgz, egaz-indexator dagidek; pgsql1 = egaz-push nusxasi)}
        {--chunk=500 : Bir INSERT da yoziladigan qatorlar soni}
        {--dry-run : Hech narsa yozmaydi, faqat hisoblab ko\'rsatadi}';

    protected $description = 'Mahalla kesimida "necha kun oldin g/b olgan" taqsimotini i_real_ballons_mahallas ga yozadi';

    const TABLE = 'i_real_ballons_mahallas';

    /**
     * Ustun nomi => [dan, gacha] kunlar oralig'i. gacha = null → cheksiz.
     */
    private static array $BUCKETS = [
        '1to30'     => [0, 30],
        '31to35'    => [31, 35],
        '36to40'    => [36, 40],
        '41to45'    => [41, 45],
        '46to50'    => [46, 50],
        '51to55'    => [51, 55],
        '56to60'    => [56, 60],
        '61to65'    => [61, 65],
        '66to70'    => [66, 70],
        '71to75'    => [71, 75],
        '76to80'    => [76, 80],
        '81to85'    => [81, 85],
        '86to90'    => [86, 90],
        '91to95'    => [91, 95],
        '96to100'   => [96, 100],
        '100tomore' => [101, null],
    ];

    public function handle()
    {
        ini_set('memory_limit', '2G');

        $started = microtime(true);

        $dt     = $this->option('dt') ?: date('Y-m-d');
        $source = $this->option('source');
        $isDry  = (bool) $this->option('dry-run');
        $chunk  = (int) $this->option('chunk');
        if ($chunk < 1) $chunk = 500;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
            $this->error("--dt noto'g'ri formatda: $dt (kutilgani Y-m-d)");
            return 1;
        }

        if (!Schema::hasTable(self::TABLE)) {
            $this->error('egaz_idxdb da ' . self::TABLE . " jadvali yo'q. Avval yarating:");
            $this->line('  mysql -u <user> -p egaz_idxdb < database/sql/' . self::TABLE . '.sql');
            return 1;
        }

        $regions = $this->regions();
        if (empty($regions)) {
            $this->error("Viloyat topilmadi (--region noto'g'rimi?)");
            return 1;
        }

        $this->info('Hisob sanasi: ' . $dt . ' | manba: ' . $source
            . ' | viloyatlar: ' . implode(',', $regions)
            . ($isDry ? ' [dry-run]' : ''));

        $allRows  = 0;
        $allAbons = 0;

        foreach ($regions as $region) {
            $t0 = microtime(true);

            $rows = DB::connection($source)->select($this->sql($dt, $region, $source));

            $data     = [];
            $regAbons = 0;
            foreach ($rows as $row) {
                $item = [
                    'id_region'  => (int) $row->id_region,
                    'id_org'     => (int) $row->id_org,
                    'id_mahalla' => is_null($row->id_mahalla) ? null : (int) $row->id_mahalla,
                    'dt'         => $dt,
                ];
                foreach (self::$BUCKETS as $col => $range) {
                    $item[$col] = (int) $row->$col;
                    $regAbons += (int) $row->$col;
                }
                $data[] = $item;
            }

            if (!$isDry) {
                DB::transaction(function () use ($region, $dt, $data, $chunk) {
                    DB::table(self::TABLE)
                        ->where('id_region', $region)
                        ->where('dt', $dt)
                        ->delete();

                    foreach (array_chunk($data, $chunk) as $part) {
                        DB::table(self::TABLE)->insert($part);
                    }
                });
            }

            $allRows  += count($data);
            $allAbons += $regAbons;

            $this->info(sprintf('  Region %-3s qator: %-6s abonent: %-8s (%s sek)',
                $region, count($data), $regAbons, number_format(microtime(true) - $t0, 2)));
        }

        $this->info('Tayyor' . ($isDry ? ' [dry-run — yozilmadi]' : '') . '. Jami qator: ' . $allRows
            . ' | jami abonent: ' . $allAbons
            . ' | vaqt: ' . number_format(microtime(true) - $started, 2) . ' sek');

        Log::info('RealBallonsMahalla: dt=' . $dt . ' rows=' . $allRows . ' abonents=' . $allAbons);

        return 0;
    }

    /**
     * Qayta ishlanadigan viloyatlar ro'yxati.
     * 15-viloyat (respublika bo'yicha yig'ma yozuvlar) hisobga olinmaydi —
     * abon:info dagi kabi.
     */
    private function regions()
    {
        if ($this->option('region')) {
            return [(int) $this->option('region')];
        }

        $ids = [];
        foreach (DB::table('regions')->where('id', '!=', 15)->orderBy('id')->get() as $r) {
            $ids[] = (int) $r->id;
        }

        return $ids;
    }

    /**
     * Bitta viloyat uchun guruhlangan SQL.
     *
     * $dt allaqachon Y-m-d formatiga tekshirilgan, $region — int; shuning uchun
     * ular to'g'ridan-to'g'ri qo'yiladi (kun farqi har bir ustunda takrorlangani
     * uchun binding tartibi chalkash bo'lardi).
     *
     * DIALEKT: manba ulanish MySQL ham, PostgreSQL ham bo'lishi mumkin
     * (--source). Kun farqi va alias qavslash ikki dialektda har xil:
     *   MySQL : datediff('2026-08-26', u.rdt)   alias `1to30`
     *   PG    : (DATE '2026-08-26' - u.rdt)     alias "1to30"
     * Alias raqam bilan boshlangani uchun ikkalasida ham qavslash SHART.
     */
    private function sql($dt, $region, $source)
    {
        $region = (int) $region;
        $isPg   = $this->isPg($source);

        $diff = $isPg
            ? "(DATE '" . $dt . "' - u.rdt)"
            : "datediff('" . $dt . "', u.rdt)";

        $q = $isPg ? '"' : '`';

        $select = ['u.id_region', 'u.id_org', 'u.id_mahalla'];
        foreach (self::$BUCKETS as $col => $range) {
            if (is_null($range[1])) {
                $cond = $diff . ' >= ' . $range[0];
            } else {
                $cond = $diff . ' between ' . $range[0] . ' and ' . $range[1];
            }
            $select[] = 'sum(case when ' . $cond . ' then 1 else 0 end) as ' . $q . $col . $q;
        }

        return 'select ' . implode(', ', $select) . '
            from cms_users as u
            where u.id_region = ' . $region . '
              and u.id_cms_privileges = 4
              and u.status = \'Active\'
              and u.tp <> \'GG\'
              and u.rdt is not null
            group by u.id_region, u.id_org, u.id_mahalla';
    }

    /** Manba ulanish PostgreSQL'mi */
    private function isPg($connection)
    {
        try {
            return DB::connection($connection)->getDriverName() === 'pgsql';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
