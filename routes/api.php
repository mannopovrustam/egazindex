<?php

use App\Http\Controllers\Api\IndexSyncController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
| Prefiks (`api`) va guruh middleware'i (`throttle:60,1` + `bindings`)
| bootstrap/app.php da egaz-indexator dagidek sozlangan.
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

/*
| egaz-indexator (MySQL) → egazindex (PostgreSQL) satr sinxronizatsiyasi.
|
|     POST /api/v1/index/rows
|
| Yuboruvchi: egaz-indexator/app/Services/IndexPushService.php
| Qabul:      App\Http\Controllers\Api\IndexSyncController → App\Jobs\ApplyIndexRows
|
| ⚠ THROTTLE: guruhdagi `throttle:60,1` bu yerga TO'G'RI KELMAYDI — realizatsiya
|   va to'lovlar kuniga o'n minglab bo'ladi, pik soatlarda daqiqasiga 60 tadan
|   ancha ko'p qator keladi va ortiqchasi 429 bilan qaytarilardi (yuboruvchi
|   tomonda ular faqat spool faylga tushib, PG ga hech qachon yetib bormasdi).
|   Shuning uchun guruh chegarasi olib tashlanib, o'z chegarasi qo'yiladi.
|   Kirish X-API-Key + HMAC imzo bilan himoyalangan (config/index_sync.php).
|
| CSRF: `api` guruhida CSRF tekshiruvi umuman yo'q (u faqat `web` da) —
| bootstrap/app.php dagi `validateCsrfTokens(except:)` ga qo'shish SHART EMAS.
*/
Route::post('v1/index/rows', [IndexSyncController::class, 'store'])
    ->withoutMiddleware('throttle:60,1')
    ->middleware('throttle:1200,1')
    ->name('index.sync.rows');
