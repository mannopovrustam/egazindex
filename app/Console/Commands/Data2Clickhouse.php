<?php

namespace App\Console\Commands;

use App\Services\ClickhouseService;
use Illuminate\Console\Command;
use ClickHouseDB\Client;
class Data2Clickhouse extends Command {
    protected $signature = 'data2clickhouse {data} {type} {value}';
    protected $description = 'send debit data to clickhouse';

    public function __construct() {parent::__construct();}

    public function handle() {
        try {
            $this->info('Starting...');
            if (!in_array($this->argument('data'), ['amending'])) {
                $this->error('Invalid type!');
                return;
            }
            if (!in_array($this->argument('type'), ['date', 'year'])) {
                $this->error('Invalid type!');
                return;
            }

            $data = $this->argument('data');
            $type = $this->argument('type');
            $value = $this->argument('value');

            switch ($data) {
                case 'amending':
                    if ($type == 'date' and $value != 'current') $dates = [$value];
                    else if ($type == 'date' and $value == 'current') $dates = [date('Y-m-d', strtotime('-1 day'))];
                    else if ($type == 'year') $dates = $this->getDatesInYear($value);
                    else $dates = [];

                    foreach ($dates as $d) {
                        $start_time = microtime(true);
                        $this->info('Inserting data to clickhouse cl_'.$data.' table for date: ' . $d);
                        ClickhouseService::amending($d);
                        $this->info('Data inserted to clickhouse cl_'.$data.' table! Elapsed: ' . (microtime(true) - $start_time) . ' sec' . ' Rows: ');
                    }
                    break;
            }
        }
        catch (\Exception $ex) {
            $this->error('Calc deposit error: ' . $ex->getMessage());
        }
    }

    function getDatesInYear($year) {
        $dates = [];
        $startDate = strtotime("first day of January $year");
        $endDate = strtotime("last day of December $year");
        if($year == '2025') {
            $endDate = strtotime(date('Y-m-d'), strtotime('-1 day'));
        }

        // Loop through each day of the year
        while ($startDate <= $endDate) {
            $dates[] = date("Y-m-d", $startDate); // Add formatted date to array
            $startDate = strtotime("+1 day", $startDate); // Move to next day
        }

        return $dates;
    }

}
