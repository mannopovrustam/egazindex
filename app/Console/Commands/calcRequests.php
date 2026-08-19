<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Exception;

class calcRequests extends Command {
    protected $signature = 'calcRequests {arg}';
    protected $description = 'Manual recalculating gas requests';
    public function __construct() {parent::__construct();}

    public function handle() {
        try {
            if ($this->argument('arg') == 'full') {
                \Log::info('Requests all data');
                $this->info('Requests all data');
                $this->RequestsAll();
                return;
            }
            if (!$this->argument('arg')) {
                \Log::error('Wrong argument!');
                $this->error('Wrong argument!');
                return;
            }
            $this->RequestsDate($this->argument('arg'));
            \Log::info('Requests command finished!');
        }
        catch (Exception $ex) {
            \Log::error('Requests command: ' . $ex->getMessage());
        }
    }

    private function RequestsDate($dt) {
        $time_start = microtime(true);
        $i = 'Requests command launched for: ' . $dt;
        $this->info($i);
        // L13: select() xom SATR kutadi — Expression obyektida __toString() yo'q.
        $rows = \DB::connection('pgsql1')->select("with cte as (select r.dt_creation as dt, EXTRACT(YEAR FROM r.dt_creation)::int as yy, EXTRACT(MONTH FROM r.dt_creation)::int as mm, o.id_region, o.id as id_org,r.amount, (SELECT SUM(rb.price) FROM tb_requests_ballons as rb where rb.id_request=r.id and rb.passed_by is not null) as amount_real,r.accepted_qty as total_qty, r.passed_qty as real_qty, r.returned_qty as back_qty from tb_requests as r join organizations as o on o.id=r.id_raygas where r.dt_creation='$dt') select dt,yy,mm,id_region,id_org,SUM(amount) as amount_total, SUM(COALESCE(amount_real,0.00)) as amount_real,SUM(total_qty) as total_qty, SUM(real_qty) as real_qty, SUM(back_qty) as back_qty FROM cte group by dt,yy,mm,id_region,id_org");
        if (!$rows || count($rows) <= 0) {
            $i = 'Requests command no data found for ' . $dt;
            \Log::info($i); $this->info($i);
            return;
        }
        \DB::table('idx_real_dayli_by_orgs')->where('dt', $dt)->delete();
        foreach ($rows as $r) {
            \DB::beginTransaction();
            try {
                \DB::table('idx_real_dayli_by_orgs')->insert([
                    'dt' => $r->dt, 'yy' => $r->yy, 'mm' => $r->mm,
                    'id_region' => $r->id_region, 'id_org' => $r->id_org,
                    'amount_total' => $r->amount_total, 'amount_real' => $r->amount_real,
                    'total_qty' => $r->total_qty, 'real_qty' => $r->real_qty, 'back_qty' => $r->back_qty
                ]);
                \DB::commit();
                $i = 'Requests command for: ' .$r->dt . '-' . $r->id_org . ' OK';
                $this->info($i);
            }
            catch(Exception $ex) {
                $i = 'Requests command error: ' . $ex->getMessage() . ' Rollback data!';
                \Log::error($i); $this->error($i);
                \DB::rollback();
            }
        }
        $time_end = microtime(true);
        $exectime = ($time_end - $time_start);
        $i = 'Requests command finished for: ' . $dt . " in " . round($exectime,3) ." sec.";
        $this->info($i); \Log::info($i);
    }

    private function RequestsAll() {
        $rows = \DB::connection('pgsql1')->select("with cte as (select r.dt_creation as dt, EXTRACT(YEAR FROM r.dt_creation)::int as yy, EXTRACT(MONTH FROM r.dt_creation)::int as mm, o.id_region, o.id as id_org,r.amount, (SELECT SUM(rb.price) FROM tb_requests_ballons as rb where rb.id_request=r.id and rb.passed_by is not null) as amount_real,r.accepted_qty as total_qty, r.passed_qty as real_qty, r.returned_qty as back_qty from tb_requests as r join organizations as o on o.id=r.id_raygas) select dt,yy,mm,id_region,id_org,SUM(amount) as amount_total, SUM(COALESCE(amount_real,0.00)) as amount_real,SUM(total_qty) as total_qty, SUM(real_qty) as real_qty, SUM(back_qty) as back_qty FROM cte group by dt,yy,mm,id_region,id_org");
        if (!$rows || count($rows) <= 0) {
            $i = 'Requests command no data found for all period!';
            $this->info($i);
            return;
        }
        \DB::table('idx_real_dayli_by_orgs')->truncate();
        foreach($rows as $r)
            $this->RequestsOne($r);
    }

    private function RequestsOne($r) {
        \DB::beginTransaction();
        try {
            \DB::table('idx_real_dayli_by_orgs')->insert([
                'dt' => $r->dt, 'yy' => $r->yy, 'mm' => $r->mm,
                'id_region' => $r->id_region, 'id_org' => $r->id_org,
                'amount_total' => $r->amount_total, 'amount_real' => $r->amount_real,
                'total_qty' => $r->total_qty, 'real_qty' => $r->real_qty, 'back_qty' => $r->back_qty
            ]);
            \DB::commit();
            $i = 'Requests command for: ' .$r->dt . '-' . $r->id_org . ' OK';
            $this->info($i);
        }
        catch(Exception $ex) {
            $i = 'Requests command error: ' . $ex->getMessage() . ' Rollback data!';
            \Log::error($i); $this->error($i);
            \DB::rollback();
        }
    }
}
