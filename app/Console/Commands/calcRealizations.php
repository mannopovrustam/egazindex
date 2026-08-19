<?php

namespace App\Console\Commands;

use App\Actions\IReal;
use Illuminate\Console\Command;
use Exception;
use Illuminate\Support\Facades\DB;
use Log;

class calcRealizations extends Command {
    protected $signature = 'realDetails {arg}';
    protected $description = 'Manual recalculating gas realizations';
    public function __construct() {parent::__construct();}

    public function handle() {
        try {
            if ($this->argument('arg') == 'full') {
                Log::info('Realization all data');
                $this->info('Realization all data');
                $this->RealizationsAll();
                return;
            }
            if ($this->argument('arg') == 'retry') {
                Log::info('Retry realization data');
                $this->info('Retry realization data');
                $this->RealizationsAll(true);
                return;
            }

            if ($this->argument('arg') == date('Y-m-d', strtotime($this->argument('arg')))) {
                Log::info('Recalculate all data in '. $this->argument('arg'));
                $this->RealizationDate($this->argument('arg'));
                return;
            }

            if (!$this->argument('arg')) {
                Log::error('Wrong argument!');
                $this->error('Wrong argument!');
                return;
            }
            $this->RealizationDate($this->argument('arg'));
            Log::info('Realization command finished!');
        }
        catch (Exception $ex) {
            Log::error('Realization command: ' . $ex->getMessage());
        }
    }

    private function RealizationDate($dt) {
        $time_start = microtime(true);
        $i = 'Realization command launched for: ' . $dt;
        $this->info($i);

        DB::table('i_real_details')->where('dt', $dt)->delete();
        DB::table('i_real_orgs')->where('dt', $dt)->delete();

        $rows = DB::connection('pgsql1')->table('tb_requests as r')
            ->join('tb_requests_ballons as rb','r.id','=','rb.id_request')
            ->where('rb.dt', $dt)->whereNotNull('rb.passed_at')
            ->select('r.id_raygas','r.numb','rb.price', 'rb.id_abonent','rb.passed_by','rb.passed_at','rb.location_lon','rb.location_lat','rb.id_ballon','rb.dt','rb.id')->get();
        if (!$rows || count($rows) <= 0) {
            $i = 'Realization command no data found for ' . $dt;
            Log::info($i); $this->info($i);
            return;
        }

//        Log::info($rows);

        (new IReal())->handle($rows);

        $time_end = microtime(true);
        $exectime = ($time_end - $time_start);
        $i = 'Realizations command finished for: ' . $dt . " in " . round($exectime,3) ." sec.";
        $this->info($i); Log::info($i);
    }

    private function RealizationsAll($retry_dt = false) {
        if ($retry_dt) {
            $q = DB::table('vw_got_max_real_date')->select('dt')->first();
            $dt = ($q && $q->dt ? $q->dt : '1900-01-01');
            DB::table('i_real_orgs')->where('dt','>=', $dt)->delete();
            DB::table('i_real_details')->where('dt','>=', $dt)->delete();
            $data = DB::connection('pgsql1')->table('tb_requests_ballons as rb')->where('rb.dt','>=', $dt)->groupBy('rb.dt')->select('rb.dt')->get();
        }
        else {
            $data = DB::connection('pgsql1')->table('tb_requests_ballons as rb')->whereNotNull('rb.passed_at')->groupBy('rb.dt')->select('rb.dt')->get();
            DB::table('i_real_orgs')->truncate();
            DB::table('i_real_details')->truncate();
            DB::table('i_real_failed')->truncate();
        }

        if (!$data || count($data) <= 0) {
            $i = 'Realizations command no data found for all period!';
            Log::info($i); $this->info($i);
            return;
        }

        foreach($data as $row){
            $time_start = microtime(true);
            $i = 'Realization command launched for: ' . $row->dt;
            $this->info($i);

            $rows = DB::connection('pgsql1')->table('tb_requests as r')
                ->join('tb_requests_ballons as rb','r.id','=','rb.id_request')
                ->where('rb.dt', $row->dt)->whereNotNull('rb.passed_at')
                ->select('r.id_raygas','r.numb','rb.price', 'rb.id_abonent','rb.passed_by','rb.passed_at','rb.location_lon','rb.location_lat','rb.id_ballon','rb.dt','rb.id')->distinct()->get();
            if (!$rows || count($rows) <= 0) {
                $i = 'Realization command no data found for ' . $row->dt;
                Log::info($i); $this->info($i);
                return;
            }

            (new IReal())->handle($rows);
            $time_end = microtime(true);
            $exectime = ($time_end - $time_start);
            $i = 'Realizations command finished for: ' . $row->dt . " in " . round($exectime,3) ." sec.";
            $this->info($i);
        }
    }
}
