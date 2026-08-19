<?php

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
