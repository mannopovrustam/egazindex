<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MySQL (egaz-indexator) jadvalidagi qaydlarni PostgreSQL ga ko'chiradi.
 *
 * egaz loyihasidagi `pg:sync` ning shu loyihaga moslashtirilgan ko'rinishi.
 * Manba ulanishi --connection orqali tanlanadi (indexator'da bir nechta MySQL
 * ulanishi bor: mysql = egaz_idxdb, mysql1 = egaz asosiy brrgz, mysql_egaz).
 *
 * Qabul qiluvchi PostgreSQL ulanishi --target orqali: `pgsql` (STANDART —
 * ilovaning o'z bazasi, i_* / idx_* agregatlari shu yerda o'qiladi) yoki
 * `pgsql1` (egaz-push asosiy bazasi).
 *
 * REJIM O'ZI TANLANADI (jadval nomi berilganda):
 *
 *     Manbada `id` (--key) ustuni BOR      →  A) increment
 *     `id` YO'Q, lekin sana ustuni bor     →  D) sanadan davom ettirish
 *     ikkalasi ham yo'q                    →  --full talab qilinadi
 *
 * TO'RT REJIM BOR:
 *
 * A) Jadval nomi BERILGAN — `php artisan pg:sync <jadval>`  (qayta yozish)
 *   0. Jadval PostgreSQL da bo'lmasa — MySQL strukturasidan DDL yasalib yaratiladi
 *   1. Boshlang'ich ID aniqlanadi:
 *        --id berilgan   → o'sha qiymat
 *        --id berilmagan → PostgreSQL dagi MAX(id); jadval bo'sh bo'lsa hammasi
 *   2. PostgreSQL dan `id >= boshlang'ich` qaydlar O'CHIRILADI
 *   3. MySQL dan `id >= boshlang'ich` qaydlar o'qilib ANIQ ID bilan yoziladi
 *   4. Ketma-ketlik (sequence) MAX(id) ga suriladi — keyingi INSERT to'qnashmaydi
 *
 *   2-3-4 bosqichlar BITTA PostgreSQL tranzaksiyasida: xato bo'lsa hech narsa
 *   o'zgarmaydi (o'chirilgan qaydlar ham qaytadi).
 *
 * B) Jadval nomi + --full — `php artisan pg:sync <jadval> --full`  (to'liq almashtirish)
 *   PostgreSQL dagi jadval TO'LIQ bo'shatiladi va MySQL dan hamma qayd yoziladi.
 *   O'suvchi (auto_increment) kalit TALAB QILINMAYDI — indexator'ning agregat
 *   jadvallari (i_money_*, i_real_*, idx_*) kalitsiz yoki qo'shma kalitli
 *   bo'lgani uchun shu rejim ular uchun mo'ljallangan. Bitta tranzaksiyada.
 *
 * D) Jadval nomi BERILGAN, lekin `id` YO'Q — sanadan davom ettirish
 *   `i_real_details`, `i_money_details` kabi jadvallarda o'suvchi kalit yo'q
 *   (kalit qo'shma: yy+dt+ballon_kod+abonent_kod), hajmi esa o'nlab GB —
 *   ya'ni na "id > MAX(id)", na `--full` yaraydi. Ular sana bo'yicha ko'chadi:
 *
 *   1. Sana ustuni topiladi (--date-column, yoki dt / real_date / … avtomatik)
 *   2. PostgreSQL dagi MAX(sana) olinadi
 *        --date berilgan → o'sha sana
 *        --days=N        → MAX(sana) dan N kun orqaga chekinadi
 *   3. PostgreSQL dan o'sha SANA VA UNDAN KEYINGI qaydlar O'CHIRILADI
 *   4. MySQL dan aynan o'sha oyna qaytadan yoziladi
 *
 *   NEGA oxirgi sana ham o'chiriladi: sinxronizatsiya kun o'rtasida ishlagan
 *   bo'lsa PG dagi eng katta sana CHALA bo'lishi mumkin. Uni butunlay
 *   almashtirish yagona ishonchli yo'l. Amal IDEMPOTENT — necha marta
 *   yurgizsangiz ham natija bir xil. Hammasi bitta tranzaksiyada.
 *
 *   ⚠ HIMOYA: manbada bu oynada qator bo'lmasa hech narsa o'chirilmaydi
 *     (aks holda PG dagi oxirgi kun yo'q bo'lib ketardi).
 *
 * C) Jadval nomi BERILMAGAN — `php artisan pg:sync`  (ro'yxat bo'yicha)
 *   1. $SYNC_TABLES — o'suvchi kalitli jadvallar, FAQAT QO'SHISH:
 *        PostgreSQL dagi MAX(kalit) topiladi, MySQL dan faqat undan KATTA
 *        kalitli qaydlar qo'shiladi. Hech narsa o'chirilmaydi.
 *   2. $DATE_TABLES — kalitsiz, sanali KATTA jadvallar: PostgreSQL dagi
 *        MAX(sana) dan davom etadi (D rejim bilan bir xil).
 *   3. $FULL_TABLES — kichik, kalitsiz agregatlar: HAR SAFAR TO'LIQ qayta
 *        yoziladi (B rejim bilan bir xil).
 *   PostgreSQL da jadval bo'lmasa avval yaratiladi. Har bir jadval o'z
 *   tranzaksiyasida — biri yiqilsa qolganlari davom etaveradi.
 *
 * Misollar:
 *   php artisan pg:sync                                  # uchala ro'yxat bo'yicha
 *   php artisan pg:sync i_balance                        # id bor → increment
 *   php artisan pg:sync i_balance --id=1000 --force
 *   php artisan pg:sync i_real_details                   # id yo'q → dt dan davom etadi
 *   php artisan pg:sync i_money_details --days=3         # oxirgi 3 kunni qayta yozadi
 *   php artisan pg:sync i_real_details --date=2019-01-01 --date-to=2019-12-31   # backfill
 *   php artisan pg:sync i_hour_realize_detail --date-column=real_date
 *   php artisan pg:sync i_rekvizits --full               # kichik agregatni to'liq
 *   php artisan pg:sync tb_scales_logs --connection=mysql1
 *   php artisan pg:sync i_balance --target=pgsql1        # egaz-push bazasiga
 *   php artisan pg:sync --dry-run                        # faqat rejani ko'rsatadi
 *
 * DIQQAT: egaz-indexator PHP 7.0 / Laravel 5.5 da ishlaydi — bu faylda 7.1+
 * sintaksisi (nullable type hint, void, arrow function, str_contains)
 * ISHLATILMAYDI.
 */
class PgSyncTable extends Command
{
    protected $signature = 'pg:sync
        {table? : Ko\'chiriladigan jadval nomi (berilmasa — ro\'yxatdagi jadvallar)}
        {--connection=mysql : Manba MySQL ulanishi (mysql = egaz_idxdb, mysql1 = egaz asosiy brrgz)}
        {--target=pgsql : Qabul qiluvchi PostgreSQL ulanishi (pgsql = ilovaning o\'z bazasi, STANDART; pgsql1 = egaz-push)}
        {--full : Jadvalni PostgreSQL da to\'liq bo\'shatib qaytadan yozadi; o\'suvchi kalit talab qilinmaydi}
        {--id= : Shu ID dan boshlab ko\'chiriladi (berilmasa PostgreSQL dagi MAX(id)); ro\'yxat rejimida ishlamaydi}
        {--key=id : O\'suvchi kalit ustuni. Shu ustun MANBADA bo\'lsa — increment rejimi}
        {--date-column= : Sana ustuni. Berilmasa avtomatik topiladi (dt, real_date, ...). Kalit yo\'q jadvallar shu ustun bo\'yicha ko\'chadi}
        {--date= : Shu sanadan boshlab (berilmasa PostgreSQL dagi MAX(sana))}
        {--date-to= : Shu sanagacha (backfill ni yil-yil bo\'lish uchun)}
        {--days=0 : PostgreSQL dagi MAX(sana) dan shuncha kun ORQAGA chekinib boshlanadi}
        {--chunk=500 : Bir marta o\'qiladigan qatorlar soni}
        {--no-create : Jadval PostgreSQL da bo\'lmasa yaratmaydi, xato beradi}
        {--no-index : Jadval yaratilganda indekslar ko\'chirilmaydi}
        {--dry-run : Hech narsa o\'zgartirmaydi, faqat rejani (va DDL ni) ko\'rsatadi}
        {--force : Tasdiqlash so\'ramaydi}';

    protected $description = 'MySQL (egaz-indexator) jadvalini PostgreSQL ga ko\'chiradi; jadval nomi berilmasa ro\'yxatdagi jadvallar sinxronlanadi';

    /**
     * Jadval nomi berilmaganda O'SUVCHI KALIT bo'yicha qo'shiladigan jadvallar.
     * Har biri uchun PostgreSQL dagi MAX(kalit) dan KATTA qaydlar qo'shiladi,
     * mavjud qaydlarga tegilmaydi.
     *
     * Yozilishi:
     *     'i_balance'          — kalit sifatida --key (odatda "id") ishlatiladi
     *     'tabiiy_users:row_id' — shu jadval uchun boshqa kalit ustuni
     */
    private static $SYNC_TABLES = array(
        // ── indexator agregatlari (o'suvchi id li) ──
        'i_balance',
        'i_face_id_detail',
        'i_face_id_recipients',
        'i_face_id_relations',
        // ── intake jadvallar ──
        'tb_factory_integration',
        'tb_gas_dispensers',
        'tb_social_sphere',
        // DIQQAT: log/sensor jadvallar (tb_scales_logs, tb_gnp_camera_logs,
        // tb_realize_location, tb_levelmeters, integration_logs) ataylab
        // qo'shilmagan — arxitektura qaroriga ko'ra ular ClickHouse'da qoladi.
        // PostgreSQL ga ham kerak bo'lsa shu ro'yxatga qo'shing.
        // ── ma'lumotnomalar ──
        'regions',
        'districts',
        'mahallas',
        'orgtypes',
        'organizations',
        'organ_old',
        'citizens',
        'tb_regional_accounts',
        'tb_rezervuars',
        'forecast_mahallas',
        'a_user_relations',
        'tabiiy_users:row_id',
    );

    /**
     * Jadval nomi berilmaganda HAR SAFAR TO'LIQ qayta yoziladigan jadvallar.
     * Bular kalitsiz yoki qo'shma kalitli agregatlar — o'suvchi ID yo'q, shuning
     * uchun "faqat yangilarini qo'shish" mumkin emas: PostgreSQL dagi nusxa
     * bo'shatilib, MySQL dagi holat aynan takrorlanadi.
     *
     * DIQQAT: bu jadvallar katta bo'lsa har sinxronizatsiya qimmatga tushadi —
     * ro'yxatga faqat haqiqatan kerakligini qo'shing.
     */
    private static $FULL_TABLES = array(
        'i_abonent_orgs',
        'i_deposit_orgs',
        'i_hour_realize',
        'i_rekvizits',
    );

    /**
     * Jadval nomi berilmaganda SANA bo'yicha davom ettiriladigan jadvallar.
     *
     * Bular o'suvchi kalitsiz, LEKIN sanali (dt) katta jadvallar: ularni har
     * safar to'liq qayta yozish qimmat, "id > MAX(id)" esa mumkin emas.
     * Shuning uchun PostgreSQL dagi MAX(sana) topiladi, o'sha SANA butunlay
     * o'chiriladi va MySQL dan o'sha sanadan boshlab qaytadan yoziladi.
     *
     * NEGA oxirgi sana ham o'chiriladi: sinxronizatsiya kun o'rtasida ishlagan
     * bo'lsa, PG dagi eng katta sana CHALA bo'lishi mumkin. Uni butunlay
     * almashtirish yagona ishonchli yo'l.
     *
     * Yozilishi:
     *     'i_real_details'           — sana ustuni avtomatik topiladi
     *     'i_hour_realize_detail:real_date' — ustun nomi aniq berilgan
     */
    private static $DATE_TABLES = array(
        'i_real_details',
        'i_money_details',
        'i_real_orgs',
        'i_money_orgs',
        'i_hour_realize_detail:real_date',
    );

    /**
     * Sana ustuni avtomatik topilganda ko'riladigan nomlar — shu tartibda.
     * Birinchi mos kelgani (va sana/vaqt tipida bo'lgani) olinadi.
     */
    private static $DATE_COLUMN_CANDIDATES = array(
        'dt', 'real_date', 'real_at', 'paid_at', 'created_dt','dt_creation',
        'event_date', 'created_at', 'dtm', 'ts', 'vaqt',
    );

    /** PostgreSQL bitta so'rovda qabul qiladigan bog'lanish (placeholder) chegarasi. */
    const PG_MAX_BIND_PARAMS = 65535;

    /** PostgreSQL identifikator uzunligi chegarasi. */
    const PG_MAX_IDENT = 63;

    /** Manba MySQL ulanishi nomi (--connection). */
    private $srcName = 'mysql';

    /**
     * Qabul qiluvchi PostgreSQL ulanishi nomi (--target).
     *
     * `pgsql`  — STANDART: ilovaning o'z bazasi. Ilova PG ga o'tgandan keyin
     * i_* / idx_* agregatlarini AYNAN shu yerdan o'qiydi, shuning uchun
     * ko'chirish ham shu yerga tushishi kerak.
     * `pgsql1` — egaz-push asosiy bazasi (cms_users, tb_gas_debit, ...).
     */
    private $dstName = 'pgsql';

    public function handle()
    {
        $this->srcName = trim($this->option('connection'));
        $this->dstName = trim($this->option('target'));

        if (config('database.connections.' . $this->srcName) === null) {
            $this->error('"' . $this->srcName . '" ulanishi config/database.php da yo\'q.');
            return 1;
        }
        if (config('database.connections.' . $this->dstName) === null) {
            $this->error('"' . $this->dstName . '" ulanishi config/database.php da yo\'q.');
            return 1;
        }

        $arg = $this->argument('table');

        if ($arg !== null && trim($arg) !== '') {
            return $this->syncOne(trim($arg));
        }

        if ($this->option('full')) {
            $this->warn('--full faqat jadval nomi berilganda ishlaydi; ro\'yxat rejimida '
                . '$FULL_TABLES dagi jadvallar allaqachon to\'liq qayta yoziladi.');
        }

        return $this->syncList();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  A / B rejim — bitta jadval
    // ═══════════════════════════════════════════════════════════════════

    private function syncOne($table)
    {
        $key   = trim($this->option('key'));
        $isDry = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $full  = (bool) $this->option('full');

        $src = DB::connection($this->srcName);
        $dst = DB::connection($this->dstName);

        if (! $this->validIdent($table)) {
            $this->error('Jadval nomi noto\'g\'ri: ' . $table);
            return 1;
        }
        if (! $full && ! $this->validIdent($key)) {
            $this->error('Kalit ustun nomi noto\'g\'ri: ' . $key);
            return 1;
        }
        if ($full && $this->option('id') !== null && $this->option('id') !== '') {
            $this->warn('--full berilganda --id ishlatilmaydi — jadval to\'liq qayta yoziladi.');
        }

        $myStruct = $this->mysqlStructure($src, $table);
        if (empty($myStruct['columns'])) {
            $this->error($this->srcName . ' da "' . $table . '" jadvali topilmadi.');
            return 1;
        }

        // ── 0. Jadval PostgreSQL da bormi; bo'lmasa yaratamiz ─────────────
        $pgTypes = $this->pgColumnTypes($dst, $table);

        if (empty($pgTypes)) {
            if ($this->option('no-create')) {
                $this->error('PostgreSQL da "' . $table . '" jadvali yo\'q (--no-create berilgan).');
                return 1;
            }

            $built = $this->buildCreateSql($table, $myStruct, ! $this->option('no-index'));
            $ddl   = $built['sql'];

            $this->line('');
            $this->warn('PostgreSQL da "' . $table . '" jadvali YO\'Q — MySQL strukturasidan yaratiladi:');
            $this->line('');
            foreach ($ddl as $sql) {
                $this->line($sql . ';');
            }
            $this->line('');

            if (! empty($built['notes'])) {
                foreach ($built['notes'] as $n) {
                    $this->warn('  ! ' . $n);
                }
                $this->line('');
            }

            if ($isDry) {
                $this->comment('--dry-run: jadval yaratilmadi, ko\'chirish ham bajarilmadi.');
                return 0;
            }
            if (! $this->option('force') && ! $this->confirm('Shu struktura bilan yaratamizmi?', false)) {
                $this->comment('Bekor qilindi.');
                return 0;
            }

            $err = $this->runDdl($dst, $ddl);
            if ($err !== null) {
                $this->error('Jadval yaratilmadi: ' . $err);
                return 1;
            }

            $this->info('Jadval yaratildi.');
            $pgTypes = $this->pgColumnTypes($dst, $table);
        }

        // ── Ustunlarni moslash ────────────────────────────────────────────
        $myCols = array_keys($myStruct['columns']);
        $cols   = array_values(array_intersect($myCols, array_keys($pgTypes)));

        if (empty($cols)) {
            $this->error('Ikkala bazada ham mos keladigan ustun topilmadi.');
            return 1;
        }
        // ── Rejimni aniqlash ──────────────────────────────────────────────
        // O'suvchi kalit MANBADA (va nusxada) bor  → increment
        // Kalit yo'q, lekin sana ustuni bor        → sana bo'yicha davom ettirish
        // Ikkalasi ham yo'q                        → --full talab qilinadi
        $dateCol = null;
        if (! $full) {
            $hasKey = in_array($key, $cols, true);

            if (! $hasKey) {
                $dateCol = $this->resolveDateColumn($myStruct, $cols);

                if ($dateCol === null) {
                    $this->error('"' . $key . '" ustuni ikkala bazada ham mavjud emas, '
                        . 'sana ustuni ham topilmadi.');
                    $this->line('  Sana ustunini o\'zingiz ko\'rsating:  --date-column=<ustun>');
                    $this->line('  Yoki jadvalni to\'liq almashtiring:   --full');
                    return 1;
                }
            } elseif ($this->option('date-column') !== null && $this->option('date-column') !== '') {
                // Kalit bor, lekin foydalanuvchi ataylab sana rejimini so'ragan.
                $dateCol = $this->resolveDateColumn($myStruct, $cols);
                if ($dateCol === null) {
                    return 1;
                }
            }
        }

        $onlyMy = array_values(array_diff($myCols, array_keys($pgTypes)));
        $onlyPg = array_values(array_diff(array_keys($pgTypes), $myCols));
        if ($onlyMy) {
            $this->warn('Faqat ' . $this->srcName . ' da bor (ko\'chirilmaydi): ' . implode(', ', $onlyMy));
        }
        if ($onlyPg) {
            $this->warn('Faqat PostgreSQL da bor (tegilmaydi, default/NULL qoladi): ' . implode(', ', $onlyPg));
        }

        // NOT NULL ustunlar — konvertatsiya NULL bergan joyda xato chiqmasligi uchun
        $notNull = $this->pgNotNullColumns($dst, $table);

        if ($full) {
            return $this->runFullOne($src, $dst, $table, $myStruct, $cols, $pgTypes, $notNull, $chunk, $isDry);
        }
        if ($dateCol !== null) {
            return $this->runDateOne($src, $dst, $table, $dateCol, $myStruct, $cols, $pgTypes, $notNull, $chunk, $isDry);
        }

        return $this->runKeyedOne($src, $dst, $table, $key, $cols, $pgTypes, $notNull, $chunk, $isDry);
    }

    /**
     * Ishlatiladigan sana ustunini aniqlaydi.
     *
     * --date-column berilgan bo'lsa — o'sha (ikkala bazada bor-yo'qligi
     * tekshiriladi). Berilmagan bo'lsa $DATE_COLUMN_CANDIDATES bo'yicha
     * birinchi mos keladigan SANA/VAQT tipidagi ustun olinadi.
     *
     * @return string|null  topilmasa null
     */
    private function resolveDateColumn(array $myStruct, array $cols)
    {
        $given = $this->option('date-column');

        if ($given !== null && trim($given) !== '') {
            $given = trim($given);

            if (! $this->validIdent($given)) {
                $this->error('Sana ustuni nomi noto\'g\'ri: ' . $given);
                return null;
            }
            if (! in_array($given, $cols, true)) {
                $this->error('"' . $given . '" ustuni ikkala bazada ham mavjud emas.');
                return null;
            }
            return $given;
        }

        return $this->resolveDateColumnQuiet($myStruct, $cols);
    }

    /**
     * Sana ustunini FAQAT $DATE_COLUMN_CANDIDATES bo'yicha topadi — hech narsa
     * chop etmaydi va --date-column ni hisobga olmaydi.
     *
     * Ro'yxat rejimida shu ishlatiladi: --date-column bir jadval uchun
     * beriladi, uni hamma jadvalga qo'llash noto'g'ri bo'lardi.
     *
     * @return string|null
     */
    private function resolveDateColumnQuiet(array $myStruct, array $cols)
    {
        foreach (self::$DATE_COLUMN_CANDIDATES as $cand) {
            if (! in_array($cand, $cols, true)) {
                continue;
            }
            if (! isset($myStruct['columns'][$cand])) {
                continue;
            }

            $type = strtolower($myStruct['columns'][$cand]['DATA_TYPE']);
            if (in_array($type, array('date', 'datetime', 'timestamp'), true)) {
                return $cand;
            }
        }

        return null;
    }

    /** A rejim — boshlang'ich kalitdan qayta yozish. */
    private function runKeyedOne($src, $dst, $table, $key, array $cols, array $pgTypes, array $notNull, $chunk, $isDry)
    {
        $keyQ   = $this->q($key);
        $tableQ = $this->q($table);

        if ($this->option('id') !== null && $this->option('id') !== '') {
            $startId  = $this->option('id');
            $idSource = 'buyruq qatoridan';
        } else {
            $row = $dst->selectOne('SELECT MAX(' . $keyQ . ') AS m FROM ' . $tableQ);
            if ($row === null || $row->m === null) {
                $startId  = null;
                $idSource = 'PostgreSQL bo\'sh — HAMMA qayd ko\'chiriladi';
            } else {
                $startId  = $row->m;
                $idSource = 'PostgreSQL dagi MAX(' . $key . ')';
            }
        }

        $srcCount = $this->countFrom($src, $table, $key, $startId);
        $dstCount = $this->countFrom($dst, $table, $key, $startId);

        $this->line('');
        $this->info('Manba         : ' . $this->srcName);
        $this->info('Jadval        : ' . $table);
        $this->info('Kalit         : ' . $key);
        $this->info('Boshlang\'ich  : ' . ($startId === null ? '(hammasi)' : $startId) . '  [' . $idSource . ']');
        $this->info('Ustunlar      : ' . count($cols) . ' ta');
        $this->info('MySQL dan     : ' . $srcCount . ' ta qayd o\'qiladi');
        $this->info('PostgreSQL da : ' . $dstCount . ' ta qayd O\'CHIRILADI');
        $this->line('');

        if ($isDry) {
            $this->comment('--dry-run: hech narsa o\'zgartirilmadi.');
            return 0;
        }
        if (! $this->option('force') && ! $this->confirm('Davom etamizmi?', false)) {
            $this->comment('Bekor qilindi.');
            return 0;
        }

        $rowsPerInsert = $this->rowsPerInsert($chunk, count($cols));
        $written = 0;
        $deleted = 0;
        $seqInfo = '-';

        $dst->beginTransaction();
        try {
            if ($startId === null) {
                $deleted = $dst->table($table)->delete();
            } else {
                $deleted = $dst->table($table)->where($key, '>=', $startId)->delete();
            }

            $bar    = $this->output->createProgressBar($srcCount);
            $lastId = null;

            while (true) {
                $q = $src->table($table)->select($cols)->orderBy($key)->limit($chunk);
                if ($lastId === null) {
                    if ($startId !== null) {
                        $q->where($key, '>=', $startId);
                    }
                } else {
                    $q->where($key, '>', $lastId);
                }

                $rows = $q->get();
                if (count($rows) === 0) {
                    break;
                }

                $batch = array();
                foreach ($rows as $row) {
                    $arr     = (array) $row;
                    $lastId  = $arr[$key];
                    $batch[] = $this->convertRow($arr, $pgTypes, $notNull);

                    if (count($batch) >= $rowsPerInsert) {
                        $dst->table($table)->insert($batch);
                        $written += count($batch);
                        $bar->advance(count($batch));
                        $batch = array();
                    }
                }

                if ($batch) {
                    $dst->table($table)->insert($batch);
                    $written += count($batch);
                    $bar->advance(count($batch));
                }
            }

            $bar->finish();
            $this->line('');

            $seqInfo = $this->resetSequence($dst, $table, $key);
            $dst->commit();
        } catch (\Exception $e) {
            $dst->rollBack();
            $this->line('');
            $this->error('XATO — hech narsa o\'zgarmadi (rollback): ' . $e->getMessage());
            return 1;
        }

        $this->line('');
        $this->info('O\'chirildi : ' . $deleted . ' ta');
        $this->info('Yozildi    : ' . $written . ' ta');
        $this->info('Sequence   : ' . $seqInfo);

        return 0;
    }

    /**
     * D rejim — SANA bo'yicha davom ettirish (o'suvchi kalit yo'q jadvallar).
     *
     * 1. PostgreSQL dagi MAX(sana) topiladi.
     * 2. O'sha sana (va --days berilgan bo'lsa undan oldingi kunlar ham)
     *    PostgreSQL dan BUTUNLAY o'chiriladi — u chala bo'lishi mumkin.
     * 3. MySQL dan o'sha sanadan boshlab qaytadan yoziladi.
     *
     * Hammasi bitta tranzaksiyada: xato bo'lsa o'chirilgan qatorlar qaytadi.
     */
    private function runDateOne($src, $dst, $table, $dateCol, array $myStruct, array $cols, array $pgTypes, array $notNull, $chunk, $isDry)
    {
        $bounds = $this->dateBounds($dst, $table, $dateCol, $myStruct);
        if ($bounds === null) {
            return 1;
        }

        $srcCount = (int) $this->dateScope($src->table($table), $dateCol, $bounds)->count();
        $dstCount = (int) $this->dateScope($dst->table($table), $dateCol, $bounds)->count();

        $order = $this->orderColumns($myStruct, $cols);

        $this->line('');
        $this->info('Manba         : ' . $this->srcName);
        $this->info('Jadval        : ' . $table . '   (sana bo\'yicha davom ettirish)');
        $this->info('Sana ustuni   : ' . $dateCol
            . ($this->option('date-column') ? '  [buyruq qatoridan]' : '  [avtomatik topildi]'));
        $this->info('Boshlanish    : ' . ($bounds['from'] === null ? '(hammasi)' : $bounds['from'])
            . '  [' . $bounds['source'] . ']');
        if ($bounds['to'] !== null) {
            $this->info('Tugash        : ' . $bounds['to']);
        }
        $this->info('Tartib        : ' . (empty($order) ? '(kalit yo\'q → ' . $dateCol . ')' : implode(', ', $order)));
        $this->info('Ustunlar      : ' . count($cols) . ' ta');
        $this->info('MySQL dan     : ' . $srcCount . ' ta qayd o\'qiladi');
        $this->info('PostgreSQL da : ' . $dstCount . ' ta qayd O\'CHIRILADI');

        if ($bounds['from'] === null) {
            $this->warn('PostgreSQL da bu jadval BO\'SH — hamma qayd ko\'chiriladi.');
            $this->line('  Katta jadvalda buni yil-yil bo\'lib qiling:');
            $this->line('      php artisan pg:sync ' . $table . ' --date=2019-01-01 --date-to=2019-12-31 --force');
        }

        if ($srcCount === 0) {
            $this->line('');
            if ($dstCount === 0) {
                $this->info('Bu oynada ikkala bazada ham qator yo\'q — qiladigan ish yo\'q.');
                return 0;
            }
            $this->error('TO\'XTATILDI: manbada bu oynada qator YO\'Q, PostgreSQL da esa '
                . $dstCount . ' ta bor.');
            $this->line('  Davom etilsa o\'sha ' . $dstCount . ' ta qayd O\'CHIB KETARDI.');
            $this->line('  Sana ustuni to\'g\'ri tanlanganini tekshiring (--date-column) yoki');
            $this->line('  haqiqatan o\'chirish kerak bo\'lsa: pg:sync ' . $table . ' --full');
            return 1;
        }
        $this->line('');

        if ($isDry) {
            $this->comment('--dry-run: hech narsa o\'zgartirilmadi.');
            return 0;
        }
        if (! $this->option('force') && ! $this->confirm('Davom etamizmi?', false)) {
            $this->comment('Bekor qilindi.');
            return 0;
        }

        $bar = $this->output->createProgressBar($srcCount);
        $res = $this->dateTable($src, $dst, $table, $dateCol, $myStruct, $cols, $pgTypes, $notNull, $chunk, $bounds, $bar);
        $bar->finish();
        $this->line('');

        if (! $res['ok']) {
            $this->line('');
            $this->error('XATO — hech narsa o\'zgarmadi (rollback): ' . $res['error']);
            return 1;
        }

        $this->line('');
        $this->info('O\'chirildi : ' . $res['deleted'] . ' ta');
        $this->info('Yozildi    : ' . $res['written'] . ' ta');
        $this->info('Sequence   : ' . $res['seq']);

        return 0;
    }

    /**
     * Sana oynasining chegaralarini hisoblaydi.
     *
     *   --date berilgan      → o'sha sanadan
     *   berilmagan           → PostgreSQL dagi MAX(sana) dan (--days bilan orqaga surilgan)
     *   PostgreSQL bo'sh     → from = null (hammasi)
     *
     * @return array|null  from, to, source  |  xato bo'lsa null
     */
    private function dateBounds($dst, $table, $dateCol, array $myStruct)
    {
        // Ustun sof sana (date) mi yoki vaqtli (datetime/timestamp) mi — yuqori
        // chegara shunga qarab `<= to` yoki `< to + 1 kun` bo'ladi.
        $temporal = isset($myStruct['columns'][$dateCol])
            && in_array(strtolower($myStruct['columns'][$dateCol]['DATA_TYPE']),
                        array('datetime', 'timestamp'), true);

        $to = $this->option('date-to');
        $to = ($to === null || trim($to) === '') ? null : trim($to);

        if ($to !== null && ! $this->isDate($to)) {
            $this->error('--date-to noto\'g\'ri formatda: ' . $to . ' (kutilgani YYYY-MM-DD)');
            return null;
        }

        $given = $this->option('date');
        if ($given !== null && trim($given) !== '') {
            $given = trim($given);
            if (! $this->isDate($given)) {
                $this->error('--date noto\'g\'ri formatda: ' . $given . ' (kutilgani YYYY-MM-DD)');
                return null;
            }
            if ($to !== null && $given > $to) {
                $this->error('--date (' . $given . ') --date-to (' . $to . ') dan katta.');
                return null;
            }
            return array('from' => $given, 'to' => $to, 'temporal' => $temporal, 'source' => 'buyruq qatoridan');
        }

        $row = $dst->selectOne('SELECT MAX(' . $this->q($dateCol) . ') AS m FROM ' . $this->q($table));
        if ($row === null || $row->m === null) {
            return array('from' => null, 'to' => $to, 'temporal' => $temporal, 'source' => 'PostgreSQL bo\'sh');
        }

        // MAX() timestamp qaytarishi mumkin — kun boshiga keltiramiz, chunki
        // o'sha KUN butunlay qayta yoziladi.
        $from = substr((string) $row->m, 0, 10);

        $days = (int) $this->option('days');
        if ($days > 0) {
            $from   = date('Y-m-d', strtotime($from . ' -' . $days . ' days'));
            $source = 'PostgreSQL dagi MAX(' . $dateCol . ') − ' . $days . ' kun';
        } else {
            $source = 'PostgreSQL dagi MAX(' . $dateCol . ')';
        }

        return array('from' => $from, 'to' => $to, 'temporal' => $temporal, 'source' => $source);
    }

    /**
     * So'rovga sana oynasi shartini qo'shadi.
     *
     * Ustun timestamp/datetime bo'lsa yuqori chegara `< to + 1 kun` bo'ladi —
     * aks holda `<= '2026-12-31'` o'sha kunning soatlarini tashlab ketardi.
     */
    private function dateScope($q, $dateCol, array $bounds)
    {
        if ($bounds['from'] !== null) {
            $q->where($dateCol, '>=', $bounds['from']);
        }
        if ($bounds['to'] !== null) {
            if (! empty($bounds['temporal'])) {
                $q->where($dateCol, '<', date('Y-m-d', strtotime($bounds['to'] . ' +1 day')));
            } else {
                $q->where($dateCol, '<=', $bounds['to']);
            }
        }
        return $q;
    }

    /**
     * Sana oynasidagi qatorlarni PostgreSQL da almashtiradi: oynani o'chirib,
     * MySQL dagi o'sha oynani qaytadan yozadi. Bitta tranzaksiya.
     *
     * @return array ok, written, deleted, seq, error
     */
    private function dateTable($src, $dst, $table, $dateCol, array $myStruct, array $cols, array $pgTypes, array $notNull, $chunk, array $bounds, $bar)
    {
        $order = $this->orderColumns($myStruct, $cols);
        if (empty($order)) {
            $order = array($dateCol);
        }

        $rowsPerInsert = $this->rowsPerInsert($chunk, count($cols));
        $written       = 0;
        $deleted       = 0;

        $dst->beginTransaction();
        try {
            $deleted = $this->dateScope($dst->table($table), $dateCol, $bounds)->delete();

            $offset = 0;
            while (true) {
                $q = $this->dateScope($src->table($table), $dateCol, $bounds)
                    ->select($cols)->limit($chunk)->offset($offset);
                foreach ($order as $oc) {
                    $q->orderBy($oc);
                }

                $srcRows = $q->get();
                $got     = count($srcRows);
                if ($got === 0) {
                    break;
                }

                $batch = array();
                foreach ($srcRows as $r) {
                    $batch[] = $this->convertRow((array) $r, $pgTypes, $notNull);

                    if (count($batch) >= $rowsPerInsert) {
                        $dst->table($table)->insert($batch);
                        $written += count($batch);
                        if ($bar !== null) {
                            $bar->advance(count($batch));
                        }
                        $batch = array();
                    }
                }

                if ($batch) {
                    $dst->table($table)->insert($batch);
                    $written += count($batch);
                    if ($bar !== null) {
                        $bar->advance(count($batch));
                    }
                }

                $offset += $got;
                if ($got < $chunk) {
                    break;
                }
            }

            $seq = $this->resetSequence($dst, $table, $order[0]);
            $dst->commit();
        } catch (\Exception $e) {
            $dst->rollBack();
            return array('ok' => false, 'written' => 0, 'deleted' => 0, 'seq' => '-', 'error' => $e->getMessage());
        }

        return array('ok' => true, 'written' => $written, 'deleted' => $deleted, 'seq' => $seq, 'error' => null);
    }

    /** YYYY-MM-DD ko'rinishidagi haqiqiy sanami. */
    private function isDate($v)
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return false;
        }
        return $v === date('Y-m-d', strtotime($v));
    }

    /** B rejim — jadvalni to'liq almashtirish (kalit shart emas). */
    private function runFullOne($src, $dst, $table, array $myStruct, array $cols, array $pgTypes, array $notNull, $chunk, $isDry)
    {
        $order    = $this->orderColumns($myStruct, $cols);
        $srcCount = (int) $src->table($table)->count();
        $dstCount = (int) $dst->table($table)->count();

        $this->line('');
        $this->info('Manba         : ' . $this->srcName);
        $this->info('Jadval        : ' . $table . '   (--full: to\'liq almashtirish)');
        $this->info('Tartib        : ' . (empty($order) ? '(yo\'q — jadvalda kalit topilmadi)' : implode(', ', $order)));
        $this->info('Ustunlar      : ' . count($cols) . ' ta');
        $this->info('MySQL dan     : ' . $srcCount . ' ta qayd o\'qiladi');
        $this->info('PostgreSQL da : ' . $dstCount . ' ta qayd O\'CHIRILADI (hammasi)');
        if (empty($order)) {
            $this->warn('Jadvalda birlamchi kalit yo\'q — sahifalash LIMIT/OFFSET bilan '
                . 'bo\'ladi. Ko\'chirish davomida manba o\'zgarsa qatorlar takrorlanishi '
                . 'yoki tushib qolishi mumkin.');
        }
        $this->line('');

        if ($isDry) {
            $this->comment('--dry-run: hech narsa o\'zgartirilmadi.');
            return 0;
        }
        if (! $this->option('force') && ! $this->confirm('Davom etamizmi?', false)) {
            $this->comment('Bekor qilindi.');
            return 0;
        }

        $bar = $this->output->createProgressBar($srcCount);
        $res = $this->replaceTable($src, $dst, $table, $cols, $pgTypes, $notNull, $order, $chunk, $bar);
        $bar->finish();
        $this->line('');

        if (! $res['ok']) {
            $this->line('');
            $this->error('XATO — hech narsa o\'zgarmadi (rollback): ' . $res['error']);
            return 1;
        }

        $this->line('');
        $this->info('O\'chirildi : ' . $res['deleted'] . ' ta');
        $this->info('Yozildi    : ' . $res['written'] . ' ta');
        $this->info('Sequence   : ' . $res['seq']);

        return 0;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  C rejim — ro'yxat bo'yicha
    // ═══════════════════════════════════════════════════════════════════

    /**
     * $SYNC_TABLES dagi jadvallarga faqat yangi kalitli qaydlar qo'shiladi,
     * $FULL_TABLES dagilar esa to'liq qayta yoziladi.
     */
    private function syncList()
    {
        $defKey = trim($this->option('key'));
        $isDry  = (bool) $this->option('dry-run');
        $chunk  = max(1, (int) $this->option('chunk'));

        if (! $this->validIdent($defKey)) {
            $this->error('Kalit ustun nomi noto\'g\'ri: ' . $defKey);
            return 1;
        }

        $items = array();
        foreach (self::$SYNC_TABLES as $entry) {
            $parts   = explode(':', $entry, 2);
            $items[] = array(
                'table' => trim($parts[0]),
                'key'   => isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : $defKey,
                'mode'  => 'append',
            );
        }
        foreach (self::$DATE_TABLES as $entry) {
            $parts   = explode(':', $entry, 2);
            $items[] = array(
                'table'   => trim($parts[0]),
                'key'     => null,
                'dateCol' => isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null,
                'mode'    => 'date',
            );
        }
        foreach (self::$FULL_TABLES as $entry) {
            $items[] = array(
                'table' => trim($entry),
                'key'   => null,
                'mode'  => 'full',
            );
        }

        if (empty($items)) {
            $this->error('Jadval ro\'yxati bo\'sh.');
            $this->line('  Ro\'yxatni ' . __CLASS__ . '::$SYNC_TABLES / ::$DATE_TABLES / ::$FULL_TABLES ga '
                . 'yozing yoki jadval nomini bering:');
            $this->line('      php artisan pg:sync <jadval>');
            return 1;
        }

        if ($this->option('id') !== null && $this->option('id') !== '') {
            $this->warn('--id ro\'yxat rejimida ishlatilmaydi — har bir jadval o\'zining '
                . 'PostgreSQL dagi MAX(kalit) idan davom etadi.');
        }

        $src = DB::connection($this->srcName);
        $dst = DB::connection($this->dstName);

        // ── Reja ──────────────────────────────────────────────────────────
        $this->line('');
        $this->comment('Manba: ' . $this->srcName . ' → ' . $this->dstName);
        $this->comment('Reja: ' . count(self::$SYNC_TABLES) . ' ta jadvalga yangi qaydlar qo\'shiladi, '
            . count(self::$DATE_TABLES) . ' ta jadval oxirgi sanadan davom etadi, '
            . count(self::$FULL_TABLES) . ' ta jadval to\'liq qayta yoziladi.');

        $plan     = array();
        $rows     = array();
        $totalNew = 0;

        foreach ($items as $item) {
            $p      = $this->planTable($src, $dst, $item);
            $plan[] = $p;
            if ($p['mode'] === 'full') {
                $by = 'to\'liq';
            } elseif ($p['mode'] === 'date') {
                $by = ($p['dateCol'] === null ? '?' : $p['dateCol']) . ' (sana)';
            } else {
                $by = $p['key'];
            }

            $rows[] = array(
                $p['table'],
                $by,
                $p['maxId'] === null ? '-' : $p['maxId'],
                $p['new'] === null ? '-' : $p['new'],
                $p['status'],
            );
            if ($p['ok']) {
                $totalNew += (int) $p['new'];
            }
        }

        $this->table(array('Jadval', 'Kalit', 'PG MAX', 'Yoziladi', 'Holat'), $rows);
        $this->info('Jami ' . $totalNew . ' ta qayd yoziladi.');
        $this->line('');

        if ($isDry) {
            $this->comment('--dry-run: hech narsa o\'zgartirilmadi.');
            return 0;
        }
        if (! $this->option('force') && ! $this->confirm('Davom etamizmi?', false)) {
            $this->comment('Bekor qilindi.');
            return 0;
        }

        // ── Ko'chirish (har bir jadval alohida tranzaksiyada) ─────────────
        $this->line('');

        $i       = 0;
        $n       = count($plan);
        $done    = 0;
        $skipped = 0;
        $failed  = 0;
        $written = 0;

        foreach ($plan as $p) {
            $i++;
            $prefix = '[' . $i . '/' . $n . '] ' . str_pad($p['table'], 34, '.') . ' ';

            if (! $p['ok']) {
                $this->line($prefix . 'o\'tkazib yuborildi — ' . $p['status']);
                $skipped++;
                continue;
            }
            // Manbada bu oynada qator bo'lmasa — TEGMAYMIZ.
            // Sana rejimida bu ayniqsa muhim: "0 ta yozish" o'chirishdan keyin
            // PostgreSQL dagi oxirgi kunni YO'Q qilib qo'yardi.
            if (($p['mode'] === 'append' || $p['mode'] === 'date')
                && ! $p['create'] && (int) $p['new'] === 0) {
                $this->line($prefix . 'yangilik yo\'q');
                $done++;
                continue;
            }

            $this->output->write($prefix);
            $res = $this->syncTable($src, $dst, $chunk, $p);

            if (! $res['ok']) {
                $this->line('');
                $this->error('    XATO (' . $p['table'] . ') — bu jadval o\'zgarmadi: ' . $res['error']);
                $failed++;
                continue;
            }

            $this->line($res['written'] . ' ta yozildi'
                . ($p['mode'] === 'append' ? '' : ' (' . $res['deleted'] . ' ta o\'chirildi)'));
            $written += $res['written'];
            $done++;
        }

        $this->line('');
        $this->info('Bajarildi    : ' . $done . ' ta jadval');
        if ($skipped > 0) {
            $this->warn('O\'tkazildi   : ' . $skipped . ' ta jadval');
        }
        if ($failed > 0) {
            $this->error('Xato         : ' . $failed . ' ta jadval');
        }
        $this->info('Jami yozildi : ' . $written . ' ta qayd');

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Bitta jadval uchun rejani hisoblaydi (hech narsa o'zgartirmaydi).
     *
     * @return array table, key, mode, ok, create, maxId, new, status, struct
     */
    private function planTable($src, $dst, array $item)
    {
        $table = $item['table'];
        $key   = $item['key'];

        $p = array(
            'table'   => $table,
            'key'     => $key,
            'mode'    => $item['mode'],
            'dateCol' => isset($item['dateCol']) ? $item['dateCol'] : null,
            'bounds'  => null,
            'ok'      => false,
            'create'  => false,
            'maxId'   => null,
            'new'     => null,
            'status'  => '',
            'struct'  => null,
        );

        if (! $this->validIdent($table)) {
            $p['status'] = 'jadval nomi noto\'g\'ri';
            return $p;
        }
        if ($p['mode'] === 'append' && ! $this->validIdent($key)) {
            $p['status'] = 'kalit ustun nomi noto\'g\'ri';
            return $p;
        }

        $myStruct = $this->mysqlStructure($src, $table);
        if (empty($myStruct['columns'])) {
            $p['status'] = $this->srcName . ' da YO\'Q';
            return $p;
        }
        if ($p['mode'] === 'append' && ! isset($myStruct['columns'][$key])) {
            $p['status'] = $this->srcName . ' da "' . $key . '" ustuni yo\'q';
            return $p;
        }
        $p['struct'] = $myStruct;

        $pgTypes = $this->pgColumnTypes($dst, $table);

        if (empty($pgTypes)) {
            if ($this->option('no-create')) {
                $p['status'] = 'PostgreSQL da yo\'q (--no-create)';
                return $p;
            }
            $p['ok']     = true;
            $p['create'] = true;
            $p['new']    = (int) $src->table($table)->count();
            $p['status'] = 'PG da yo\'q → yaratiladi';
            return $p;
        }

        if ($p['mode'] === 'full') {
            $p['ok']     = true;
            $p['new']    = (int) $src->table($table)->count();
            $p['status'] = 'to\'liq qayta yoziladi';
            return $p;
        }

        // ── Sana rejimi: PG dagi MAX(sana) dan davom etadi ────────────────
        if ($p['mode'] === 'date') {
            $cols = array_values(array_intersect(array_keys($myStruct['columns']), array_keys($pgTypes)));

            $dateCol = $p['dateCol'];
            if ($dateCol === null) {
                $dateCol = $this->resolveDateColumnQuiet($myStruct, $cols);
            } elseif (! in_array($dateCol, $cols, true)) {
                $p['status'] = '"' . $dateCol . '" ustuni ikkala bazada ham yo\'q';
                return $p;
            }
            if ($dateCol === null) {
                $p['status'] = 'sana ustuni topilmadi';
                return $p;
            }

            $bounds = $this->dateBounds($dst, $table, $dateCol, $myStruct);
            if ($bounds === null) {
                $p['status'] = 'sana chegarasi noto\'g\'ri';
                return $p;
            }

            $p['ok']      = true;
            $p['dateCol'] = $dateCol;
            $p['bounds']  = $bounds;
            $p['maxId']   = $bounds['from'] === null ? null : $bounds['from'];
            $p['new']     = (int) $this->dateScope($src->table($table), $dateCol, $bounds)->count();

            if ($bounds['from'] === null) {
                $p['status'] = 'PG bo\'sh → hammasi';
            } elseif ($p['new'] > 0) {
                $p['status'] = $bounds['from'] . ' dan qayta yoziladi';
            } else {
                $p['status'] = 'yangilik yo\'q';
            }

            return $p;
        }

        if (! isset($pgTypes[$key])) {
            $p['status'] = 'PostgreSQL da "' . $key . '" ustuni yo\'q';
            return $p;
        }

        $row = $dst->selectOne('SELECT MAX(' . $this->q($key) . ') AS m FROM ' . $this->q($table));
        $max = ($row === null || $row->m === null) ? null : $row->m;

        $p['ok']    = true;
        $p['maxId'] = $max;

        if ($max === null) {
            $p['new']    = (int) $src->table($table)->count();
            $p['status'] = 'PG bo\'sh → hammasi';
        } else {
            $p['new']    = (int) $src->table($table)->where($key, '>', $max)->count();
            $p['status'] = $p['new'] > 0 ? 'yangi qayd bor' : 'yangilik yo\'q';
        }

        return $p;
    }

    /**
     * Rejaga ko'ra bitta jadvalni sinxronlaydi. Bitta tranzaksiya: xato bo'lsa
     * shu jadvalda hech narsa o'zgarmaydi.
     *
     * @return array ok, written, deleted, seq, error
     */
    private function syncTable($src, $dst, $chunk, array $p)
    {
        $table = $p['table'];
        $fail  = array('ok' => false, 'written' => 0, 'deleted' => 0, 'seq' => '-', 'error' => null);

        if ($p['create']) {
            $built = $this->buildCreateSql($table, $p['struct'], ! $this->option('no-index'));
            $err   = $this->runDdl($dst, $built['sql']);
            if ($err !== null) {
                $fail['error'] = 'jadval yaratilmadi: ' . $err;
                return $fail;
            }
        }

        $pgTypes = $this->pgColumnTypes($dst, $table);
        if (empty($pgTypes)) {
            $fail['error'] = 'PostgreSQL da jadval topilmadi';
            return $fail;
        }

        $cols = array_values(array_intersect(array_keys($p['struct']['columns']), array_keys($pgTypes)));
        if (empty($cols)) {
            $fail['error'] = 'ikkala bazada ham mos keladigan ustun yo\'q';
            return $fail;
        }

        $notNull = $this->pgNotNullColumns($dst, $table);

        if ($p['mode'] === 'full') {
            $order = $this->orderColumns($p['struct'], $cols);
            return $this->replaceTable($src, $dst, $table, $cols, $pgTypes, $notNull, $order, $chunk, null);
        }

        if ($p['mode'] === 'date') {
            $dateCol = $p['dateCol'];
            if ($dateCol === null) {
                $dateCol = $this->resolveDateColumnQuiet($p['struct'], $cols);
            }
            if ($dateCol === null || ! in_array($dateCol, $cols, true)) {
                $fail['error'] = 'sana ustuni topilmadi';
                return $fail;
            }

            // Jadval hozirgina yaratilgan bo'lsa chegara reja paytida hisoblanmagan.
            $bounds = $p['bounds'];
            if ($bounds === null) {
                $bounds = $this->dateBounds($dst, $table, $dateCol, $p['struct']);
                if ($bounds === null) {
                    $fail['error'] = 'sana chegarasi noto\'g\'ri';
                    return $fail;
                }
            }

            return $this->dateTable($src, $dst, $table, $dateCol, $p['struct'],
                $cols, $pgTypes, $notNull, $chunk, $bounds, null);
        }

        $key = $p['key'];
        if (! in_array($key, $cols, true)) {
            $fail['error'] = '"' . $key . '" ustuni ikkala bazada ham mavjud emas';
            return $fail;
        }

        return $this->appendTable($src, $dst, $table, $key, $cols, $pgTypes, $notNull, $chunk);
    }

    /**
     * PostgreSQL dagi MAX(kalit) dan katta kalitli qaydlarni MySQL dan qo'shadi.
     * Mavjud qaydlar o'chirilmaydi.
     *
     * @return array ok, written, deleted, seq, error
     */
    private function appendTable($src, $dst, $table, $key, array $cols, array $pgTypes, array $notNull, $chunk)
    {
        // Boshlang'ich chegara — PostgreSQL dagi so'nggi kalit (bo'sh bo'lsa null → hammasi)
        $row    = $dst->selectOne('SELECT MAX(' . $this->q($key) . ') AS m FROM ' . $this->q($table));
        $lastId = ($row === null || $row->m === null) ? null : $row->m;

        $rowsPerInsert = $this->rowsPerInsert($chunk, count($cols));
        $written       = 0;

        $dst->beginTransaction();
        try {
            while (true) {
                $q = $src->table($table)->select($cols)->orderBy($key)->limit($chunk);
                if ($lastId !== null) {
                    $q->where($key, '>', $lastId);
                }

                $srcRows = $q->get();
                if (count($srcRows) === 0) {
                    break;
                }

                $batch = array();
                foreach ($srcRows as $r) {
                    $arr     = (array) $r;
                    $lastId  = $arr[$key];
                    $batch[] = $this->convertRow($arr, $pgTypes, $notNull);

                    if (count($batch) >= $rowsPerInsert) {
                        $dst->table($table)->insert($batch);
                        $written += count($batch);
                        $batch = array();
                    }
                }

                if ($batch) {
                    $dst->table($table)->insert($batch);
                    $written += count($batch);
                }
            }

            $seq = $this->resetSequence($dst, $table, $key);
            $dst->commit();
        } catch (\Exception $e) {
            $dst->rollBack();
            return array('ok' => false, 'written' => 0, 'deleted' => 0, 'seq' => '-', 'error' => $e->getMessage());
        }

        return array('ok' => true, 'written' => $written, 'deleted' => 0, 'seq' => $seq, 'error' => null);
    }

    /**
     * PostgreSQL dagi jadvalni bo'shatib, MySQL dagi hamma qaydni yozadi.
     * O'suvchi kalit talab qilinmaydi — sahifalash birlamchi kalit (bo'lsa)
     * bo'yicha tartiblangan LIMIT/OFFSET bilan bo'ladi.
     *
     * @return array ok, written, deleted, seq, error
     */
    private function replaceTable($src, $dst, $table, array $cols, array $pgTypes, array $notNull, array $order, $chunk, $bar)
    {
        $rowsPerInsert = $this->rowsPerInsert($chunk, count($cols));
        $written       = 0;
        $deleted       = 0;

        $dst->beginTransaction();
        try {
            $deleted = $dst->table($table)->delete();

            $offset = 0;
            while (true) {
                $q = $src->table($table)->select($cols)->limit($chunk)->offset($offset);
                foreach ($order as $oc) {
                    $q->orderBy($oc);
                }

                $srcRows = $q->get();
                $got     = count($srcRows);
                if ($got === 0) {
                    break;
                }

                $batch = array();
                foreach ($srcRows as $r) {
                    $batch[] = $this->convertRow((array) $r, $pgTypes, $notNull);

                    if (count($batch) >= $rowsPerInsert) {
                        $dst->table($table)->insert($batch);
                        $written += count($batch);
                        if ($bar !== null) {
                            $bar->advance(count($batch));
                        }
                        $batch = array();
                    }
                }

                if ($batch) {
                    $dst->table($table)->insert($batch);
                    $written += count($batch);
                    if ($bar !== null) {
                        $bar->advance(count($batch));
                    }
                }

                $offset += $got;
                if ($got < $chunk) {
                    break;
                }
            }

            // Kalit ustuni identity/serial bo'lsa ketma-ketlik ham suriladi.
            $seq = empty($order) ? 'yo\'q (kalit topilmadi)' : $this->resetSequence($dst, $table, $order[0]);
            $dst->commit();
        } catch (\Exception $e) {
            $dst->rollBack();
            return array('ok' => false, 'written' => 0, 'deleted' => 0, 'seq' => '-', 'error' => $e->getMessage());
        }

        return array('ok' => true, 'written' => $written, 'deleted' => $deleted, 'seq' => $seq, 'error' => null);
    }

    /**
     * To'liq ko'chirishda barqaror sahifalash uchun tartib ustunlari:
     * birlamchi kalit (bo'lsa), bo'lmasa bo'sh massiv.
     */
    private function orderColumns(array $struct, array $cols)
    {
        if (empty($struct['pk'])) {
            return array();
        }
        return array_values(array_intersect($struct['pk'], $cols));
    }

    /** Bitta INSERT ga sig'adigan qatorlar soni (PostgreSQL placeholder chegarasi). */
    private function rowsPerInsert($chunk, $colCount)
    {
        return max(1, min($chunk, (int) floor(self::PG_MAX_BIND_PARAMS / max(1, $colCount))));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  MySQL struktura → PostgreSQL DDL
    // ═══════════════════════════════════════════════════════════════════

    /** DDL larni bitta tranzaksiyada bajaradi; xato bo'lsa matnini qaytaradi. */
    private function runDdl($dst, array $ddl)
    {
        $dst->beginTransaction();
        try {
            foreach ($ddl as $sql) {
                $dst->statement($sql);
            }
            $dst->commit();
        } catch (\Exception $e) {
            $dst->rollBack();
            return $e->getMessage();
        }

        return null;
    }

    /**
     * MySQL jadval strukturasi: ustunlar (to'liq metadata), birlamchi kalit,
     * indekslar va e'tibor talab qiladigan holatlar ro'yxati.
     */
    private function mysqlStructure($conn, $table)
    {
        $cols = $conn->select(
            'SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE,
                    CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
                    COLUMN_TYPE, EXTRA
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            array($table)
        );

        $out = array('columns' => array(), 'pk' => array(), 'indexes' => array(), 'notes' => array());
        foreach ($cols as $c) {
            $a = (array) $c;
            $out['columns'][$a['COLUMN_NAME']] = $a;
        }
        if (empty($out['columns'])) {
            return $out;
        }

        $idx = $conn->select(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            array($table)
        );

        $grouped = array();
        foreach ($idx as $i) {
            $a    = (array) $i;
            $name = $a['INDEX_NAME'];
            if (! isset($grouped[$name])) {
                $grouped[$name] = array('unique' => ((int) $a['NON_UNIQUE'] === 0), 'cols' => array());
            }
            $grouped[$name]['cols'][] = $a['COLUMN_NAME'];
        }

        foreach ($grouped as $name => $g) {
            if ($name === 'PRIMARY') {
                $out['pk'] = $g['cols'];
            } else {
                $out['indexes'][$name] = $g;
            }
        }

        return $out;
    }

    /**
     * CREATE TABLE (+ CREATE INDEX) SQL larini yasaydi.
     *
     * @return array ['sql' => bajariladigan SQL lar, 'notes' => ogohlantirishlar]
     */
    private function buildCreateSql($table, array $struct, $withIndexes)
    {
        $lines = array();
        $notes = array();

        foreach ($struct['columns'] as $name => $c) {
            $pgType = $this->pgType($c);
            $def    = $this->q($name) . ' ' . $pgType;

            $isAuto = stripos((string) $c['EXTRA'], 'auto_increment') !== false;
            if ($isAuto) {
                // PostgreSQL da identity ustuni FAQAT smallint/integer/bigint bo'la oladi.
                // MySQL "bigint unsigned" odatda numeric(20,0) ga tushardi — bu yerda
                // bigint ga keltiriladi (auto_increment kalit uchun amalda yetarli).
                $idType = $this->identityType($pgType);
                if ($idType !== $pgType) {
                    $notes[] = '"' . $name . '" identity ustuni ' . $pgType . ' emas, '
                        . $idType . ' qilib yaratildi (PostgreSQL cheklovi).';
                    $pgType = $idType;
                }
                $def = $this->q($name) . ' ' . $pgType;
                // BY DEFAULT — aniq ID bilan yozishga ruxsat beradi (ALWAYS bermaydi).
                $def .= ' GENERATED BY DEFAULT AS IDENTITY';
            } else {
                $d = $this->pgDefault($c, $pgType);
                if ($d !== null) {
                    $def .= ' DEFAULT ' . $d;
                }
            }

            if (strtoupper($c['IS_NULLABLE']) === 'NO') {
                $def .= ' NOT NULL';

                // MySQL "0000-00-00" ni saqlaydi, PostgreSQL rad etadi. Bunday
                // ustun NOT NULL bo'lsa ko'chirishda qator yiqilishi mumkin.
                if ($this->isTemporal($pgType)) {
                    $notes[] = '"' . $name . '" NOT NULL sana ustuni — manbada '
                        . '"0000-00-00" bo\'lsa o\'sha qator yozilmaydi (PostgreSQL rad etadi).';
                }
            }

            $lines[] = '    ' . $def;
        }

        if (! empty($struct['pk'])) {
            $pk = array();
            foreach ($struct['pk'] as $c) {
                $pk[] = $this->q($c);
            }
            $lines[] = '    PRIMARY KEY (' . implode(', ', $pk) . ')';
        }

        $sql   = array();
        $sql[] = 'CREATE TABLE ' . $this->q($table) . " (\n" . implode(",\n", $lines) . "\n)";

        if ($withIndexes) {
            foreach ($struct['indexes'] as $name => $g) {
                $cols = array();
                foreach ($g['cols'] as $c) {
                    $cols[] = $this->q($c);
                }
                // PostgreSQL da indeks nomi butun schema bo'yicha noyob bo'lishi shart
                // (MySQL da faqat jadval ichida), shuning uchun jadval nomi qo'shiladi.
                $idxName = $this->shortIdent($table . '_' . $name);
                $sql[]   = 'CREATE ' . ($g['unique'] ? 'UNIQUE ' : '') . 'INDEX ' . $this->q($idxName)
                         . ' ON ' . $this->q($table) . ' (' . implode(', ', $cols) . ')';
            }
        }

        return array('sql' => $sql, 'notes' => $notes);
    }

    /** MySQL ustun tipini PostgreSQL tipiga o'giradi. */
    private function pgType(array $c)
    {
        $t   = strtolower($c['DATA_TYPE']);
        $ct  = strtolower((string) $c['COLUMN_TYPE']);
        $uns = strpos($ct, 'unsigned') !== false;
        $len = $c['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int) $c['CHARACTER_MAXIMUM_LENGTH'] : 0;

        switch ($t) {
            // ── Butun sonlar (PostgreSQL da unsigned YO'Q — kengroq tip olinadi) ──
            case 'bit':
            case 'year':
            case 'tinyint':      // tinyint(1) ham smallint — boolean ishlatilmaydi
                return 'smallint';
            case 'smallint':
                return $uns ? 'integer' : 'smallint';
            case 'mediumint':
                return 'integer';
            case 'int':
            case 'integer':
                return $uns ? 'bigint' : 'integer';
            case 'bigint':
                return $uns ? 'numeric(20,0)' : 'bigint';

            // ── O'nlik / suzuvchi ──
            case 'decimal':
            case 'numeric':
                $p = $c['NUMERIC_PRECISION'] !== null ? (int) $c['NUMERIC_PRECISION'] : 20;
                $s = $c['NUMERIC_SCALE'] !== null ? (int) $c['NUMERIC_SCALE'] : 0;
                return 'numeric(' . max(1, $p) . ',' . max(0, $s) . ')';
            case 'float':
                return 'real';
            case 'double':
                return 'double precision';

            // ── Matn ──
            case 'char':
                return 'character(' . max(1, $len) . ')';
            case 'varchar':
                return 'character varying(' . max(1, $len) . ')';
            case 'enum':
            case 'set':
                return 'character varying(' . $this->enumWidth($ct) . ')';
            case 'tinytext':
            case 'text':
            case 'mediumtext':
            case 'longtext':
                return 'text';

            // ── Sana / vaqt ──
            case 'date':
                return 'date';
            case 'datetime':
            case 'timestamp':
                return 'timestamp without time zone';
            case 'time':
                return 'time without time zone';

            // ── Boshqa ──
            case 'json':
                return 'jsonb';
            case 'binary':
            case 'varbinary':
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
                return 'bytea';
        }

        return 'text';
    }

    /** enum('a','bb') → eng uzun qiymat uzunligi (varchar kengligi uchun). */
    private function enumWidth($columnType)
    {
        if (! preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $columnType, $m)) {
            return 255;
        }
        $max = 1;
        foreach ($m[1] as $v) {
            $l = strlen(str_replace("''", "'", $v));
            if ($l > $max) {
                $max = $l;
            }
        }
        return $max;
    }

    /** MySQL DEFAULT ni PostgreSQL DEFAULT ifodasiga o'giradi (yaroqsiz bo'lsa null). */
    private function pgDefault(array $c, $pgType)
    {
        $d = $c['COLUMN_DEFAULT'];
        if ($d === null) {
            return null;
        }

        $dl = strtolower(trim((string) $d));

        if ($dl === 'current_timestamp' || strpos($dl, 'current_timestamp(') === 0 || $dl === 'now()') {
            return 'CURRENT_TIMESTAMP';
        }
        if (strpos($dl, '0000-00-00') === 0) {
            return null; // PostgreSQL bunday sanani qabul qilmaydi
        }
        if ($dl === 'null') {
            return null;
        }

        if ($this->isNumericType($pgType)) {
            return is_numeric(trim((string) $d)) ? trim((string) $d) : null;
        }
        if ($pgType === 'jsonb' || $pgType === 'bytea') {
            return null;
        }
        if ($this->isTemporal($pgType) && trim((string) $d) === '') {
            return null;
        }

        return "'" . str_replace("'", "''", (string) $d) . "'";
    }

    /**
     * PostgreSQL identity ustuni faqat smallint/integer/bigint bo'la oladi —
     * boshqa har qanday sonli tip bigint ga keltiriladi.
     */
    private function identityType($pgType)
    {
        if ($pgType === 'smallint' || $pgType === 'integer' || $pgType === 'bigint') {
            return $pgType;
        }
        return 'bigint';
    }

    private function isNumericType($pgType)
    {
        return (bool) preg_match('/^(smallint|integer|bigint|numeric|real|double precision)/', $pgType);
    }

    private function isTemporal($pgType)
    {
        return (bool) preg_match('/^(date|timestamp|time)/', $pgType);
    }

    /** PostgreSQL identifikator 63 baytdan uzun bo'lsa qisqartiradi. */
    private function shortIdent($name)
    {
        if (strlen($name) <= self::PG_MAX_IDENT) {
            return $name;
        }
        return substr($name, 0, self::PG_MAX_IDENT - 9) . '_' . substr(md5($name), 0, 8);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Ko'chirish yordamchilari
    // ═══════════════════════════════════════════════════════════════════

    /** PostgreSQL jadval ustunlari: [ustun => data_type]. Jadval yo'q bo'lsa bo'sh. */
    private function pgColumnTypes($conn, $table)
    {
        $rows = $conn->select(
            'SELECT column_name, data_type
               FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = ?
              ORDER BY ordinal_position',
            array($table)
        );

        $out = array();
        foreach ($rows as $r) {
            $out[$r->column_name] = $r->data_type;
        }
        return $out;
    }

    /** PostgreSQL da NOT NULL bo'lgan ustunlar: [ustun => true]. */
    private function pgNotNullColumns($conn, $table)
    {
        $rows = $conn->select(
            'SELECT column_name
               FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = ? AND is_nullable = \'NO\'',
            array($table)
        );

        $out = array();
        foreach ($rows as $r) {
            $out[$r->column_name] = true;
        }
        return $out;
    }

    private function countFrom($conn, $table, $key, $startId)
    {
        $q = $conn->table($table);
        if ($startId !== null) {
            $q->where($key, '>=', $startId);
        }
        return (int) $q->count();
    }

    /** MySQL qatorini PostgreSQL qabul qiladigan ko'rinishga keltiradi. */
    private function convertRow(array $row, array $pgTypes, array $notNull)
    {
        $out = array();
        foreach ($row as $col => $val) {
            $type = isset($pgTypes[$col]) ? $pgTypes[$col] : 'text';
            $out[$col] = $this->convertValue($val, $type, isset($notNull[$col]));
        }
        return $out;
    }

    private function convertValue($val, $type, $isNotNull)
    {
        if ($val === null) {
            return null;
        }

        // Sana/vaqt: MySQL "0000-00-00" ni saqlaydi, PostgreSQL rad etadi
        if ($this->isTemporal($type)) {
            $s = trim((string) $val);
            if ($s === '' || strpos($s, '0000-00-00') === 0) {
                return null;
            }
            return $s;
        }

        // Sonlar: MySQL bo'sh satrni 0 deb qabul qiladi, PostgreSQL rad etadi.
        // NOT NULL ustunda NULL o'rniga 0 — MySQL semantikasi bilan bir xil.
        if ($this->isNumericType($type)) {
            $s = trim((string) $val);
            if ($s === '') {
                return $isNotNull ? '0' : null;
            }
            return $s;
        }

        // JSON: bo'sh satr yaroqli JSON emas
        if ($type === 'json' || $type === 'jsonb') {
            $s = trim((string) $val);
            if ($s === '') {
                return $isNotNull ? '{}' : null;
            }
            return $s;
        }

        // Matn: NUL bayt va yaroqsiz UTF-8 PostgreSQL da xato beradi
        if (is_string($val)) {
            $s = str_replace("\0", '', $val);
            if (function_exists('mb_check_encoding') && ! mb_check_encoding($s, 'UTF-8')) {
                $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            }
            return $s;
        }

        return $val;
    }

    /**
     * Ketma-ketlikni jadvaldagi MAX(kalit) ga suradi.
     * Jadval bo'shatilgan bo'lsa 1 dan boshlanadi (is_called = false).
     * Kalit identity/serial bo'lmasa — sequence yo'q, o'tkazib yuboriladi.
     */
    private function resetSequence($conn, $table, $key)
    {
        $row = $conn->selectOne('SELECT pg_get_serial_sequence(?, ?) AS seq', array($table, $key));
        if ($row === null || $row->seq === null) {
            return 'yo\'q (ustun identity/serial emas)';
        }

        $keyQ   = $this->q($key);
        $tableQ = $this->q($table);

        $res = $conn->selectOne(
            'SELECT setval(?,
                        COALESCE((SELECT MAX(' . $keyQ . ') FROM ' . $tableQ . '), 1),
                        (SELECT MAX(' . $keyQ . ') FROM ' . $tableQ . ') IS NOT NULL) AS v',
            array($row->seq)
        );

        return $row->seq . ' → ' . $res->v;
    }

    private function validIdent($name)
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }

    /** PostgreSQL identifikatorini qo'shtirnoqqa oladi. */
    private function q($ident)
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }
}
