<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
| DIQQAT (ko'chirish): bu fayldagi URL lar egaz-indexator dagilar bilan
| AYNAN bir xil bo'lishi SHART — tashqi tizimlar (1C, tarozi, GNP kamera,
| dispenserlar, UzGPS, ijtimoiy soha, sath o'lchagichlar, egaz asosiy tizimi)
| shu manzillarga murojaat qiladi. Yangi manzil qo'shish mumkin, mavjudini
| o'zgartirish/olib tashlash MUMKIN EMAS.
|
| CSRF: quyidagi POST manzillar bootstrap/app.php dagi
| `validateCsrfTokens(except: [...])` ro'yxatida — aks holda 419 qaytadi.
|
*/

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

// L5.5 da: Route::get('/home', 'HomeController@index')->name('home');
// Kontroller-satr sintaksisi L8 dan olib tashlangan; URL va route nomi o'zgarmadi.
Route::get('/home', [HomeController::class, 'index'])->name('home');

Route::get('/test-bid/{id}', function($id){
    /*$row = \DB::connection('pgsql1')->table('tb_gas_debit')->where('sys_bid', $id)->first();*/
    dd($id);
});

Route::post('/datatransactions', function(){
    try {
        //$data = json_decode(json_encode(\Request::all()),true);
        //return json_encode(['status'=>'success', 'message' => 'OK'], JSON_UNESCAPED_UNICODE);
        App\Jobs\Transaction::dispatch($_POST);
        return json_encode(['status'=>'success', 'message' => 'OK'], JSON_UNESCAPED_UNICODE);
    }
    catch (\Exception $ex) {
        return json_encode(['status'=>'error', 'message' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
});

Route::post('/datarealizations', function(){
    App\Jobs\Realizations::dispatch($_POST);
    return json_encode(['status'=>'success', 'data' => \Request::all()], JSON_UNESCAPED_UNICODE);
});

Route::post('uzgps/{ver}', function ($ver) { return (new \App\Services\UzGPS())->pullRequest(request()->all(),$ver); });

Route::post('scales/info', function () { return (new \App\Services\Scales())->pullRequest(request()->all()); });
Route::post('scales/photo', function () { return (new \App\Services\Scales())->gotPhoto(request()->all()); });

Route::post('gnp-camera', function () { return (new \App\Services\GnpCamera())->pullRequest(request()->all()); });
Route::post('factory-invoice', function () { return (new \App\Services\FactoryInvoice())->pullRequest(request()->all()); });
// DIQQAT: request()->all() EMAS — butun Request obyekti (xom baytlar getContent() orqali kerak).
Route::post('factory-signature', function () { return (new \App\Services\FactorySignature())->pullRequest(request()); });
Route::post('dispensers', function () { return (new \App\Services\GasDispenser())->pullRequest(request()->all()); });
Route::post('engraving', function () { return (new \App\Services\Engraving())->pullRequest(request()->all()); });
Route::post('social-sphere', function () { return (new \App\Services\SocialSphere())->pullRequest(request()->all()); });
Route::post('levelmeters', function () { return (new \App\Services\Levelmeters())->pullRequest(request()->all()); });
