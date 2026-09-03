<?php

/*
|--------------------------------------------------------------------------
| egaz-indexator (MySQL) → egazindex (PostgreSQL) satr sinxronizatsiyasi
|--------------------------------------------------------------------------
|
| egaz-indexator `i_real_details` / `i_money_details` ga qator qo'shganda uni
| shu manzilga yuboradi:
|
|     POST /api/v1/index/rows
|
| Yuboruvchi taraf:
|     egaz-indexator/app/Services/IndexPushService.php
|     egaz-indexator/config/index_push.php
|
| Qabul qiluvchi:
|     App\Http\Controllers\Api\IndexSyncController  — tekshiradi, navbatga qo'yadi
|     App\Jobs\ApplyIndexRows                       — PostgreSQL ga yozadi
|
*/

return [

    // Asosiy richag. false — endpoint 503 qaytaradi (kirish yopiq).
    'enabled' => env('INDEX_SYNC_ENABLED', true),

    // X-API-Key — yuboruvchi tarafdagi INDEX_PUSH_KEY bilan bir xil bo'lishi shart.
    // Bo'sh qoldirilsa kalit TEKSHIRILMAYDI (faqat ichki tarmoqda mumkin).
    'key' => env('INDEX_SYNC_KEY'),

    // HMAC-SHA256 maxfiy so'zi (yuboruvchi: INDEX_PUSH_SECRET). Bo'sh bo'lsa
    // imzo tekshirilmaydi.
    'secret' => env('INDEX_SYNC_SECRET'),

    // Qaysi ulanishga yoziladi. `pgsql` = egaz_idxpost (indexator PostgreSQL) —
    // config/database.php dagi standart ulanish.
    'connection' => env('INDEX_SYNC_CONNECTION', 'pgsql'),

    // Job qaysi navbatga tushadi. QUEUE_DRIVER=sync bo'lsa bu e'tiborsiz
    // qoldiriladi va job so'rov ichida darhol bajariladi.
    'queue' => env('INDEX_SYNC_QUEUE', 'default'),

    // Bitta so'rovda maksimal qator (yuboruvchi: INDEX_PUSH_CHUNK dan katta bo'lsin).
    'max_rows' => (int) env('INDEX_SYNC_MAX_ROWS', 500),

    // Har bir qabul/yozuv uchun info-log.
    'log' => env('INDEX_SYNC_LOG', true),

    /*
    | Qabul qilinadigan jadvallar va ularning BIRLAMCHI KALITI.
    |
    | PK egaz-indexator MySQL dagi qo'shma kalitning aynan o'zi:
    |   i_real_details  → PRIMARY KEY (yy, dt, ballon_kod, abonent_kod)
    |   i_money_details → PRIMARY KEY (dt, yy, sys_bid)
    |
    | PK bu yerda ikki ish uchun kerak:
    |   1) qatorda kalit ustunlari bor-yo'qligini tekshirish (yo'q bo'lsa
    |      qator tashlanadi — kalitsiz qator agregatni buzadi);
    |   2) log/diagnostikada qatorni nomlash.
    |
    | ⚠ Jadval qo'shsangiz yuboruvchi tarafdagi `index_push.tables` ga ham
    |   qo'shing — aks holda u yerdan hech narsa chiqmaydi.
    */
    'tables' => [
        'i_real_details' => [
            'pk' => ['yy', 'dt', 'ballon_kod', 'abonent_kod'],
        ],
        'i_money_details' => [
            'pk' => ['dt', 'yy', 'sys_bid'],
        ],
    ],

];
