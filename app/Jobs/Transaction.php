<?php

namespace App\Jobs;

use App\Actions\IMoneyDebit;
use DB;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class Transaction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($payload)
    {
        $this->data = $payload;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (env('JOBS_DISABLE', false)) return;
        //$row = \DB::connection('mysql1')->table('tb_gas_debit')->where('sys_bid', $id)->first();
        $start = microtime(true);
        if (!isset($this->data['hash']) || !isset($this->data['method']) || !isset($this->data['action']) || !isset($this->data['tr'])) {
            \Log::error($this->data['tr']);
            throw new Exception('Wrong input parameters!', 103);
        }
        #region Latta
        $tr = json_decode($this->data['tr'], true);
        //\Log::debug($tr);
        //$tr = DB::connection('mysql1')->table('tb_gas_debit')->where('sys_bid', $tr['sys_bid'])->first();
        //\Log::debug('Got transaction from 1st server by sys_bid: ' . (microtime(true)-$start) . ' sec.');

        $start = microtime(true);
        (new IMoneyDebit())->handle([$tr], $tr['dt_pay'], false);
        \Log::debug($tr['sys_bid'] . ' -> OK,' . (microtime(true) - $start) . ' sec.');
    }

    /*private function AddTransaction($tr) {
        if (!in_array(trim($tr['supplier']), ['paynet', '0112', 'apelsin','hgt'])) return "Wrong provider: " . $tr['supplier'];
        $user = \DB::connection('mysql1')->table('cms_users as u')->leftjoin('mahallas as m','m.id','=','u.id_mahalla')
            ->select(\DB::raw("IFNULL(m.name,u.mahalla) as mahalla"),'u.name','u.kod','u.psp','u.deposit','u.address','u.mobile','u.id_mahalla')->where('u.id', $tr['id_abonent'])->first();
        $org = \DB::table('organizations')->select('id','id_region')->where('id', $tr['payee_id'])->first();
        if (!$user) throw new Exception('Unkowen Abonent ID!',102);
        if(!$user->name) $user->name = $user->kod;

        if (!$org || !$org->id_region) throw new Exception('Unkowen Organization ID!',102);

        $details = [
            'dt' => $tr['dt_pay'], 'yy' => date('Y', strtotime($tr['dt_pay'])),
            'amount' => $tr['amount'], 'id_org' => $org->id,
            'provider' => 'munis', 'paid_at' => $tr['confirmed_at'],
            'sys_bid' => $tr['sys_bid'], 'kod' => $user->kod, 'name' => $user->name,
            'psp' => $user->psp, 'mahalla' => $user->mahalla, 'id_mahalla' => $user->id_mahalla,
            'mobile' => $user->mobile, 'address' => $user->address, 'deposit' => $user->deposit,
        ];
        $dt = $tr['dt_pay'];
        $yy = date('Y', strtotime($tr['dt_pay']));
        $mm = date('n', strtotime($tr['dt_pay']));
        $amount = $tr['amount']; $sql ='';
        $sql = null;
        if ($tr['supplier'] == 'paynet') {
            $details['provider'] = 'paynet';
            $sql = "INSERT INTO `idx_dayli_by_orgs` (dt,yy,mm,id_region,id_org,amount_paynet,qty_paynet) VALUES ('$dt', $yy, $mm, {$org->id_region}, {$org->id}, $amount,1) ON DUPLICATE KEY UPDATE amount_paynet=amount_paynet+$amount, qty_paynet=qty_paynet+1";
        }
        if ($tr['supplier'] == 'hgt') {
            $details['provider'] = 'hgt';
            $sql = "INSERT INTO `idx_dayli_by_orgs` (dt,yy,mm,id_region,id_org,amount_hgt,qty_hgt) VALUES ('$dt', $yy, $mm, {$org->id_region}, {$org->id}, $amount,1) ON DUPLICATE KEY UPDATE amount_hgt=amount_hgt+$amount, qty_hgt=qty_hgt+1";
        }
        if ($tr['supplier'] == 'apelsin') {
            $details['provider'] = 'apelsin';
            $sql = "INSERT INTO `idx_dayli_by_orgs` (dt,yy,mm,id_region,id_org,amount_apelsin,qty_apelsin) VALUES ('$dt', $yy, $mm, {$org->id_region}, {$org->id}, $amount,1) ON DUPLICATE KEY UPDATE amount_apelsin=amount_apelsin+$amount, qty_apelsin=qty_apelsin+1";
        }
        if ($tr['supplier'] == '0112') {
            switch(trim($tr['payer_inn'])) {
                case '302134733':
                    $details['provider'] = 'click';
                    $sql = "INSERT INTO `idx_dayli_by_orgs` (dt,yy,mm,id_region,id_org,amount_click,qty_click) VALUES ('$dt', $yy, $mm, {$org->id_region}, {$org->id}, $amount,1) ON DUPLICATE KEY UPDATE amount_click=amount_click+$amount, qty_click=qty_click+1";
                    break;
                case '302050181':
                    $details['provider'] = 'payme';
                    $sql = "INSERT INTO `idx_dayli_by_orgs` (dt,yy,mm,id_region,id_org,amount_payme,qty_payme) VALUES ('$dt', $yy, $mm, {$org->id_region}, {$org->id}, $amount,1) ON DUPLICATE KEY UPDATE amount_payme=amount_payme+$amount, qty_payme=qty_payme+1";
                    break;
                default:
                    $details['provider'] = 'munis';
                    $sql = "INSERT INTO `idx_dayli_by_orgs` (dt,yy,mm,id_region,id_org,amount_munis,qty_munis) VALUES ('$dt', $yy, $mm, {$org->id_region}, {$org->id}, $amount,1) ON DUPLICATE KEY UPDATE amount_munis=amount_munis+$amount, qty_munis=qty_munis+1";
            }
        }
        if ($sql) {
            \DB::unprepared(\DB::raw($sql));
            \DB::table('idx_dayli_by_orgs_details')->insert($details);
            $sql = "UPDATE `organizations` SET deposit=deposit+$amount where id={$org->id};";
            \DB::unprepared(\DB::raw($sql));
            return 'OK';
        }
    }*/
}
