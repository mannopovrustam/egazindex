<?php

/*
|--------------------------------------------------------------------------
| Ikki bazaga yozish (dual write): MySQL — ASOSIY, PostgreSQL — NUSXA
|--------------------------------------------------------------------------
|
| Ilova egaz-indexator dagidek MySQL ga yozadi (standart ulanish `mysql` =
| egaz_idxdb), va DARHOL keyin AYNAN shu ma'lumotni PostgreSQL nusxasiga
| ko'chiradi — nusxaga `id` va `created_at` ham qo'shiladi.
|
|   yozish       →  mysql   (egaz_idxdb)        →  nusxa: pgsql   (egaz_idxpost)
|   yozish       →  mysql1  (brrgz, EGAZ MAIN)  →  nusxa: pgsql1  (egaz_push)
|
| MANBA O'QISH O'ZGARMAYDI: cms_users / organizations / tb_gas_debit kabi
| manba jadvallar oldingidek `pgsql1` (egaz-push PostgreSQL) dan o'qiladi.
| Faqat YOZISH amallari ikkilanadi.
|
| QANDAY ULANGAN (chaqiruv joylarini o'zgartirmasdan):
|   App\Providers\DualWriteServiceProvider `mysql` drayveri uchun maxsus
|   ulanish klassini ro'yxatga oladi (Connection::resolverFor). U so'rov
|   quruvchisi sifatida App\Database\DualWrite\Builder ni qaytaradi, va
|   INSERT / UPDATE / DELETE / TRUNCATE / UPSERT / INCREMENT amallaridan
|   keyin nusxani App\Services\DualWrite\Mirror ga uzatadi. Ya'ni oddiy
|   `DB::table('i_real_details')->insert($row)` avtomatik ikkilanadi.
|
| ⚠ XOM SQL (`DB::unprepared`, `DB::insert`, `DB::statement`) so'rov
|   quruvchisidan o'tmaydi — u USHLANMAYDI. Hisoblagichli upsert lar
|   (i_money_orgs, idx_dayli_by_orgs) shu sababli
|   App\Services\DualWrite\CounterUpsert orqali yoziladi: u SQL ni har bir
|   dialektga alohida quradi va nusxani ham o'zi bajaradi.
|
| TRANZAKSIYA: asosiy amal tranzaksiya ichida bo'lsa, nusxa DARHOL emas,
|   COMMIT dan keyin yoziladi (afterCommit). Rollback bo'lsa nusxa ham
|   yozilmaydi — ikki baza bir-biriga mos qoladi.
|
| TRIGGERLAR: MySQL tomonda bazaning O'Z triggerlari ishlaydi (egaz_idxdb da
|   ular bor), PostgreSQL tomonda esa PHP versiyalari — config/db_triggers.php
|   dagi `php_connections` shuni belgilaydi. Ya'ni agregat jadvallar
|   (i_*_orgs, organizations.deposit) ikkala bazada ham to'g'ri yuritiladi va
|   HECH QAYERDA ikki marta bajarilmaydi.
|
| Batafsil: docs/dual-write.md
|
*/

return [

    /*
    | Umumiy kalit (avariya o'chirgichi). `false` — ilova FAQAT MySQL ga yozadi,
    | ya'ni aynan egaz-indexator dagi xatti-harakat.
    */
    'enabled' => env('DUAL_WRITE', true),

    /*
    |--------------------------------------------------------------------------
    | Ulanish juftliklari: asosiy => nusxa
    |--------------------------------------------------------------------------
    | Kalit — YOZILADIGAN ulanish nomi, qiymat — nusxa olinadigan ulanish.
    | Ro'yxatda yo'q ulanish (mysql_brrgz, mysql_egaz, pgsql, pgsql1) hech
    | qanday nusxa olmaydi — ular oldingidek ishlaydi.
    */
    'pairs' => [
        'mysql'  => env('DUAL_WRITE_MIRROR', 'pgsql'),
        'mysql1' => env('DUAL_WRITE_MIRROR1', 'pgsql1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | `id` va `created_at`
    |--------------------------------------------------------------------------
    | PostgreSQL nusxasidagi jadvallarda MySQL da bo'lmagan `id` (surrogat
    | kalit) va `created_at` ustunlari bor. Nusxa yozilayotganda ular
    | QO'SHILADI:
    |
    |   id         — MySQL AUTO_INCREMENT bergan qiymat (ya'ni ikki bazada
    |                AYNAN bir xil id). MySQL da bunday ustun bo'lmasa:
    |                PG dagi serial/identity o'zi beradi, u ham bo'lmasa
    |                `MAX(id)+1` hisoblanadi (generate_missing_id).
    |   created_at — nusxa yozilgan payt (Y-m-d H:i:s), agar qatorda
    |                allaqachon bo'lmasa.
    |
    | Nusxa jadvalda bu ustunlar bo'lmasa — hech narsa qo'shilmaydi.
    */
    'id_column'         => 'id',
    'created_at_column' => 'created_at',

    /*
    | MySQL bergan AUTO_INCREMENT id ni nusxaga ko'chirish (id pariteti).
    */
    'copy_insert_id' => env('DUAL_WRITE_COPY_ID', true),

    /*
    | Ko'p qatorli (bulk) INSERT da ham id ko'chirilsinmi.
    |
    | ⛔ STANDART `false`. MySQL bitta bulk INSERT uchun faqat BIRINCHI id ni
    |   qaytaradi; qolganlari ketma-ket bo'lishiga innodb_autoinc_lock_mode=2
    |   (MySQL 8 standarti) da KAFOLAT YO'Q. Shuning uchun bulk INSERT da id
    |   ko'chirilmaydi — PG o'z serial ini ishlatadi.
    */
    'bulk_ids' => env('DUAL_WRITE_BULK_IDS', false),

    /*
    | Nusxadagi `id` ustuni NOT NULL bo'lib, serial/identity ham bo'lmasa —
    | `MAX(id)+1` hisoblanadi. Bu poyga (race) ga moyil; ko'p yozadigan
    | jadvallarda PG tomonda ustunni serial qilish tavsiya etiladi.
    */
    'generate_missing_id' => env('DUAL_WRITE_GENERATE_ID', true),

    /*
    |--------------------------------------------------------------------------
    | Nusxa tomonda PHP triggerlari
    |--------------------------------------------------------------------------
    | `true` — nusxa qatori yozilishidan oldin/keyin App\Services\DbTriggers
    | dagi PHP triggerlari NUSXA ULANISHIDA ishlaydi (PG da DB trigger yo'q).
    | Ya'ni PG dagi i_*_orgs agregatlari va organizations.deposit MySQL
    | tomondagi DB triggerlari qilgan ishni takrorlaydi.
    |
    | Qaysi ulanishda ishlashini config/db_triggers.php → `php_connections`
    | belgilaydi; bu bayroq esa umumiy o'chirgich.
    */
    'triggers' => env('DUAL_WRITE_TRIGGERS', true),

    /*
    |--------------------------------------------------------------------------
    | Xatolarni boshqarish
    |--------------------------------------------------------------------------
    | fail_open = true  — nusxa yozishdagi xato YUTILADI (faqat logga yoziladi),
    |                     asosiy (MySQL) amal buzilmaydi. STANDART: asosiy
    |                     tizim PostgreSQL nosozligidan to'xtab qolmasligi kerak.
    | fail_open = false — xato yuqoriga otiladi.
    */
    'fail_open' => env('DUAL_WRITE_FAIL_OPEN', true),

    /*
    | Ketma-ket shuncha marta xato bo'lsa (masalan PG o'chib qolgan), shu
    | process davomida nusxa olish TO'XTATILADI — log to'lib ketmasligi va
    | har bir yozish sekinlashmasligi uchun. 0 = cheksiz urinish.
    */
    'max_failures' => env('DUAL_WRITE_MAX_FAILURES', 20),

    /*
    | Har bir nusxa amali logga yoziladi (DUALW prefiksi).
    */
    'log' => env('DUAL_WRITE_LOG', true),

    /*
    | Quruq yurish: nusxa BAZAGA yozilmaydi, faqat logga chiqadi.
    | Asosiy (MySQL) yozish odatdagidek bajariladi.
    */
    'dry_run' => env('DUAL_WRITE_DRY_RUN', false),

    /*
    |--------------------------------------------------------------------------
    | Jadval filtri
    |--------------------------------------------------------------------------
    | only_tables bo'sh bo'lsa — skip_tables dan boshqa HAMMA jadval nusxalanadi.
    | skip_tables — framework ning o'z jadvallari va lokal yordamchi jadvallar:
    | ularning PG da o'rni yo'q yoki nusxasi keraksiz.
    */
    'only_tables' => [],

    'skip_tables' => [
        'migrations',
        'jobs',
        'failed_jobs',
        'job_batches',
        'sessions',
        'cache',
        'cache_locks',
        'password_resets',
        'password_reset_tokens',
        'users',
        'tmp_rrr',
        'tmp_index_coming_orgs',
    ],

    /*
    |--------------------------------------------------------------------------
    | `php artisan dual:status` tekshiradigan jadvallar
    |--------------------------------------------------------------------------
    | Ilova YOZADIGAN jadvallar ro'yxati (diagnostika uchun; yozish mantig'iga
    | ta'sir qilmaydi). Komanda har biri uchun: nusxa bazada bor-yo'qligini,
    | `id` / `created_at` ustunlarini va serial ketma-ketlik holatini ko'rsatadi.
    */
    'watch_tables' => [
        'mysql' => [
            'i_abonent_details', 'i_abonent_orgs',
            'i_deposit_details', 'i_deposit_orgs',
            'i_money_details', 'i_money_orgs', 'i_money_failed',
            'i_real_details', 'i_real_orgs', 'i_real_failed',
            'i_balance', 'i_hour_realize', 'i_hour_realize_detail', 'i_rekvizits',
            'idx_dayli_by_orgs', 'idx_dayli_by_orgs_details', 'idx_real_dayli_by_orgs',
            'integration_logs', 'organizations',
            'tb_factory_integration', 'tb_factory_signature_logs',
            'tb_gas_dispensers', 'tb_levelmeters', 'tb_scales_logs',
        ],
        'mysql1' => [
            'tb_factory_signatures',
            'tb_fc_invoices',
            'tb_social_sphere',
        ],
    ],
];
