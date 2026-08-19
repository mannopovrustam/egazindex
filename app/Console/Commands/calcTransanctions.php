<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Exception;

class calcTransanctions extends Command {
    protected $signature = 'calcTransactions {arg}';
    protected $description = 'Manual recalculating transactions';
    public function __construct() {parent::__construct();}

    public function handle() {
        try {
            if ($this->argument('arg') == 'full') {
                \Log::info('Recalculate all data');
                $this->RecalculationAll();
                return;
            }
            if ($this->argument('arg') == 'fullw') {
                \Log::info('Recalculate all data');
                $this->RecalculationAll(false);
                return;
            }
            if ($this->argument('arg') == 'deposit') {
                \Log::info('Recalculate deposit of orgs data');
                $this->RecalculateDeposit();
                return;
            }
            if (!$this->argument('arg')) {
                \Log::error('Wrong argument!');
                $this->error('Wrong argument!');
                return;
            }
            $this->RecalculationDate($this->argument('arg'));
            \Log::info('Recalucalation command finished!');
        }
        catch (\Exception $ex) {
            \Log::error('Recalculation command: ' . $ex->getMessage());
        }
    }

    private function RecalculationDate($dt, $forceDetails = true) {
        $i = 'Recalucalation command launched for: ' . $dt;
        \Log::info($i); $this->info($i);

        \DB::table('idx_dayli_by_orgs')->where('dt', $dt)->delete();
        if ($forceDetails) \DB::table('idx_dayli_by_orgs_details')->where('dt', $dt)->delete();
        $rows = \DB::connection('pgsql1')->table('tb_gas_debit')->where('dt_pay', $dt)->get();
        if (!$rows || count($rows) <= 0) {
            $i = 'Recalc command no data found for ' . $dt;
            \Log::info($i); $this->info($i);
            return;
        }
        \DB::beginTransaction();
        try {
            $details = null;
            foreach ($rows as $tr) {
                if (!in_array(trim($tr->supplier), ['paynet', '0112', 'hgt','apelsin'])) {
                    $i = 'Recalc command: wrong supplier! ' . $tr->supplier;
                    \Log::info($i);
                    $this->info($i);
                    continue;
                }

                $org = \DB::table('organizations')->select('id', 'id_region')->where('id', $tr->payee_id)->first();
                if (!$org || !$org->id_region) {
                    $i = 'Recalc command: Unkowen OrgID!';
                    \Log::info($i); $this->info($i);
                    continue;
                }

                if ($forceDetails) {
                    $user = \DB::connection('pgsql1')->table('cms_users as u')->leftjoin('mahallas as m', 'm.id', '=', 'u.id_mahalla')->select(\DB::raw("COALESCE(u.name,u.mahalla) as mahalla"), 'u.name', 'u.kod', 'u.psp', 'u.deposit', 'u.address', 'u.mobile', 'u.id_mahalla')->where('u.id', $tr->id_abonent)->first();

                    if (!$user || !$user->kod) {
                        $i = 'Recalc command: Unkowen AbonentID!';
                        \Log::info($i);
                        $this->info($i);
                        continue;
                    }
                    $details = [
                        'dt' => $tr->dt_pay, 'yy' => date('Y', strtotime($tr->dt_pay)),
                        'amount' => $tr->amount, 'id_org' => $org->id,
                        'provider' => 'MUNIS', 'paid_at' => $tr->confirmed_at,
                        'sys_bid' => $tr->sys_bid, 'kod' => $user->kod, 'name' => $user->name,
                        'psp' => $user->psp, 'mahalla' => $user->mahalla, 'id_mahalla' => $user->id_mahalla,
                        'mobile' => $user->mobile, 'address' => $user->address, 'deposit' => $user->deposit,
                    ];
                }

                //$dt = $tr->dt_pay;
                $yy = date('Y', strtotime($dt));
                $mm = date('n', strtotime($dt));
                $amount = $tr->amount;

                // Provayder → hisoblagich ustunlari suffiksi (amount_$p / qty_$p).
                // SQL ni endi CounterUpsert quradi: MySQL uchun ON DUPLICATE KEY
                // UPDATE, PostgreSQL nusxasi uchun ON CONFLICT ... DO UPDATE.
                $p = null;
                if ($tr->supplier == 'paynet') {
                    $details['provider'] = 'PAYNET';
                    $p = 'paynet';
                }
                if ($tr['supplier'] == 'hgt') {
                    $details['provider'] = 'hgt';
                    $p = 'hgt';
                }
                if ($tr->supplier == 'apelsin') {
                    $details['provider'] = 'APELSIN';
                    $p = 'apelsin';
                }
                if ($tr->supplier == '0112') {
                    switch (trim($tr->payer_inn)) {
                        case '302134733':
                            $details['provider'] = 'CLICK';
                            $p = 'click';
                            break;
                        case '302050181':
                            $details['provider'] = 'PAYME';
                            $p = 'payme';
                            break;
                        default:
                            $details['provider'] = 'MUNIS';
                            $p = 'munis';
                    }
                }
                if ($p !== null && $this->bumpDayliByOrgs($dt, $yy, $mm, $org, $amount, $p)) {
                    if ($forceDetails) \DB::table('idx_dayli_by_orgs_details')->insert($details);
                    $i = 'Recalc command ' . $details['provider'] . ': ' . number_format($amount, 2);
                    $this->info($i);
                    //\Log::info($i);
                }
            }
        }
        catch(Exception $ex) {
            $i = 'Recalc command error: ' . $ex->getMessage() . ' Rollback all transactions';
            \Log::info($i); $this->info($i);
            \DB::rollback();
        } finally {
            $i = 'Recalc command finished for: ' . $dt;
            $this->info($i); \Log::info($i);
        }
    }

    private function RecalculationAll($forceDetails = true) {
        //$dt = date('Y-m-d');
        if (!$forceDetails) return;
        //$rows = \DB::table('vw_reindexation_payments')->get();
        //$this->info('Rows found: ' .count($rows));
        $rows = ['2022-08-28'];

        foreach($rows as $r) {
            //$trs = \DB::select(\DB::raw("select count(*) as qty,SUM(amount) as amt from brrgz.tb_gas_debit where dt_pay='{$r->dt}' "));
            $this->info($r);
            $this->info('ERRORS: ' . $this->RecalculationDate2($r));
        }
    }

    private function RecalculationDate2($dt) {
        $errors = 0;
        $i = 'Recalucalation command launched for: ' . $dt;
        \Log::info($i); $this->info($i);

        \DB::table('idx_dayli_by_orgs')->where('dt', $dt)->delete();
        \DB::table('idx_dayli_by_orgs_details')->where('dt', $dt)->delete();
        // L13: select() xom SATR kutadi — Expression obyektida __toString() yo'q.
        $rows = \DB::connection('pgsql1')->select("select * from tb_gas_debit where dt_pay='$dt' ");
        if (!$rows || count($rows) <= 0) {
            $i = 'Recalc command no data found for ' . $dt;
            \Log::info($i); $this->info($i);
            $errors++;
            return $errors;
        }
        \DB::beginTransaction();
        $this->info(count($rows));
       // try {
        $details = null;
        foreach ($rows as $tr) {
            if (!in_array(strtolower(trim($tr->supplier)), ['paynet', '0112', 'hgt','apelsin','payme'])) {
                $i = 'Recalc command: wrong supplier! ' . $tr->supplier;
                $this->info($i);
                $errors++;
                continue;
            }

            $org = \DB::table('organizations')->select('id', 'id_region')->where('id', $tr->payee_id)->first();
            if (!$org || !$org->id_region) {
                $i = 'Recalc command: Unkowen OrgID!';
                \Log::info($i); $this->info($i);
                $errors++;
                continue;
            }
            $user = \DB::connection('pgsql1')->table('cms_users as u')->leftjoin('mahallas as m', 'm.id','=','u.id_mahalla')
                ->select(\DB::raw("COALESCE(m.name,u.mahalla) as mahalla,u.name,u.kod,u.psp,u.deposit,u.address,u.mobile,u.id_mahalla"))
                ->where('u.id',$tr->id_abonent)->first();
           /*$user = \DB::select(\DB::raw("select COALESCE(u.name,u.mahalla) as mahalla,u.name,u.kod,u.psp,u.deposit,u.address,u.mobile,u.id_mahalla from brrgz.cms_users as u left join brrgz.mahallas as m on m.id=u.id_mahalla u.id = $tr->id_abonent limit 1"));*/

            if (!$user || !$user->kod) {
                $i = 'Recalc command: Unkowen AbonentID!';
                \Log::info($i);
                $this->info($i);
                $errors++;
                continue;
            }
            $details = [
                'dt' => $tr->dt_pay, 'yy' => date('Y', strtotime($tr->dt_pay)),
                'amount' => $tr->amount, 'id_org' => $org->id,
                'provider' => 'MUNIS', 'paid_at' => $tr->confirmed_at,
                'sys_bid' => $tr->sys_bid, 'kod' => $user->kod, 'name' => $user->name,
                'psp' => $user->psp, 'mahalla' => $user->mahalla, 'id_mahalla' => $user->id_mahalla,
                'mobile' => $user->mobile, 'address' => $user->address, 'deposit' => $user->deposit,
            ];

            //$dt = $tr->dt_pay;
            $yy = date('Y', strtotime($dt));
            $mm = date('n', strtotime($dt));
            $amount = $tr->amount;

            // Provayder → hisoblagich ustunlari suffiksi (yuqoridagi
            // RecalculationDate() dagidek; SQL ni CounterUpsert quradi).
            $p = null;
            if ($tr->supplier == 'paynet') {
                $details['provider'] = 'PAYNET';
                $p = 'paynet';
            }
            if ($tr->supplier == 'hgt') {
                $details['provider'] = 'HGT';
                $p = 'hgt';
            }
            if ($tr->supplier == 'apelsin') {
                $details['provider'] = 'APELSIN';
                $p = 'apelsin';
            }
            if ($tr->supplier == '0112') {
                switch (trim($tr->payer_inn)) {
                    case '302134733':
                        $details['provider'] = 'CLICK';
                        $p = 'click';
                        break;
                    case '302050181':
                        $details['provider'] = 'PAYME';
                        $p = 'payme';
                        break;
                    default:
                        $details['provider'] = 'MUNIS';
                        $p = 'munis';
                }
            }
            if ($p !== null) $this->bumpDayliByOrgs($dt, $yy, $mm, $org, $amount, $p);
            $this->info(json_encode($details, JSON_UNESCAPED_UNICODE));
            \DB::table('idx_dayli_by_orgs_details')->insert($details);

            //$i = $dt . ' => recalc ' . $details['provider'] . ': ' . $amount;
            //\Log::info($i);

        }
        \DB::commit();

        /* } catch(Exception $ex) {
            $i = 'Recalc2 command error: ' . $ex->getMessage() . ' Rollback all transactions';
            \Log::info($i); $this->info($i);
            $errors++;
            \DB::rollback();
        } finally {
            $i = 'Recalc2 command finished for: ' . $dt;
            $this->info($i); \Log::info($i);
            return $errors;
        }*/
    }

    private function RecalculateDeposit() {
        $rows = \DB::connection('pgsql1')->select("select id_org,SUM(COALESCE(deposit,0.00)) as y from cms_users where id_cms_privileges=4 group by id_org");
        if (!$rows || count($rows) <=0) {
            $i = 'RecalucalationDeposit command no data found!';
            \Log::info($i); $this->info($i);
            return;
        }
        $c = 0;
        foreach($rows as $r)
            $c += \DB::table('organizations')->where('id',$r->id_org)->update(['deposit' => $r->y]);

        $i = 'RecalucalationDeposit command: all updated records: ' . $c;
        \Log::info($i); $this->info($i);
    }

    /**
     * idx_dayli_by_orgs dagi kunlik hisoblagichlarni oshirish.
     *
     * Qator bo'lmasa qo'shiladi, bo'lsa `amount_$p` ga summa va `qty_$p` ga 1
     * qo'shiladi. SQL ikkala dialektda alohida quriladi va PostgreSQL nusxasiga
     * ham yoziladi — buni App\Services\DualWrite\CounterUpsert bajaradi
     * (xom SQL so'rov quruvchisidan o'tmaydi, ya'ni avtomatik nusxalanmaydi).
     *
     * @param string $dt      sana
     * @param int    $yy      yil
     * @param int    $mm      oy
     * @param object $org     organizations dan (id, id_region)
     * @param float  $amount  summa
     * @param string $p       provayder suffiksi: paynet|hgt|apelsin|click|payme|munis
     * @return bool
     */
    private function bumpDayliByOrgs($dt, $yy, $mm, $org, $amount, $p)
    {
        return \App\Services\DualWrite\CounterUpsert::run('idx_dayli_by_orgs', [
            'dt'        => $dt,
            'yy'        => $yy,
            'mm'        => $mm,
            'id_region' => $org->id_region,
            'id_org'    => $org->id,
            "amount_$p" => $amount,
            "qty_$p"    => 1,
        ], ['dt', 'yy', 'id_org'], ["amount_$p", "qty_$p"]);
    }
}
