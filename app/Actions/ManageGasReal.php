<?php

namespace App\Actions;


use Illuminate\Support\Facades\DB;

/**
 * `i_real_failed` dagi xato realizatsiyalarni QAYTA yurgizadi.
 *
 * ManageGasDebit (i_money_failed) ning realizatsiya tomonidagi juftligi — oqim:
 *
 *   i_real_failed.id_tran  =  tb_requests_ballons.id
 *        ↓ manba qator (mysql1: tb_requests + tb_requests_ballons)
 *   IReal::handle()  →  i_real_details  (i_real_orgs va deposit triggerlari bilan)
 *        ↓ yana yiqilsa
 *   i_real_failed    ← IReal o'zi yangi `err` bilan qaytadan yozib qo'yadi
 *
 * ⚠ QATOR AVVAL O'CHIRILADI, keyin IReal chaqiriladi — tartib MUHIM.
 *   `i_real_failed` birlamchi kaliti — `id_tran`. Qator jadvalda turganda
 *   IReal qayta yiqilsa o'sha id_tran ni QAYTA INSERT qiladi va duplicate-key
 *   otadi; IReal.php:25 va :55 dagi INSERT lar esa try/catch dan TASHQARIDA,
 *   ya'ni istisno butun paketni uzib qo'yardi. Oldin o'chirilsa — xato yo'lida
 *   IReal qatorni yangilangan `err` bilan o'zi qayta yozadi, natija idempotent.
 *
 * TEZLIK. Qatorlar bittalab emas, paket-paket o'qiladi: 500 ta xato tranzaksiya
 * uchun mysql1 ga 500 ta emas, BITTA `whereIn` so'rovi ketadi. Kursor
 * (`id_tran > $lastId`) tufayli har qator aynan BIR MARTA ko'riladi — qayta
 * yiqilgan qator o'sha id_tran bilan qaytib yozilsa ham kursor undan o'tib
 * ketgan bo'ladi, ya'ni cheksiz aylanish bo'lmaydi.
 *
 * @see \App\Actions\ManageGasDebit  i_money_failed uchun aynan shu naqsh
 * @see \App\Actions\IReal::handle()
 * @see \App\Console\Commands\calcRealizations  `php artisan realDetails failed`
 */
class ManageGasReal
{
    /** Bir paketda nechta xato tranzaksiya olinadi (ManageGasDebit dagidek 500) */
    const CHUNK = 500;

    public function handle()
    {
        \Log::info('___Start Failed realizations');

        $lastId  = 0;
        $seen    = 0;
        $sourced = 0;

        while (true) {
            // Kursor: har paket oldingisidan KEYINGI id_tran lardan boshlanadi.
            $ids = DB::table('i_real_failed')
                ->where('id_tran', '>', $lastId)
                ->orderBy('id_tran')
                ->take(self::CHUNK)
                ->pluck('id_tran')
                ->toArray();

            if (!$ids) break;

            $lastId = end($ids);
            $seen  += count($ids);

            // IReal ga bermasdan OLDIN o'chiramiz — yuqoridagi ⚠ ga qarang.
            // Query Builder orqali: dual write nusxani (pgsql) o'zi oladi.
            DB::table('i_real_failed')->whereIn('id_tran', $ids)->delete();

            // Manba qator — calcRealizations dagi AYNAN o'sha join va ustunlar
            // ro'yxati. IReal shu nomlarni kutadi, bittasi kam bo'lsa yiqiladi.
            $rows = DB::connection('mysql1')->table('tb_requests as r')
                ->join('tb_requests_ballons as rb', 'r.id', '=', 'rb.id_request')
                ->whereIn('rb.id', $ids)
                ->whereNotNull('rb.passed_at')
                ->select('r.id_raygas', 'r.numb', 'rb.price', 'rb.id_abonent', 'rb.passed_by',
                    'rb.passed_at', 'rb.location_lon', 'rb.location_lat', 'rb.id_ballon', 'rb.dt', 'rb.id')
                ->get();

            $sourced += count($rows);

            if (count($rows) > 0) (new IReal())->handle($rows);
        }

        // Manbada topilmagan qatorlar QAYTA YOZILMAYDI. Ular tb_requests_ballons
        // dan o'chirilgan yoki `passed_at` NULL bo'lib qolgan — ya'ni endi
        // realizatsiya emas. ManageGasDebit ham shunday qiladi (u yerdagi izoh:
        // "ular brrgz da endi yo'q va har yurishda 500 tali blokni band qilardi").
        \Log::info('___End Failed realizations: ko`rilgan ' . $seen . ', manbadan topilgan ' . $sourced);
    }
}
