<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 01.02.2023
 * Time: 14:31
 */

namespace App\Actions;


use DB;
use Illuminate\Console\Command;
use Log;
use App\Services\DualWrite\CounterUpsert;

class IMoneyDebit
{
    private function handleErr($tr, $err) {
        try {
            DB::table('i_money_failed')->insert([
                'id_tran' => $tr->id,
                'id_abonent' => $tr->id_abonent,
                'kod' => $tr->sys_tid,
                'id_org' => $tr->payee_id,
                'err' => $err
            ]);
            Log::error('Failed indexing transaction: ' . $tr->sys_bid);
        } catch (\Exception $e){
            Log::error($e->getMessage());
        }
    }

    // PHP 8: majburiy parametrdan oldin standart qiymatli parametr deprecated —
    // barcha chaqiruvchilar $gasDebits ni doim beradi, shuning uchun `= []` olib tashlandi.
    public function handle($gasDebits, $dt_pay, $computeDepositClient = true)
    {
        $provider = new FindProvider();
        $errors = 0; $details = null; $inserted = 0;
        foreach ($gasDebits as $tr){
            $tr = (object)$tr;
            try {
                DB::beginTransaction();
                $start = microtime(true);
                if (!in_array(strtolower(trim($tr->supplier)), ['click', 'paynet', '0112', 'hgt','apelsin','payme'])) {
                    DB::rollback();
                    $this->handleErr($tr, 'wrong supplier! ' . $tr->supplier);
                    $errors++;
                    continue;
                }

                $org = DB::connection('pgsql1')->table('organizations')->select('id', 'id_region')->where('id', $tr->payee_id)->first();
                if (!$org || !isset($org->id) || !$org->id_region) {
                    $errors++;
                    DB::rollback();
                    $this->handleErr($tr,'Unkowen OrgID: ' . $tr->payee_id);
                    continue;
                }

                $user = DB::connection('pgsql1')->table('cms_users as u')->select(DB::raw("u.id,u.name,u.kod,u.psp,u.deposit,u.address,u.mobile,u.id_mahalla"))->where('u.kod',$tr->sys_tid)->first();

                if (!$user || !isset($user->id) || !$user->kod) {
                    DB::rollback();
                    $this->handleErr($tr,'Unkowen AbonentID: ' . $tr->id_abonent . ' / ' . $tr->sys_tid);
                    $errors++;
                    continue;
                }
                if ($computeDepositClient)
                    $deposit = $provider->calcDebosit($user->id, $dt_pay);
                else
                    $deposit = $user->deposit;

                $pr = $provider->provider($tr->supplier, $tr->payer_inn);
                $mah = $user->id_mahalla ?? 8740; // no mahalla if null
                $details = [
                    'dt' => $tr->dt_pay,
                    'yy' => date('Y', strtotime($tr->dt_pay)),
                    'amount' => $tr->amount,
                    'id_org' => $org->id,
                    'provider' => $pr,
                    'paid_at' => $tr->confirmed_at ?? date('Y-m-d H:i:s',time()),
                    'sys_bid' => $tr->sys_bid,
                    'kod' => $user->kod,
                    'name' => $user->name,
                    'psp' => $user->psp,
                    'id_mah' => $mah,
                    'mobile' => $user->mobile,
                    'address' => $user->address,
                    'deposit' => $deposit,
                    'payer_branch' => $tr->payer_branch ?? 'XXXXX',
                    'payer_account' => $tr->payer_account ?? 'XXXXXX',
                    'payer_name' => $tr->payer_name,
                    'payer_inn' => empty($tr->payer_inn) ? 'XXXXXXXXX' : $tr->payer_inn,
                ];

                $amount = $tr->amount;
                $p = strtolower($details['provider']);


                // DB trigger `plus_deposit_org` (AFTER INSERT) — organizations.deposit += amount.
                // ⚠ Quyidagi xom SQL `i_money_orgs` ni QO'LDA yangilaydi — u trigger emas,
                //   shuning uchun teginilmadi. docs/db-triggers-to-service-migration.md
                if (\App\Services\DbTriggers\TriggerBus::insert('i_money_details', $details)) $inserted++;
                $yy = date('Y', strtotime($dt_pay));
                $mm = date('n', strtotime($dt_pay));

                // Hisoblagichli upsert — xom SQL so'rov quruvchisidan o'tmaydi,
                // shuning uchun CounterUpsert ishlatiladi:
                //   asosiy (MySQL): ON DUPLICATE KEY UPDATE
                //   nusxa    (PG):  ON CONFLICT (dt, yy, id_mah, id_org) DO UPDATE
                // Ikkala SQL ni u o'zi quradi va nusxani ham o'zi yozadi.
                // Konfliktda: amount_$p += amount, deposit += deposit, qty_$p += 1.
                CounterUpsert::run('i_money_orgs', [
                    'provider'  => $pr,
                    'id_mah'    => $mah,
                    'deposit'   => $deposit,
                    'dt'        => $tr->dt_pay,
                    'yy'        => $yy,
                    'mm'        => $mm,
                    'id_reg'    => $org->id_region,
                    'id_org'    => $org->id,
                    "amount_$p" => $amount,
                    "qty_$p"    => 1,
                ], ['dt', 'yy', 'id_mah', 'id_org'], ["amount_$p", 'deposit', "qty_$p"]);
                DB::commit();
                Log::debug('Inserted into indexed ImoneyTable ' . $tr->sys_bid . ' in ' . (microtime(true) - $start) . ' sec.' );
                //Log::debug("Indexed: $tr->sys_bid");
            } catch (\Exception $e){
                DB::rollback();
                $this->handleErr($tr,$e->getMessage());
                $errors++;
            }
        }
        Log::debug("CalcDebit for $dt_pay / inserted: $inserted / errors: $errors");
    }
}