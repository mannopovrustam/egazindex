<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 08.02.2023
 * Time: 12:17
 */

namespace App\Actions;


class IReal
{
    public function handle($rows) {
        $details = [];
        foreach ($rows as $rb) {
            $user = null;
            $INSP = null;
            $rb = json_decode(json_encode($rb, JSON_UNESCAPED_UNICODE));
            $cms_user = \DB::connection('pgsql1')->table('cms_users as u')
                ->join('organizations as o', 'o.id', '=', 'u.id_org')
                ->select('u.name', 'u.kod', 'u.address', 'u.mobile', 'u.id_mahalla', 'u.id_org', 'o.name as org_name')->where('u.id', $rb->id_abonent)->first();
            if (!$cms_user) {

                \DB::table('i_real_failed')->insert([
                    'id_tran' => $rb->id,
                    'id_abonent' => $rb->id_abonent,
                    'id_ballon' => $rb->id_ballon,
                    'id_org' => $rb->id_raygas,
                    'err' => 'Can not find user by id: '.$rb->id_abonent,
                ]);
                continue;

            }

            $user['id_org'] = $cms_user->id_org;
            $user['abonent_kod'] = $cms_user->kod;
            $user['org_name'] = $cms_user->org_name;
            $user['abonent_name'] = $cms_user->name;
            $user['abonent_address'] = $cms_user->address;
            $user['id_mahalla'] = $cms_user->id_mahalla ?? 8740;
            $user['abonent_phone'] = $cms_user->mobile;

            $inspector = \DB::connection('pgsql1')->table('cms_users as u')->select('u.name', 'u.kod')->where('u.id', $rb->passed_by)->first();
            if (!$inspector) {
                $INSP['inspector_kod'] = '01001000001';
                $INSP['inspector_name'] = 'НЕИЗВЕСТНО!';
            } else {
                $INSP['inspector_kod'] = $inspector->kod;
                $INSP['inspector_name'] = $inspector->name;
            }
            $ballon = \DB::connection('pgsql1')->table('tb_balons')->select('kod')->where('id', $rb->id_ballon)->first();
            if (!$ballon) {
                //$i = 'Realization command: BallonID: ' . $rb->id_ballon . ' no found!';
                \DB::table('i_real_failed')->insert([
                    'id_tran' => $rb->id,
                    'id_abonent' => $rb->id_abonent,
                    'id_ballon' => $rb->id_ballon,
                    'id_org' => $rb->id_raygas,
                    'err' => 'Can\'t find ballon by id: '.$rb->id_ballon,
                ]);
                continue;
            }
            try {
                \DB::beginTransaction();
                $details = [
                    'dt' => $rb->dt,
                    'yy' => date('Y', strtotime($rb->dt)),
                    'id_org' => $user['id_org'],
                    'id_mah' => $user['id_mahalla'],
                    'ballon_kod' => $ballon->kod,
                    'amount' => $rb->price,
                    'real_at' => $rb->passed_at,
                    'inspector_kod' => $INSP['inspector_kod'],
                    'abonent_kod' => $user['abonent_kod'],
                    'inspector_name' => $INSP['inspector_name'],
                    'abonent_name' => $user['abonent_name'],
                    'abonent_address' => $user['abonent_address'],
                    'abonent_phone' => $user['abonent_phone'],
                    'numb' => $rb->numb,
                    'location_lon' => $rb->location_lon,
                    'location_lat' => $rb->location_lat,
                ];

                // DB triggerlari (bitta INSERT da ikkalasi ham yonadi):
                //   `insert_i_real_details` → i_real_orgs upsert (amount_total, total_qty)
                //   `minus_deposit_orgs`    → organizations.deposit -= amount
                // Bayroqlar o'chiq bo'lsa aynan eski kod kabi bitta INSERT.
                if (\App\Services\DbTriggers\TriggerBus::insert('i_real_details', $details)) {
                    \Log::debug('Calc real: ' .$user['abonent_kod'] . ' -> ' .$ballon->kod . ': ' . $rb->passed_at);
                    \DB::commit();
                } else {
                    \DB::rollback();
                    \DB::table('i_real_failed')->insert([
                        'id_tran' => $rb->id,
                        'id_abonent' => $rb->id_abonent,
                        'id_ballon' => $rb->id_ballon,
                        'id_org' => $rb->id_raygas,
                        'err' => 'Can\'t insert data to table!',
                    ]);
                }
            } catch (\Exception $ex) {
                \DB::rollback();
                \DB::table('i_real_failed')->insert([
                    'id_tran' => $rb->id,
                    'id_abonent' => $rb->id_abonent,
                    'id_ballon' => $rb->id_ballon,
                    'id_org' => $rb->id_raygas,
                    'err' => $ex->getMessage(),
                ]);
            }
        }
    }
}