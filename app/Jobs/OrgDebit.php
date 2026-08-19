<?php

namespace App\Jobs;

use App\Actions\FindProvider;
use App\Http\Constant;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrgDebit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    protected $mm, $yy, $user;

    public function __construct($mm, $yy, $user) {
        $this->mm = $mm;
        $this->yy = $yy;
        $this->user = $user;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (env('JOBS_DISABLE')) return;
        $count = 0;
        ini_set('memory_limit', '3G');
            $user = json_decode(json_encode($this->user), true);
            $real = DB::table('i_real_details')->select(DB::raw('COUNT(real_at) as qty'), 'abonent_kod')->whereMonth('dt', $this->mm)->whereYear('dt', $this->yy)->where('abonent_kod', $user['kod'])->groupBy('abonent_kod')->first();
            $money = DB::table('i_money_details')->select(DB::raw('SUM(amount) as amount'), 'kod')->whereMonth('dt', $this->mm)->whereYear('dt', $this->yy)->where('kod', $user['kod'])->groupBy('kod')->first();

            $real = json_decode(json_encode($real), true);
            $money = json_decode(json_encode($money), true);
            $submonth = Carbon::createFromFormat("Y-m", "{$this->yy}-{$this->mm}")->subMonth();
            // L13: DB::select() xom SATR kutadi (Expression obyektida __toString() yo'q)
            // PG: MySQL ning month()/year() funksiyalari yo'q. Ular baribir
            // o'zgarmas sanaga qo'llanardi — endi qiymat PHP da hisoblanadi.
            $lastDepositSql = json_decode(json_encode(DB::select(
                'SELECT deposit FROM i_deposit_details where mm = ? and yy = ? and abonent_kod = ?',
                [(int) $submonth->month, (int) $submonth->year, $user['kod']]
            )), true);
            $lastDepositSql = !empty($lastDepositSql) ? $lastDepositSql[0]['deposit'] : 0;
            $details = [
                'mm' => $this->mm,
                'yy' => $this->yy,
                'id_org' => $user['id_org'],
                'id_mah' => $user['id_mahalla'] ?? 8740,
                'abonent_kod' => $user['kod'],
                'abonent_name' => $user['name'],
                'deposit' => (($money['amount'] ?? 0) - ($real['qty'] ?? 0) * Constant::gotPrice($this->yy.'-'.$this->mm.'-01') * Constant::KG_BALLON) + $lastDepositSql,
                'kg' => ($real['qty'] ?? 0) * Constant::KG_BALLON,
                'amount' => ($money['amount'] ?? 0),
                'real_amount' => ($real['qty'] ?? 0) * Constant::gotPrice($this->yy.'-'.$this->mm.'-01') * Constant::KG_BALLON
            ];

            // DB trigger `insert_i_deposit_details` (AFTER INSERT) —
            // i_deposit_orgs upsert (deposit/kg/amount/real_amount jamlanadi)
            $count += \App\Services\DbTriggers\TriggerBus::insert('i_deposit_details', $details);
        Log::info($count);
    }
}






