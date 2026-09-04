<?php

/*
|--------------------------------------------------------------------------
| Eski MySQL triggerlarining PHP servislari (dual write: MySQL + PostgreSQL)
|--------------------------------------------------------------------------
|
| `docs/idxdb_triggers.sql` dagi har bir DB trigger uchun `App\Services\DbTriggers`
| ichida bitta PHP metod yozilgan. Bu yerdagi bayroqlar shu metodlarni YOQADI.
|
| ⚙ ILOVA IKKI BAZAGA YOZADI (config/dual_write.php):
|
|     mysql  (egaz_idxdb)  — DB triggerlari BOR  → PHP tomoni O'CHIQ
|     pgsql  (egaz_idxpost) — DB trigger YO'Q     → PHP tomoni YOQIQ
|
|   Ya'ni bir xil trigger mantig'i har bir bazada AYNAN BIR MARTA bajariladi:
|   MySQL da baza o'zi, PostgreSQL nusxasida esa shu yerdagi PHP metodlari.
|   Qaysi ulanishda PHP ishlashini pastdagi `php_connections` belgilaydi —
|   bayroqlarga tegish kerak emas.
|
| IKKI BAYROQ ATAYLAB `false` QOLDIRILDI — ular MySQL da ham NO-OP edi
| (tanasi bo'sh yoki to'liq izohda), yoqish ko'chirish emas, YANGI
| xatti-harakat qo'shish bo'lardi:
|     cms_users.updateKod
|     i_money_details.i_money_details_orgs
|
| ⚠ AGAR PostgreSQL da shu nomdagi trigger YARATILSA — o'sha ulanishni
|   `php_connections` dan chiqaring, aks holda amal IKKI MARTA bajariladi.
|   `php artisan triggers:status --connection=pgsql` ikkala tomon holatini
|   ko'rsatadi.
|
| Bayroqni .env orqali ham o'zgartirsa bo'ladi (DB_TRIGGER_* kalitlari),
| config keshlangan bo'lsa keyin `php artisan config:cache`.
|
| Batafsil: docs/db-triggers-to-service-migration.md, docs/dual-write.md
|
*/

return [

    /*
    | Umumiy kalit (avariya o'chirgichi).
    */
    'enabled' => env('DB_TRIGGERS_PHP', true),

    /*
    | Triggerli jadvallar QAYSI ulanishda yotadi.
    |
    | Standart ulanish (`mysql` = egaz_idxdb) — shuning uchun null. Boshqa
    | ulanish kerak bo'lsa TriggerBus metodlariga oxirgi argument sifatida
    | beriladi: PostgreSQL nusxasi uchun buni App\Services\DualWrite\Mirror
    | AVTOMATIK qiladi ('pgsql' / 'pgsql1').
    |
    | config/database.php:
    |   mysql   → DB_*      (egaz_idxdb — STANDART, triggerlar shu yerda)
    |   mysql1  → DB_*1     (EGAZ MAIN / brrgz)
    |   pgsql   → PGIDX_*   (egaz_idxdb ning PostgreSQL nusxasi)
    |   pgsql1  → PGPUSH_*  (EGAZ MAIN ning PostgreSQL nusxasi — egaz-push)
    |
    | ⚙ TRIGGER KODI IKKALA DIALEKTDA HAM ISHLAYDI. Dialekt ulanishning
    |   `driver` iga qarab avtomatik tanlanadi (BaseTrigger::isPg()):
    |     upsert     — MySQL: ON DUPLICATE KEY UPDATE / PG: ON CONFLICT DO UPDATE
    |     insIgnore  — MySQL: INSERT IGNORE          / PG: ON CONFLICT DO NOTHING
    |     toUInt     — MySQL: CONVERT(x, UNSIGNED)   / PG: qorovullangan CASE
    |   Ya'ni bu ulanishni eski MySQL bazasiga qaratsangiz ham hammasi ishlaydi.
    |
    | ⚠ PostgreSQL da bu jadvallarda DB trigger YO'Q (ular MySQL sxemasida
    |   qolgan edi). Ya'ni PG nusxasida yon ta'sirni FAQAT shu yerdagi
    |   bayroqlar beradi.
    */
    'connection' => env('DB_TRIGGERS_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | PHP triggerlari QAYSI ULANISHLARDA ishlaydi
    |--------------------------------------------------------------------------
    |
    | Bu ro'yxat dual write ning kaliti. Bayroq yoqilgan bo'lsa ham, PHP
    | triggeri FAQAT shu ro'yxatdagi ulanishda bajariladi:
    |
    |   pgsql, pgsql1  — PostgreSQL nusxalari, ularda DB trigger YO'Q → PHP ishlaydi
    |   mysql, mysql1  — ro'yxatda YO'Q, chunki bazalarning O'Z triggerlari bor
    |
    | Natija: har bir bazada trigger mantig'i AYNAN BIR MARTA bajariladi.
    |
    | `null` qilib qo'ysangiz — ulanish tekshirilmaydi, bayroq yoqilgan bo'lsa
    | HAMMA ulanishda ishlaydi (dual write dan oldingi xatti-harakat).
    |
    | ⚠ MySQL dagi DB triggerlarni DROP qilsangiz — 'mysql' ni shu ro'yxatga
    |   QO'SHING, aks holda MySQL tomonda yon ta'sir bajarilmay qoladi.
    */
/*    'php_connections' => [
        'pgsql',
        'pgsql1',
    ],*/

    /*
    | Bajarilgan har bir PHP trigger amali logga yoziladi (DBTRG prefiksi).
    */
    'log' => env('DB_TRIGGERS_LOG', true),

    /*
    | Quruq yurish: yoqilgan bo'lsa ham BAZAGA YOZMAYDI, faqat logga chiqaradi.
    | Baza triggeri hali turganda PHP versiyasini solishtirib sinash uchun.
    */
    'dry_run' => env('DB_TRIGGERS_DRY_RUN', false),

    /*
    | true — PHP triggerdagi xato yutiladi (faqat log), asosiy amal davom etadi;
    | false — xato yuqoriga otiladi (baza triggeri kabi). Standart.
    */
    'fail_open' => env('DB_TRIGGERS_FAIL_OPEN', false),

    /*
    | AFTER UPDATE / DELETE triggeri yoqilgan bo'lsa, TriggerBus eski qatorlarni
    | oldin o'qib oladi (MySQL FOR EACH ROW semantikasi uchun). Shart keng bo'lsa
    | (masalan calcDebit dagi sana oralig'i) bu millionlab qator bo'lishi mumkin.
    | Shundan oshsa logga ogohlantirish yoziladi. 0 = tekshirmaslik.
    */
    'max_old_rows' => env('DB_TRIGGERS_MAX_OLD_ROWS', 5000),

    /*
    |--------------------------------------------------------------------------
    | Trigger bayroqlari — `jadval.trigger_nomi`, bazadagi nom bilan AYNAN bir xil
    |--------------------------------------------------------------------------
    */
    'triggers' => [

        // ---- cms_users -------------------------------------------------------
        // ⚠ Bu 5 ta trigger asosiy egaz bazasidagilar bilan MAZMUNAN BIR XIL
        //   (egaz/docs/egaz_triggers.sql). Ya'ni cms_users ikkala bazada ham
        //   triggerli. Qaysi bazadagi qatorni yozayotganingizga qarab mos
        //   loyihaning bayrog'ini yoqing — ikkalasini emas.
        'cms_users.setIDkodUser'     => env('DB_TRIGGER_CMS_USERS_SETIDKODUSER', true),
        'cms_users.incUsers'         => env('DB_TRIGGER_CMS_USERS_INCUSERS', true),
        // BEFORE UPDATE — bazada tanasi BO'SH (no-op)
        'cms_users.updateKod'        => env('DB_TRIGGER_CMS_USERS_UPDATEKOD', false),
        'cms_users.updSubscrbStatus' => env('DB_TRIGGER_CMS_USERS_UPDSUBSCRBSTATUS', true),
        'cms_users.decUSers'         => env('DB_TRIGGER_CMS_USERS_DECUSERS', true),

        // ---- i_abonent_details ------------------------------------------------
        // AFTER INSERT — i_abonent_orgs upsert: all_users+1 (status bo'lsa active_users+1)
        'i_abonent_details.i_abonent_details_ai' => env('DB_TRIGGER_I_ABONENT_DETAILS_AI', true),
        // AFTER UPDATE — AYNAN SHU upsert, ya'ni har UPDATE da hisoblagich YANA oshadi
        'i_abonent_details.i_abonent_details_au' => env('DB_TRIGGER_I_ABONENT_DETAILS_AU', true),

        // ---- i_deposit_details ------------------------------------------------
        // AFTER INSERT — i_deposit_orgs upsert (deposit/kg/amount/real_amount jamlanadi)
        'i_deposit_details.insert_i_deposit_details' => env('DB_TRIGGER_INSERT_I_DEPOSIT_DETAILS', true),

        // ---- i_money_details --------------------------------------------------
        // AFTER INSERT — organizations.deposit += NEW.amount
        'i_money_details.plus_deposit_org'     => env('DB_TRIGGER_PLUS_DEPOSIT_ORG', true),
        // AFTER DELETE — bazada tanasi TO'LIQ izohga olingan (no-op)
        //
        // ⛔ DOIMO `false` QOLDIRING. Ikki sabab:
        //   1. Bazada bu trigger HECH NARSA qilmaydi — yoqish "ko'chirish" emas,
        //      YANGI xatti-harakat qo'shish bo'ladi.
        //   2. calcDebit.php da har bir i_money_details DELETE dan bir qator
        //      OLDIN i_money_orgs QO'LDA o'chiriladi (:35/:37, :100/:102,
        //      :118/:120). Ya'ni yoqilsa allaqachon o'chirilgan qatorlardan
        //      yana ayirishga urinadi.
        //   3. Bu bayroq yoqilsa TriggerBus DELETE dan oldin barcha mos
        //      qatorlarni o'qiydi — calcDebit da bu sana ORALIG'I, ya'ni
        //      millionlab qator xotiraga tushishi mumkin.
        // docs/db-triggers-to-service-migration.md §6.4
        'i_money_details.i_money_details_orgs' => env('DB_TRIGGER_I_MONEY_DETAILS_ORGS', false),

        // ---- i_real_details ---------------------------------------------------
        // AFTER INSERT — i_real_orgs upsert (amount_total += amount, total_qty += 1)
        'i_real_details.insert_i_real_details' => env('DB_TRIGGER_INSERT_I_REAL_DETAILS', true),
        // AFTER INSERT — organizations.deposit -= NEW.amount
        // ⚠ Yuqoridagi bilan BIR XIL INSERT da yonadi; bazadagi tartib: avval
        //   insert_i_real_details, keyin minus_deposit_orgs.
        'i_real_details.minus_deposit_orgs'    => env('DB_TRIGGER_MINUS_DEPOSIT_ORGS', true),

        // ---- tb_factory_integration -------------------------------------------
        // BEFORE INSERT — hgt_filial → hgt_filial_egaz, factory → factory_egaz (CASE)
        'tb_factory_integration.tb_factory_integration_bi' => env('DB_TRIGGER_TB_FACTORY_INTEGRATION_BI', true),

        // ---- tb_scales_logs ----------------------------------------------------
        // BEFORE INSERT — org_id qayta xaritalash (1→13, 239→350)
        'tb_scales_logs.tb_scales_logs_bi' => env('DB_TRIGGER_TB_SCALES_LOGS_BI', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Asl bazada ham HECH NARSA qilmagan triggerlar (NO-OP)
    |--------------------------------------------------------------------------
    | Bularning MySQL dagi tanasi bo'sh yoki to'liq izohda edi. Ya'ni "na
    | bazada, na PHP da" holati ular uchun XATO EMAS — aynan shunday bo'lishi
    | kerak. `triggers:status` shu ro'yxatdagilarni ogohlantirishga qo'shmaydi.
    */
    'noop' => [
        'cms_users.updateKod',
        'i_money_details.i_money_details_orgs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bir xil hodisada yonadigan triggerlar — birga ko'chirilishi shart.
    |--------------------------------------------------------------------------
    | Bazada bitta INSERT ikkala triggerni ham uyg'otadi. Bittasini PHP ga
    | o'tkazib ikkinchisini bazada qoldirish mumkin, lekin `triggers:status`
    | shu juftlikni doim ko'rsatib turadi.
    */
    'pairs' => [
        'i_real_details' => [
            'i_real_details.insert_i_real_details',
            'i_real_details.minus_deposit_orgs',
        ],
    ],
];
