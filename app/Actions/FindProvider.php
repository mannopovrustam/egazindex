<?php

namespace App\Actions;

class FindProvider
{
    public function provider($supplier,$payer_inn)
    {
        if (strtolower($supplier) == 'paynet') return 'PAYNET';

        if (strtolower($supplier) == 'hgt') return 'HGT';

        if (strtolower($supplier) == 'apelsin') return 'APELSIN';

        switch (trim($payer_inn)) {
            case '302134733':
                return 'CLICK';
            case '302050181':
                return 'PAYME';
            default:
                return 'MUNIS';
        }
    }

    public function calcDebosit($id, $date){
        // L13: DB::select() xom SATR kutadi — Expression obyektida __toString() yo'q.
        // PG: IFNULL yo'q — COALESCE.
        $q = \DB::connection('pgsql1')->select("with cte as (select COALESCE(SUM(amount),0) as prix, 0 as ras from tb_gas_debit where id_abonent = $id and dt_pay <= '$date' union all select 0, COALESCE(SUM(price),0) from tb_requests_ballons where id_abonent = $id and dt <= '$date') select SUM(prix) - SUM(ras) as deposit from cte");
        if ($q && $q[0]) return $q[0]->deposit;
        return 0.00;
    }

}