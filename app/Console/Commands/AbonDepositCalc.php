<?php

namespace App\Console\Commands;

use App\Services\Deposit;
use Illuminate\Console\Command;

class AbonDepositCalc extends Command {
    protected $signature = 'deposit:abondepositcalc {status}';
    protected $description = 'calc deposit from calc_abonents';
    public function __construct() {parent::__construct();}

    public function handle() {
        $status = $this->argument('status');
        if (!in_array($status, ['Active', 'Deactive'])){
            $this->error('Wrong status parameter!');
            return;
        }
        try {
            // L13: select()/insert() xom SATR kutadi — Expression obyektida __toString() yo'q.
            $rows = \DB::select("select kod, id, deposit from tmp_db.cms_users where status = '$status' and id_cms_privileges = 4");

            if (!$rows) {
                $this->info('No abonents data for calculation!');
                return;
            }
            foreach($rows as $r) {
                $q = self::computeBalance($r->id,$r->deposit);
                if($q['affected'] == 1) {
                    $this->info($r->kod  .' => old_balance: ' . $r->deposit .' => real_balance: ' . $q['balance'] . ', affected: ' . $q['affected']);
                    \DB::insert("insert into tmp_db.wrong_deposits (id_abonent, old_deposit, real_deposit) values ($r->id, $r->deposit, {$q['balance']})");
                }
                else $this->error($r->kod  .' => balance: ' . $q['balance'] . ', affected: ' . $q['affected']);
            }
        }
        catch (\Exception $ex) {
            $this->error('Calc deposit error: ' . $ex->getMessage());
        }
    }

    public static function computeBalance($id, $deposit) {
        $deposits = [
            'balance'=>0.00,
            'affected'=>0
        ];
        $affected = 0;

        $q = \DB::select("with cte as (select (select sum(amount) from tmp_db.tb_gas_debit where id_abonent = $id and st='0') as inp, (select sum(price) from tmp_db.tb_gas_credit where id_abonent = $id) as outp, (select sum(saldo) from tmp_db.hgt_saldo where id_abonent = $id) as saldo) select IFNULL(inp, 0) as i, IFNULL(outp, 0) as o, IFNULL(saldo, 0) as s, IFNULL(inp, 0) - IFNULL(outp, 0) + IFNULL(saldo, 0) as balance from cte");

        if ($q && $q[0]) {
            if ($deposit != $q[0]->balance) $affected = 1;
            $deposits = [
                'balance'=>$q[0]->balance ?? 0.00,
                'affected'=>$affected
            ];
        }
        return $deposits;
    }

}
