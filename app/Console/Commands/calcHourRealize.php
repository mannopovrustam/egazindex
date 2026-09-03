<?php

namespace App\Console\Commands;

use App\Jobs\OrgDebit;
use Illuminate\Console\Command;
use App\Http\Constant;
use Illuminate\Support\Facades\DB;
use Log;


class calcHourRealize extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hour:realize {arg}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculating Realization by hour';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle() {
        try {
            if ($this->argument('arg') == 'full') {
                Log::info('Recalculate all data');
                $this->RecalculationAll();
                return;
            }
            if ($this->argument('arg') == 'detail') {
                Log::info('Recalculate all data');
                $this->RecalculationAllDetail();
                return;
            }
            if ($this->argument('arg') == 'latest') {
                $dt = now()->subDay()->format('Y-m-d');
                Log::info('Recalculate all latest month data: '. $dt);
                $this->RecalculationDate($dt);
                return;
            }
            if ($this->argument('arg') == date('Y-m-d', strtotime($this->argument('arg')))) {
                Log::info('Recalculate all data in '. $this->argument('arg'));
                $this->RecalculationDate($this->argument('arg'));
                return;
            }

        }catch (\Exception $ex) {
            Log::error('Recalculation command: ' . $ex->getMessage());
        }
    }

    private function RecalculationAll()
    {
        $period = \Carbon\CarbonPeriod::create('2020-01-01', now()->subDay())->day();
        $days = collect($period)->map(function (\Carbon\Carbon $date) {
            return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $date)->format('Y-m-d');
        })->toArray();

        // DB::table('i_hour_realize')->truncate();
        return 0;

        foreach ($days as $dt){
            $started = microtime(true);
            $i = 'Recalucalation command launched for: ' . $dt;
            Log::info($i);
            $this->info($i);

            $result = $this->reportByDate($dt);
            if ($result) {
                foreach ($result as $a) DB::table('i_hour_realize')->insert(collect($a)->toArray());
            }

            $elapsed =  (microtime(true) - $started);
            $this->info('Calced date: ' . $dt .' elapsed '. $elapsed . 'secs.');
        }
    }
    private function RecalculationDate($dt)
    {
        $started = microtime(true);
        $i = 'Recalucalation command launched for: ' . $dt;
        Log::info($i);
        $this->info($i);
        DB::table('i_hour_realize')->where('real_date', $dt)->delete();
        $result = $this->reportByDate($dt);
        if ($result) {
            foreach ($result as $a) {
                DB::table('i_hour_realize')->insert(collect($a)->toArray());
            }
        }

        $resultDetail = $this->reportByDateDetail($dt);
        if ($resultDetail) {
            foreach ($resultDetail as $a) DB::table('i_hour_realize_detail')->insert(collect($a)->toArray());
        }

        $elapsed =  (microtime(true) - $started);
        $this->info('Calced date: ' . $dt .' elapsed '. $elapsed . 'secs.');
    }

    private function RecalculationAllDetail()
    {
        return 0;
        $period = \Carbon\CarbonPeriod::create('2020-01-01', now()->subDay())->day();
        $days = collect($period)->map(function (\Carbon\Carbon $date) {
            return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $date)->format('Y-m-d');
        })->toArray();

        // DB::table('i_hour_realize_detail')->truncate();

        foreach ($days as $dt){
            Log::info($dt);

            $started = microtime(true);
            $i = 'Recalucalation command launched for: ' . $dt;
            Log::info($i);
            $this->info($i);

            $resultDetail = $this->reportByDateDetail($dt);
            if ($resultDetail) {
                foreach ($resultDetail as $a) DB::table('i_hour_realize_detail')->insert(collect($a)->toArray());
            }

            $elapsed =  (microtime(true) - $started);
            $this->info('Calced date: ' . $dt .' elapsed '. $elapsed . 'secs.');
        }
    }

    public function reportByDate($dt)
    {
        $price_gb = Constant::KG_BALLON*Constant::gotPrice($dt);

        return DB::connection('mysql1')->table('tb_requests_ballons as bal')
            ->select('r.id as id_region', DB::raw("'$dt' as real_date"),
                DB::raw('(select count(*) from organizations where id_region = r.id and orgtype_id in (2,3)) as org_qty'),
                DB::raw("(select sum(accepted_qty) from tb_requests where date(dt_acception) = '$dt' and id_raygas in (select id from organizations where id_region = r.id)) as req_qty"),
                DB::raw('sum(if(HOUR(bal.passed_at) = 6, 1, 0)) as `qty_6`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 6, 1, 0)*'.$price_gb.') as `price_6`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 7, 1, 0)) as `qty_7`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 7, 1, 0)*'.$price_gb.') as `price_7`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 8, 1, 0)) as `qty_8`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 8, 1, 0)*'.$price_gb.') as `price_8`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 9, 1, 0)) as `qty_9`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 9, 1, 0)*'.$price_gb.') as `price_9`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 10, 1, 0)) as `qty_10`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 10, 1, 0)*'.$price_gb.') as `price_10`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 11, 1, 0)) as `qty_11`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 11, 1, 0)*'.$price_gb.') as `price_11`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 12, 1, 0)) as `qty_12`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 12, 1, 0)*'.$price_gb.') as `price_12`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 13, 1, 0)) as `qty_13`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 13, 1, 0)*'.$price_gb.') as `price_13`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 14, 1, 0)) as `qty_14`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 14, 1, 0)*'.$price_gb.') as `price_14`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 15, 1, 0)) as `qty_15`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 15, 1, 0)*'.$price_gb.') as `price_15`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 16, 1, 0)) as `qty_16`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 16, 1, 0)*'.$price_gb.') as `price_16`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 17, 1, 0)) as `qty_17`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 17, 1, 0)*'.$price_gb.') as `price_17`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 18, 1, 0)) as `qty_18`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 18, 1, 0)*'.$price_gb.') as `price_18`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 19, 1, 0)) as `qty_19`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 19, 1, 0)*'.$price_gb.') as `price_19`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 20, 1, 0)) as `qty_20`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 20, 1, 0)*'.$price_gb.') as `price_20`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 21, 1, 0)) as `qty_21`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 21, 1, 0)*'.$price_gb.') as `price_21`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 22, 1, 0)) as `qty_22`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 22, 1, 0)*'.$price_gb.') as `price_22`'),
                DB::raw('sum(if(bal.passed_at, 1, 0)) as `pass_qty`'),
                DB::raw('sum(if(bal.passed_at, 1, 0) * '.$price_gb.') as `pass_price`'))

            ->join('tb_requests as req', function ($join){
                $join->on('bal.id_request', '=', 'req.id');
            })
            ->leftJoin('organizations as o', 'req.id_raygas', '=', 'o.id')
            ->leftJoin('regions as r', 'o.id_region', '=', 'r.id')
            ->where('bal.dt', $dt)
            ->groupBy('r.id')
            ->get();
    }

    public function reportByDateDetail($dt)
    {
        $price_gb = Constant::KG_BALLON*Constant::gotPrice($dt);
        return DB::connection('mysql1')->table('tb_requests_ballons as bal')
            ->select('r.id as id_region', 'o.id as id_org', 'o.name as org_name', 'bal.passed_by as inspector_id', 'u.name as inspector_name', DB::raw("'$dt' as real_date"),
                DB::raw('sum(if(HOUR(bal.passed_at) = 6, 1, 0)) as `qty_6`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 6, 1, 0)*'.$price_gb.') as `price_6`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 7, 1, 0)) as `qty_7`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 7, 1, 0)*'.$price_gb.') as `price_7`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 8, 1, 0)) as `qty_8`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 8, 1, 0)*'.$price_gb.') as `price_8`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 9, 1, 0)) as `qty_9`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 9, 1, 0)*'.$price_gb.') as `price_9`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 10, 1, 0)) as `qty_10`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 10, 1, 0)*'.$price_gb.') as `price_10`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 11, 1, 0)) as `qty_11`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 11, 1, 0)*'.$price_gb.') as `price_11`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 12, 1, 0)) as `qty_12`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 12, 1, 0)*'.$price_gb.') as `price_12`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 13, 1, 0)) as `qty_13`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 13, 1, 0)*'.$price_gb.') as `price_13`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 14, 1, 0)) as `qty_14`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 14, 1, 0)*'.$price_gb.') as `price_14`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 15, 1, 0)) as `qty_15`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 15, 1, 0)*'.$price_gb.') as `price_15`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 16, 1, 0)) as `qty_16`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 16, 1, 0)*'.$price_gb.') as `price_16`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 17, 1, 0)) as `qty_17`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 17, 1, 0)*'.$price_gb.') as `price_17`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 18, 1, 0)) as `qty_18`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 18, 1, 0)*'.$price_gb.') as `price_18`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 19, 1, 0)) as `qty_19`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 19, 1, 0)*'.$price_gb.') as `price_19`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 20, 1, 0)) as `qty_20`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 20, 1, 0)*'.$price_gb.') as `price_20`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 21, 1, 0)) as `qty_21`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 21, 1, 0)*'.$price_gb.') as `price_21`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 22, 1, 0)) as `qty_22`'),
                DB::raw('sum(if(HOUR(bal.passed_at) = 22, 1, 0)*'.$price_gb.') as `price_22`'))
            ->join('tb_requests as req', function ($join){
                $join->on('bal.id_request', '=', 'req.id');
            })
            ->leftJoin('organizations as o', 'req.id_raygas', '=', 'o.id')
            ->leftJoin('regions as r', 'o.id_region', '=', 'r.id')
            ->join('cms_users as u', 'bal.passed_by', '=', 'u.id')
            ->where('bal.dt', $dt)
            ->groupBy('r.id', 'o.id', 'bal.passed_by', 'u.name')
            ->get();


    }

}
