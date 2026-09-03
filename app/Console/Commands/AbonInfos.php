<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AbonInfos extends Command
{

    protected $signature = 'abon:info';

    protected $description = 'Write information about abonents to tb_abon_deposits table';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Init statistic details started!');
        $started_time = microtime(true);

        try {

            $dt = date('Y-m-d');
            \DB::table('i_rekvizits')->where('dt', $dt)->delete();

            $i = 'Abonent infos command launched for: ' . $dt;
            $this->info($i);

            $regions = \DB::table('regions')->where('id', '!=', 15)->get();
            foreach ($regions as $item) {
                /*$rows = \DB::connection('mysql1')->table('cms_users as u')->join('districts as d', 'd.id', '=', 'u.id_district')
                    ->leftJoin('idx_abonent_changes as i', 'u.id', '=', 'i.id_abonent')
                    ->where('u.id_region', $item->id)
                    ->where('u.id_cms_privileges', 4)
                    ->select('d.id_region', 'd.id as id_district', \DB::raw('count(*) as count'), \DB::raw("sum(if(u.status = 'Active', 1, 0)) as is_active"), \DB::raw("sum(if(u.status = 'Deactive', 1, 0)) as is_deactive"), \DB::raw('sum(if(u.pinfl is not null, 1, 0)) as with_pinfl'), \DB::raw('sum(if(i.module = "pinfl", 1, 0)) as change_pinfl'), \DB::raw('sum(if(u.psp is not null, 1, 0)) as with_psp'), \DB::raw('sum(if(i.module = "psp", 1, 0)) as change_psp'), \DB::raw('sum(if(u.mobile is not null, 1, 0)) as with_mobile'), \DB::raw('sum(if(i.module = "mobile", 1, 0)) as change_mobile'), \DB::raw('sum(if(u.kadastr is not null, 1, 0)) as with_kadastr'), \DB::raw('sum(if(i.module = "kadastr", 1, 0)) as change_kadastr'))
                    ->groupBy('u.id_region', 'u.id_district')
                    ->get();*/

                $sql = "select d.id_region, d.id as id_district, count(distinct u.id) as count, count(distinct if(u.status = 'Active', u.id, null)) as is_active, count(distinct if(u.status = 'Deactive', u.id, null)) as is_deactive, count(distinct if(u.status = 'Active' and u.pinfl is not null, u.id, null)) as with_pinfl, sum(if(u.status = 'Active' and i.module = 'pinfl', 1, 0)) as change_pinfl, count(distinct if(u.status = 'Active' and u.psp is not null, u.id, null)) as with_psp, sum(if(u.status = 'Active' and i.module = 'psp', 1, 0)) as change_psp, count(distinct if(u.status = 'Active' and u.mobile is not null, u.id, null)) as with_mobile, sum(if(u.status = 'Active' and i.module = 'mobile', 1, 0)) as change_mobile, count(distinct if(u.status = 'Active' and u.kadastr is not null, u.id, null)) as with_kadastr, sum(if(u.status = 'Active' and i.module = 'kadastr', 1, 0)) as change_kadastr, count(distinct if(u.status = 'Active' and u.is_nfc = 1, u.id, null)) as with_nfc, sum(if(u.status = 'Active' and i.module = 'nfc', 1, 0)) as change_nfc from cms_users as u join districts as d on d.id = u.id_district left join (select module, id_abonent from idx_abonent_changes group by module, id_abonent) as i on u.id = i.id_abonent where u.id_region = {$item->id} and u.id_cms_privileges = 4 group by u.id_region, u.id_district";

                $rows = \DB::connection('mysql1')->select($sql);

                foreach ($rows as $row) {
                    $details = [
                        'id_region' => $row->id_region,
                        'id_district' => $row->id_district,
                        'all_qty' => $row->count,
                        'is_active' => $row->is_active,
                        'is_deactive' => $row->is_deactive,
                        'with_pinfl' => $row->with_pinfl,
                        'change_pinfl' => $row->change_pinfl,
                        'with_psp' => $row->with_psp,
                        'change_psp' => $row->change_psp,
                        'with_mobile' => $row->with_mobile,
                        'change_mobile' => $row->change_mobile,
                        'with_kadastr' => $row->with_kadastr,
                        'change_kadastr' => $row->change_kadastr,
                        'dt' => $dt
                    ];
                    \DB::table('i_rekvizits')->insert($details);
                    $this->info('Region: '.$item->id.' District: '.$row->id_district);
                }
            }

            \Log::info('Abonent infos command finished!');
        } catch (\Exception $ex) {
            \Log::error('Abonent infos command: ' . $ex->getMessage());
        }

        $this->info('Init statistics finished!' . ' Time: ' . (microtime(true) - $started_time));
    }

}
