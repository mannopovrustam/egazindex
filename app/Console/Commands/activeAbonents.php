<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class activeAbonents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:abonents';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check active or disabled abonents';

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
     * @return mixedegaz_db
     */
    public function handle()
    {

        ini_set('memory_limit', '1500M');
        // TRUNCATE MySQL da triggerlarni UYG'OTMAYDI — shuning uchun bu yerda
        // TriggerBus kerak emas. Ikkala jadval birga tozalanadi va quyidagi sikl
        // ularni qaytadan quradi (i_abonent_orgs ni `i_abonent_details_ai` to'ldiradi).
        \DB::table('i_abonent_details')->truncate();
        \DB::table('i_abonent_orgs')->truncate();
        $this->info('Now loading data...');
        $start = microtime(true);
        $this->info('Start time: ' . date('H:i:s'));

        $organizations = \DB::connection('mysql1')->table('organizations')->select('id', 'name')->where('orgtype_id', 4)->get();

        $ALL_ORGS = 0;
        foreach ($organizations as $orgs){
            $ALL_ORGS++;
            $users = \DB::connection('mysql1')->table('cms_users')->select('kod', 'name', 'id_mahalla', 'created_at', 'status')->where('id_cms_privileges',4)->where('id_org', $orgs->id)->get();
            $this->info('Organization: ' . $orgs->name . ' has ' . count($users) . ' abonents.');
            $ALL_ABONENTS = 0;
            foreach ($users as $user) {
                $detail = [
                    'id_org' => $orgs->id,
                    'id_mah' => $user->id_mahalla ?? 8740,
                    'abonent_kod' => $user->kod,
                    'abonent_name' => $user->name,
                    'dt_creation' => date('Y-m-d', strtotime($user->created_at)),
                    'status' => $user->status == 'Active',
                ];
                // DB trigger `i_abonent_details_ai` (AFTER INSERT) —
                // i_abonent_orgs upsert: all_users+1 (status rost bo'lsa active_users+1)
                if(\App\Services\DbTriggers\TriggerBus::insert('i_abonent_details', $detail)) $ALL_ABONENTS++;
                else $this->error('Failed to add abonent details.' . $user->kod);
            }
            $this->info('Inserted ' . $ALL_ABONENTS . ' rows successfully.');

        }
        $end = microtime(true);
        $time = number_format(($end - $start), 2);
        $this->info('End time: ' . date('H:i:s') . "\n" . 'Time: ' . $time . ' sec' . "\n");
        $this->info('Inserted ' . $ALL_ORGS . ' organizations successfully.');

    }
}
