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
        $this->transactions = DB::table('i_money_failed')->orderBy('id_tran')->take(500)->pluck('id_tran')->toArray();
        DB::table('i_money_failed')->whereIn('id_tran', $this->transactions)->delete();
        $gasDebits = DB::connection('pgsql1')->table('tb_gas_debit')->whereIn('id', $this->transactions)->get();

        foreach($gasDebits->groupBy('dt_pay') as $key => $item){
            (new IMoneyDebit())->handle($gasDebits->where('dt_pay', $key), $key);
        }
        \Log::info("___End Failed transactions");
    }

}