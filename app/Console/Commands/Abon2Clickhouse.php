<?php

namespace App\Console\Commands;

use App\Services\ClickhouseService;
use Illuminate\Console\Command;
use ClickHouseDB\Client;
class Abon2Clickhouse extends Command {
    protected $signature = 'abonent2clickhouse';
    protected $description = 'send abonent data to clickhouse';

    public function __construct() {parent::__construct();}

    public function handle() {
        try {
            $this->info('Starting...');
            $elapsed_time_start = microtime(true);

            $organizations = \DB::table('organizations')->where('orgtype_id', 4)->get();
            if (!$organizations) {
                $this->error('No organizations found!');
                return;
            }
            foreach ($organizations as $organization) {
                $start_time = microtime(true);
                $this->info('Inserting data to clickhouse cl_users table for ORG_ID: ' . $organization->id);
                $affected = ClickhouseService::abonent($organization->id);
                $this->info('Data inserted to clickhouse cl_gas_abonent table! Elapsed: ' . (microtime(true) - $start_time) . ' sec' . ' Rows: ' . $affected);
            }

            $this->info('Done! Elapsed: ' . (microtime(true) - $elapsed_time_start) . ' sec');
        }
        catch (\Exception $ex) {
            $this->error('Calc deposit error: ' . $ex->getMessage() . ' ' . $ex->getFile() . ':' . $ex->getLine());
        }
    }


}
