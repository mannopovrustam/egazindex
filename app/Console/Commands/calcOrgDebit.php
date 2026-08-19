<?php

namespace App\Console\Commands;

use App\Jobs\OrgDebit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Log;


class calcOrgDebit extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'haqdorlik {arg}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculating Organization\'s debit';

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
            if ($this->argument('arg') == 'latest') {
                $dt = now()->subMonth()->format('Y-m');
                Log::info('Recalculate all latest month data: '. $dt);
                $this->RecalculationDate($dt);
                return;
            }
            if ($this->argument('arg') == date('Y-m', strtotime($this->argument('arg')))) {
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
        $period = \Carbon\CarbonPeriod::create('2020-01-01', now()->subMonth())->month();
        $months = collect($period)->map(function (\Carbon\Carbon $date) {
            return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $date)->format('Y-m');
        })->toArray();

        $users = DB::connection('pgsql1')->table('cms_users')->where('id_cms_privileges', 4)->select('kod', 'name', 'id_org', 'id_mahalla')->get();

        DB::table('i_deposit_orgs')->truncate();
        DB::table('i_deposit_details')->truncate();

        foreach ($months as $dt){
            $yy = explode("-", $dt)[0];
            $mm = explode("-", $dt)[1];
            Log::info($dt . ' ' . $mm . ' ' . $yy);

            $started = microtime(true);
            $i = 'Recalucalation command launched for: ' . $dt;
            Log::info($i);
            $this->info($i);
            foreach ($users as $user) {
                OrgDebit::dispatch($mm, $yy, $user);
            }
            $elapsed =  (microtime(true) - $started);
            $this->info('Calced date: ' . $dt .' elapsed '. $elapsed . 'secs.');
        }
    }
    private function RecalculationDate($dt)
    {
        $yy = explode("-", $dt)[0];
        $mm = explode("-", $dt)[1];
        Log::info($dt . ' ' . $mm . ' ' . $yy);

        $started = microtime(true);
        $i = 'Recalucalation command launched for: ' . $dt;
        Log::info($i);
        $this->info($i);
        DB::table('i_deposit_orgs')->where('yy', $yy)->where('mm', $mm)->delete();
        DB::table('i_deposit_details')->where('yy', $yy)->where('mm', $mm)->delete();

        $users = DB::connection('pgsql1')->table('cms_users')->where('id_cms_privileges', 4)->select('kod', 'name', 'id_org', 'id_mahalla')->get();
        foreach ($users as $user) {
            OrgDebit::dispatch($mm, $yy, $user);
        }
        $elapsed =  (microtime(true) - $started);
        $this->info('Calced date: ' . $dt .' elapsed '. $elapsed . 'secs.');
    }
}
