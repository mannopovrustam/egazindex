<?php

namespace App\Services\DbTriggers;

/**
 * `i_money_details` jadvalidagi MySQL triggerlarining PHP versiyasi.
 *
 * Manba: docs/idxdb_triggers.sql
 *   plus_deposit_org       AFTER INSERT
 *   i_money_details_orgs   AFTER DELETE  (bazada tanasi TO'LIQ izohga olingan — no-op)
 */
class IMoneyDetailsTriggers extends BaseTrigger
{
    const T_PLUS = 'i_money_details.plus_deposit_org';
    const T_DEL  = 'i_money_details.i_money_details_orgs';

    /**
     * `i_money_details_orgs` izohidagi provayderlar va ularning ustunlari.
     * provider => [amount ustuni, qty ustuni]
     */
    private static $providerColumns = [
        'PAYNET'  => ['amount_paynet',  'qty_paynet'],
        'PAYME'   => ['amount_payme',   'qty_payme'],
        'CLICK'   => ['amount_click',   'qty_click'],
        'MUNIS'   => ['amount_munis',   'qty_munis'],
        'APELSIN' => ['amount_apelsin', 'qty_apelsin'],
        'HGT'     => ['amount_hgt',     'qty_hgt'],
    ];

    /**
     * AFTER INSERT ON i_money_details — `plus_deposit_org`
     *
     * To'lov summasini tashkilot depozitiga qo'shadi:
     *   organizations.deposit += NEW.amount
     *
     * @param array|object $newRow
     * @return void
     */
    public static function plusDepositOrg($newRow)
    {
        $new = self::row($newRow);

        self::fire(self::T_PLUS, function () use ($new) {
            self::upd(self::T_PLUS, 'organizations',
                ['id' => self::val($new, 'id_org')],
                ['deposit' => \DB::raw('deposit + ' . (float) self::val($new, 'amount', 0))]
            );
        });
    }

    /**
     * AFTER DELETE ON i_money_details — `i_money_details_orgs`
     *
     * Bazada tanasi TO'LIQ izohga olingan (ya'ni hozir hech narsa qilmaydi).
     * Izohdagi mantiq shu yerda PHP ga o'girilgan: o'chirilgan to'lovni
     * `i_money_orgs` dagi provayder ustunlaridan qaytarib chiqaradi.
     *
     * ⚠ Buni yoqish — KO'CHIRISH emas, YANGI XATTI-HARAKAT qo'shish.
     *   Avval `i_money_orgs` qiymatlari haqiqatan ham buzilganini tekshiring.
     *
     * @param array|object $oldRow
     * @return void
     */
    public static function iMoneyDetailsOrgs($oldRow)
    {
        $old = self::row($oldRow);

        self::fire(self::T_DEL, function () use ($old) {
            $provider = strtoupper((string) self::val($old, 'provider'));
            if (!isset(self::$providerColumns[$provider])) return;

            $amountCol = self::$providerColumns[$provider][0];
            $qtyCol    = self::$providerColumns[$provider][1];

            $amount  = (float) self::val($old, 'amount', 0);
            $deposit = (float) self::val($old, 'deposit', 0);

            self::stmt(
                self::T_DEL,
                'UPDATE i_money_orgs SET ' . $amountCol . ' = ' . $amountCol . ' - ?, '
                . 'deposit = deposit - ?, ' . $qtyCol . ' = ' . $qtyCol . ' - 1 '
                . 'WHERE dt = ? AND yy = ? AND id_mah = ? AND id_org = ?',
                [
                    $amount,
                    $deposit,
                    self::val($old, 'dt'),
                    self::val($old, 'yy'),
                    self::val($old, 'id_mah'),
                    self::val($old, 'id_org'),
                ],
                'i_money_orgs qaytarish (' . $provider . ')'
            );
        });
    }
}
