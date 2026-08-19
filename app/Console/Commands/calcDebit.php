<?php

namespace App\Console\Commands;

use App\Actions\FindProvider;
use App\Actions\IMoneyDebit;
use App\Actions\ManageGasDebit;
use DB;
use Illuminate\Console\Command;
use Exception;
use Log;

class calcDebit extends Command {
    protected $signature = 'debit:make {arg}';
    protected $description = 'Manual recalculating transactions';
    public function __construct() {parent::__construct();}

    public function handle() {
        try {
            if ($this->argument('arg') && count(explode('=',$this->argument('arg'))) == 2) {
                // make 2024-07-14=2024-08-05
                Log::info('Recalculate for period: ' . $this->argument('arg'));
                $dates = explode('=', $this->argument('arg'));
                //iterate between 1st date and 2nd date
                $start = $dates[0];
                $end = $dates[1];
                // get diffrence days between two dates
                $diff = abs(strtotime($end) - strtotime($start));
                $days = floor($diff / (60*60*24));
                if ($start != date('Y-m-d', strtotime($start)) || $end != date('Y-m-d', strtotime($end))) {
                    Log::error('Wrong Start or End date format!');
                    $this->error('Wrong Start or End date format!');
                    return;
                }
                DB::table('i_money_orgs')->whereBetween('dt', [$start, $end])->delete();
                // DB trigger `i_money_details_orgs` (AFTER DELETE) — bazada tanasi izohda (no-op)
                \App\Services\DbTriggers\TriggerBus::delete('i_money_details', function ($q) use ($start, $end) { $q->whereBetween('dt', [$start, $end]); });

                for ($i = 0; $i <= $days; $i++) {
                    $this->info('Recalculating date: ' . date('Y-m-d', strtotime($start . ' + ' . $i . ' days')));
                    $dt = date('Y-m-d', strtotime($start . ' + ' . $i . ' days'));
                    $this->RecalculationDate($dt, false);
                }
                return;
            }
            if ($this->argument('arg') == 'full') {
                Log::info('Recalculate all data');
                $this->RecalculationAll();
                return;
            }
            if ($this->argument('arg') == date('Y-m-d', strtotime($this->argument('arg')))) {
                Log::info('Recalculate all data in '. $this->argument('arg'));
                $this->RecalculationDate($this->argument('arg'));
                return;
            }
            if ($this->argument('arg') == 'retry') {
                Log::info('Recalculate all data in continued date');
                $this->RecalculationRetry();
                return;
            }
            if ($this->argument('arg') == 'failed') {
                Log::info('Recalculate failed data');
                (new ManageGasDebit())->handle();
                return;
            }
            if (!$this->argument('arg')) {
                Log::error('Wrong argument!');
                $this->error('Wrong argument!');
                return;
            }
        }
        catch (\Exception $ex) {
            Log::error('Recalculation command: ' . $ex->getMessage());
        }
    }

    private function RecalculationAll() {
        // L13: select() xom SATR kutadi â€” Expression obyektida __toString() yo'q.
        $allDateTransactions = DB::connection('pgsql1')->select("select dt_pay, count(*) as qty from tb_gas_debit group by dt_pay order by dt_pay");
        $i = 'Recalucalation debit started, found rows: ' . count($allDateTransactions);
        $this->info($i); Log::debug($i);
        DB::table('i_money_orgs')->truncate();
        DB::table('i_money_details')->truncate();
        DB::table('i_money_failed')->truncate();

        foreach ($allDateTransactions as $allDateTransaction) {
            $gasDebits = DB::connection('pgsql1')->select("select * from tb_gas_debit where dt_pay = '$allDateTransaction->dt_pay'");
            $started = microtime(true);
            (new IMoneyDebit())->handle($gasDebits, $allDateTransaction->dt_pay);
            $elapsed =  (microtime(true) - $started);
            $this->info('Calced date: ' . $allDateTransaction->dt_pay .' elapsed '. $elapsed . 'secs.');
        }
    }


    private function RecalculationDate($dt, $forceDelete = true) {
        $started = microtime(true);
        $i = 'Recalucalation command launched for: ' . $dt;
        Log::info($i); $this->info($i);
        if ($forceDelete) {
            DB::table('i_money_orgs')->where('dt', $dt)->delete();
            // DB trigger `i_money_details_orgs` (AFTER DELETE) — bazada tanasi izohda (no-op)
            \App\Services\DbTriggers\TriggerBus::delete('i_money_details', ['dt' => $dt]);
        }
        $gasDebits = DB::connection('pgsql1')->table('tb_gas_debit')->where('dt_pay', $dt)->get();
        if (!$gasDebits || count($gasDebits) <= 0) {
            $i = 'Recalc command no data found for ' . $dt;
            Log::info($i); $this->info($i);
            return;
        }
        (new IMoneyDebit())->handle($gasDebits, $dt);
        $elapsed =  (microtime(true) - $started);
        $this->info('Calced date: ' . $dt .' elapsed '. $elapsed . 'secs.');
    }

    private function RecalculationRetry() {
        $allDateTransactionsMaxDate = DB::select("select MAX(dt) as dt from i_money_details");

        DB::table('i_money_orgs')->where('dt', $allDateTransactionsMaxDate[0]->dt)->delete();
        // DB trigger `i_money_details_orgs` (AFTER DELETE) — bazada tanasi izohda (no-op)
        \App\Services\DbTriggers\TriggerBus::delete('i_money_details', ['dt' => $allDateTransactionsMaxDate[0]->dt]);
        $maxDate = $allDateTransactionsMaxDate != null ? "where dt_pay >= '{$allDateTransactionsMaxDate[0]->dt}'" : "";
        $allDateTransactions = DB::connection('pgsql1')->select("select dt_pay, count(*) as qty from tb_gas_debit $maxDate group by dt_pay order by dt_pay");
        $i = 'Recalucalation debit started, found rows: ' . count($allDateTransactions);
        $this->info($i); Log::debug($i);

        foreach ($allDateTransactions as $allDateTransaction) {
            $gasDebits = DB::connection('pgsql1')->select("select * from tb_gas_debit where dt_pay = '$allDateTransaction->dt_pay'");
            $started = microtime(true);
            (new IMoneyDebit())->handle($gasDebits, $allDateTransaction->dt_pay);
            $elapsed =  (microtime(true) - $started);
            $this->info('Calced date: ' . $allDateTransaction->dt_pay .' elapsed '. $elapsed . 'secs.');
        }
    }

}
