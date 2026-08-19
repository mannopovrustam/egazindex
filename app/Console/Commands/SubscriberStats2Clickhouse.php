<?php

namespace App\Console\Commands;

use App\Services\ClickhouseService;
use Illuminate\Console\Command;

class SubscriberStats2Clickhouse extends Command
{
    protected $signature = 'subscriber-stats {type} {value}';
    protected $description = 'MySQL tb_amending dan status o\'zgarishlarni ClickHouse ga indeksatsiya qilish (tb_subscriber_daily_status + tb_subscriber_geo_daily_stats)';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        try {
            $type = $this->argument('type');
            $value = $this->argument('value');

            if (!in_array($type, ['date', 'range', 'year'])) {
                $this->error('Invalid type! Use: date, range, year');
                return;
            }

            $dates = $this->resolveDates($type, $value);

            if (empty($dates)) {
                $this->error('No dates resolved!');
                return;
            }

            $this->info('Starting subscriber stats indexation...');
            $this->info('Total dates to process: ' . count($dates));

            $totalDaily = 0;
            $totalGeo = 0;

            foreach ($dates as $d) {
                $start_time = microtime(true);

                // 0-qadam: Yangi abonentlarni ClickHouse cms_users ga sinxronlash (faqat 2026-03-21 dan keyin)
                if (strtotime($d) > strtotime('2026-03-21')) {
                    $this->info("[{$d}] Step 0: Syncing new subscribers to ClickHouse cms_users...");
                    $syncedRows = ClickhouseService::syncNewSubscribers($d);
                    $this->info("[{$d}] Synced new subscribers: {$syncedRows}");
                }

                // 1-qadam: MySQL tb_amending -> CH tb_amending -> CH tb_subscriber_daily_status
                $this->info("[{$d}] Step 1: MySQL tb_amending -> CH tb_amending -> CH tb_subscriber_daily_status...");
                $dailyRows = ClickhouseService::subscriberDailyStatus($d);
                $this->info("[{$d}] Daily status rows: {$dailyRows}");
                $totalDaily += $dailyRows;

                // 2-qadam: ClickHouse tb_subscriber_daily_status + cms_users -> tb_subscriber_geo_daily_stats
                $this->info("[{$d}] Step 2: tb_subscriber_daily_status -> tb_subscriber_geo_daily_stats...");
                $geoRows = ClickhouseService::subscriberGeoStats($d);
                $this->info("[{$d}] Geo stats rows: {$geoRows}");
                $totalGeo += $geoRows;

                $elapsed = round(microtime(true) - $start_time, 2);
                $this->info("[{$d}] Done! Elapsed: {$elapsed} sec");
                $this->info('---');
            }

            $this->info("=== FINISHED ===");
            $this->info("Total daily status rows: {$totalDaily}");
            $this->info("Total geo stats rows: {$totalGeo}");
            $this->info("Dates processed: " . count($dates));

        } catch (\Exception $ex) {
            $this->error('Error: ' . $ex->getMessage() . ' at ' . $ex->getFile() . ':' . $ex->getLine());
            \Log::error('subscriber-stats error: ' . $ex->getMessage());
        }
    }

    /**
     * Sanalarni aniqlash
     */
    private function resolveDates($type, $value)
    {
        $dates = [];

        switch ($type) {
            case 'date':
                if ($value === 'current' || $value === 'today') {
                    $dates = [date('Y-m-d')];
                } elseif ($value === 'yesterday') {
                    $dates = [date('Y-m-d', strtotime('-1 day'))];
                } else {
                    $dates = [$value];
                }
                break;

            case 'range':
                // Format: 2026-01-01_2026-03-28
                $parts = explode('_', $value);
                if (count($parts) !== 2) {
                    $this->error('Range format: YYYY-MM-DD_YYYY-MM-DD');
                    return [];
                }
                $start = strtotime($parts[0]);
                $end = strtotime($parts[1]);
                if ($start > $end) {
                    $this->error('Start date must be before end date!');
                    return [];
                }
                for ($i = $start; $i <= $end; $i = strtotime('+1 day', $i)) {
                    $dates[] = date('Y-m-d', $i);
                }
                break;

            case 'year':
                $startDate = strtotime("first day of January {$value}");
                $endDate = strtotime(date('Y') == $value ? date('Y-m-d') : "last day of December {$value}");
                for ($i = $startDate; $i <= $endDate; $i = strtotime('+1 day', $i)) {
                    $dates[] = date('Y-m-d', $i);
                }
                break;
        }

        return $dates;
    }
}
