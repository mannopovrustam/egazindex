<?php

namespace App\Services\DbTriggers;

/**
 * `i_real_details` jadvalidagi MySQL triggerlarining PHP versiyasi.
 *
 * Manba: docs/idxdb_triggers.sql
 *   insert_i_real_details   AFTER INSERT
 *   minus_deposit_orgs      AFTER INSERT
 *
 * ⚠ IKKALASI HAM BITTA INSERT da yonadi. Bazadagi bajarilish tartibi
 *   (yaratilish tartibi bo'yicha): avval `insert_i_real_details`, keyin
 *   `minus_deposit_orgs`. TriggerBus shu tartibni saqlaydi.
 */
class IRealDetailsTriggers extends BaseTrigger
{
    const T_ORGS    = 'i_real_details.insert_i_real_details';
    const T_DEPOSIT = 'i_real_details.minus_deposit_orgs';

    /**
     * AFTER INSERT ON i_real_details — `insert_i_real_details`
     *
     * Realizatsiyani tashkilot/mahalla/kun kesimida `i_real_orgs` ga yig'adi:
     *   amount_total += NEW.amount,  total_qty += 1
     *
     * `id_region` bazadagidek KICHIK SO'ROV (subquery) bilan olinadi —
     * qo'shimcha roundtrip bo'lmasligi va semantika aynan mos kelishi uchun.
     *
     * @param array|object $newRow
     * @return void
     */
    public static function insertIRealDetails($newRow)
    {
        $new = self::row($newRow);

        self::fire(self::T_ORGS, function () use ($new) {
            $dt     = self::val($new, 'dt');
            $amount = self::val($new, 'amount');

            self::upsert(
                self::T_ORGS,
                'i_real_orgs',
                '(dt, yy, mm, id_region, id_org, id_mah, amount_total, total_qty)',
                '(?, ?, ?, (SELECT id_region FROM organizations o WHERE o.id = ? LIMIT 1), ?, ?, ?, 1)',
                ['dt', 'yy', 'id_org', 'id_mah'],   // birlamchi kalit
                ['amount_total' => '+ ?', 'total_qty' => '+ 1'],
                [
                    $dt,
                    self::val($new, 'yy'),
                    self::monthOf($dt),          // MONTH(NEW.dt)
                    self::val($new, 'id_org'),   // subquery uchun
                    self::val($new, 'id_org'),
                    self::val($new, 'id_mah'),
                    $amount,
                    $amount,
                ],
                'i_real_orgs upsert'
            );
        });
    }

    /**
     * AFTER INSERT ON i_real_details — `minus_deposit_orgs`
     *
     * Tashkilot depozitidan realizatsiya summasini yechadi:
     *   organizations.deposit -= NEW.amount
     *
     * @param array|object $newRow
     * @return void
     */
    public static function minusDepositOrgs($newRow)
    {
        $new = self::row($newRow);

        self::fire(self::T_DEPOSIT, function () use ($new) {
            self::upd(self::T_DEPOSIT, 'organizations',
                ['id' => self::val($new, 'id_org')],
                ['deposit' => \DB::raw('deposit - ' . (float) self::val($new, 'amount', 0))]
            );
        });
    }
}
