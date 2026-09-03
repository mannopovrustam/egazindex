<?php

/*
|--------------------------------------------------------------------------
| Yuk xati QR tokeni (egaz.uz/factory-invoice-qr)
|--------------------------------------------------------------------------
|
| 1C (zavod) yuk xatini yuborgach, javobda QR uchun TOKEN qaytariladi. 1C
| o'zida bazaviy manzilni (base_url) qo'yib QR kod chizadi:
|
|     https://egaz.uz/factory-invoice-qr/{token}
|     token = md5(EGAZ APP_KEY . '-' . tb_fc_invoices.id) . '-' . id
|
| DIQQAT: `key` — bu EGAZ MAIN (egaz.uz) ilovasining .env dagi XOM APP_KEY
| qiymati ("base64:..." prefiksi bilan birga), egaz-indexator ning O'Z
| APP_KEY i EMAS. egaz da marshrut aynan `env('APP_KEY')` ni tekshiradi
| (routes/web.php — "factory-invoice-qr"), shuning uchun bir belgi ham
| farq qilsa QR 404 beradi.
|
| Kalit bo'sh bo'lsa yuk xati baribir yoziladi, lekin javobda token NULL
| bo'ladi va logga error tushadi.
|
*/

return [

    'base_url' => rtrim(env('WAYBILL_QR_BASE_URL', 'https://egaz.uz/factory-invoice-qr'), '/') . '/',

    'key' => (string) env('WAYBILL_QR_KEY', ''),
];
