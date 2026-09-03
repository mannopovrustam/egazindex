<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 02.02.2023
 * Time: 15:04
 */

namespace App\Actions;


use Illuminate\Support\Facades\DB;

class ManageGasDebit
{

    protected $transactions = [];
    public function handle()
    {
        \Log::info("___Start Failed transactions");
        // egaz-indexator fd5505a "fix i_money_failed": shu id dan oldingi eski
        // xato tranzaksiyalar qayta urinilmaydi — ular brrgz da endi yo'q va
        // har yurishda 500 tali blokni band qilib turardi.
        $this->transactions = DB::table('i_money_failed')->where('id_tran','>',150503408)->orderBy('id_tran')->take(500)->pluck('id_tran')->toArray();
        DB::table('i_money_failed')->whereIn('id_tran', $this->transactions)->delete();
        $gasDebits = DB::connection('mysql1')->table('tb_gas_debit')->whereIn('id', $this->transactions)->get();

        foreach($gasDebits->groupBy('dt_pay') as $key => $item){
            (new IMoneyDebit())->handle($gasDebits->where('dt_pay', $key), $key);
        }
        \Log::info("___End Failed transactions");
    }

}