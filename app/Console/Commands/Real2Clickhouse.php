<?php

namespace App\Console\Commands;

use App\Services\ClickhouseService;
use Illuminate\Console\Command;
use ClickHouseDB\Client;
class Real2Clickhouse extends Command {
    protected $signature = 'real2clickhouse {type} {value}';
    protected $description = 'send debit data to clickhouse';

    public function __construct() {parent::__construct();}

    public function handle() {
        try {
            $this->info('Starting...');
            if (!in_array($this->argument('type'), ['year', 'date', 'amend', 'optimize'])) {
                $this->error('Invalid type!');
                return;
            }

            $type = $this->argument('type');
            $value = $this->argument('value');

            switch ($type) {
                case 'year':
                case 'date':
                    // get all days in this 2024 year
                    $year = $value;
                    if ($type == 'date' and $value == 'current') $dates = [date('Y-m-d', strtotime('-1 day'))];
                    if ($type == 'date' and $value != 'current') $dates = [date('Y-m-d', strtotime($value))];
                    if ($type == 'year') $dates = $this->getDatesInYear($year);

                    foreach ($dates as $date) {
                        $start_time = microtime(true);
                        $this->info('Inserting data to clickhouse cl_realization table for date: ' . $date);
                        $affected = ClickhouseService::real($date);
                        $this->info('Data inserted to clickhouse cl_realization table! Elapsed: ' . (microtime(true) - $start_time) . ' sec' . ' Rows: ' . $affected);
                    }
                    break;

                case 'optimize':
                    ClickhouseService::optimize('cl_realization');
                    $this->info('Table cl_realization optimized!');
                    break;
            }
        }
        catch (\Exception $ex) {
            $this->error('Real2Clickhouse error: ' . $ex->getMessage() . ' ' . $ex->getTraceAsString());
        }
    }

    function getDatesInYear($year) {
        $dates = [];
        $startDate = strtotime("first day of January $year");
        $endDate = strtotime("last day of December $year");
        if($year == '2025') {
            $endDate = strtotime(date('Y-m-d'), strtotime('-1 day'));
//            $endDate = strtotime('2024-12-16');
            //$startDate = strtotime("first day of December $year");
            // yesterday
            //$endDate = strtotime(date('Y-m-d', strtotime('-2 day')));
        }
        // Loop through each day of the year
        while ($startDate <= $endDate) {
            $dates[] = date("Y-m-d", $startDate); // Add formatted date to array
            $startDate = strtotime("+1 day", $startDate); // Move to next day
        }

        return $dates;
    }

}
