<?php

namespace App\Console\Commands;

use App\Jobs\OrgDebit;
use Illuminate\Console\Command;
use App\Http\Constant;
use Illuminate\Support\Facades\DB;
use Log;


class calcBalance extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'org:balance {arg}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculating balance for organizations';

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
                $this->RecalculationDate('2025-01-01');
                return;
            }
            if ($this->argument('arg') == 'latest') {
                $dt = now()->subDay()->format('Y-m-d');
                Log::info('Recalculate all latest month data: '. $dt);
                $this->RecalculationDate($dt);
                return;
            }
            if ($this->argument('arg') == date('Y-m-d', strtotime($this->argument('arg')))) {
                if (date('Y-m-d', strtotime($this->argument('arg'))) > now()->subDay()->format('Y-m-d') ||
                    date('Y', strtotime($this->argument('arg'))) < '2025') {
                    $this->error('Recalculate is not allowed for today and future date or before 2025');
                    Log::info('Recalculate is not allowed for today and future date');
                    return;
                }
                Log::info('Recalculate all data in '. $this->argument('arg'));
                $this->RecalculationDate($this->argument('arg'));
                return;
            }

        }catch (\Exception $ex) {
            Log::error('Recalculation command: ' . $ex->getMessage());
        }
    }

    private function RecalculationDate($dt)
    {
        $period = \Carbon\CarbonPeriod::create($dt, now()->subDay())->day();
        $days = collect($period)->map(function (\Carbon\Carbon $date) {
            return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $date)->format('Y-m-d');
        })->toArray();

        DB::table('i_balance')->where('dt', '>=', $dt)->delete();

        foreach ($days as $dt){
            $started = microtime(true);
            $i = 'Recalucalation command launched for: ' . $dt;
            Log::info($i);
            $this->info($i);

            $result = $this->reportByDate($dt);
            if ($result) {
                foreach ($result as $a) DB::table('i_balance')->insert(collect($a)->toArray());
            }

            $elapsed =  (microtime(true) - $started);
            $this->info('Calced date: ' . $dt .' elapsed '. $elapsed . 'secs.');
        }
    }

    public function reportByDate($dt)
    {
        // egaz-indexator dagi so'rovning AYNAN o'zi: MySQL dialekti (DATE(), IFNULL,
        // DATE_SUB), manba `mysql1` (brrgz). Yagona farq — asl so'rovda `re as r` va
        // `regions as r` BITTA FROM ichida IKKI MARTA `r` aliasi edi va MySQL
        // "Not unique table/alias: 'r'" bilan yiqilardi. Viloyat jadvali `rg` deb
        // nomlandi; `r.to_rgs` / `r.yoqotish_rgs` avvalgidek `re` CTE siga tegishli.
        $sql = "with org as (select o.id, o.name, o.id_region, o.id_district, o.orgtype_id from organizations as o), tb as (select i.id_to, SUM(i.qty_accepted) as qty_accepted from tb_fc_invoices as i join org as o on i.id_to = o.id where DATE(i.accepted_at) = '$dt' group by o.id), tr as (select f.id_from, SUM(f.qty_output) as qty_output from tb_transit as f join org as o on f.id_from = o.id where f.dt = '$dt' group by o.id), ta as (select t.id_to, SUM(t.qty_accepted) as qty_accepted from tb_transit as t join org as o on t.id_to = o.id where DATE(t.accepted_at) = '$dt' group by o.id), re as (select o.id as id_org, sum(accepted_qty) * 20 as to_rgs, sum(accepted_qty) * 0.003 as yoqotish_rgs from tb_requests as req join org as o on req.id_gns = o.id where req.id_gns = o.id and req.dt_creation = '$dt' group by o.id), ro as (select o.id as id_org, sum(accepted_qty) * 20 as rgs_oldi from tb_requests as req join org as o on req.id_raygas = o.id where req.id_raygas = o.id and DATE(req.dt_acception) = '$dt' group by o.id), ah as (select o.id as id_org, count(rb.id) * 20 as aholiga from tb_requests_ballons as rb join tb_requests as req on rb.id_request = req.id join org as o on req.id_raygas = o.id where req.id_raygas = o.id and rb.dt = '$dt' group by o.id) select rg.id as id_reg, rg.name_ru as region, o.id as id_org, o.orgtype_id as id_orgtype, o.name as hgt, IFNULL(i.qty_accepted, 0.00) as zavod_qabul, IFNULL(f.qty_output, 0.00) as transit_chiqdi, IFNULL(t.qty_accepted, 0.00) as transit_qabul, IFNULL(r.to_rgs, 0.000) as rgs_chiqdi, IFNULL(r.yoqotish_rgs, 0.000) as yoqotish_rgs, IFNULL(p.rgs_oldi, 0.000) as rgs_oldi, IFNULL(a.aholiga, 0.000) as aholiga, IFNULL(i.qty_accepted, 0.00) - IFNULL(f.qty_output, 0.00) + IFNULL(t.qty_accepted, 0.00) - IFNULL(r.to_rgs, 0.000) - IFNULL(r.yoqotish_rgs, 0.000) + IFNULL(p.rgs_oldi, 0.000) - IFNULL(a.aholiga, 0.000) + IFNULL((select qoldiq from i_balance where id_org = o.id and dt = DATE_SUB('$dt', INTERVAL 1 DAY)), 0.000) as qoldiq, '$dt' as dt from org as o left join tb as i on i.id_to = o.id left join tr as f on f.id_from = o.id left join ta as t on t.id_to = o.id left join re as r on r.id_org = o.id left join ro as p on p.id_org = o.id left join ah as a on a.id_org = o.id join regions as rg on o.id_region = rg.id where o.orgtype_id != 1 and o.id_region not in (11, 15)";

        // L13: select() xom SATR kutadi — Expression obyektida __toString() yo'q.
        return DB::connection('mysql1')->select($sql);
    }

}
