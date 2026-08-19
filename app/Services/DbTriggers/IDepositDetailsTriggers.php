<?php

namespace App\Services\DbTriggers;

/**
 * `i_deposit_details` jadvalidagi MySQL triggerining PHP versiyasi.
 *
 * Manba: docs/idxdb_triggers.sql
 *   insert_i_deposit_details   AFTER INSERT
 */
class IDepositDetailsTriggers extends BaseTrigger
{
    const T_AI = 'i_deposit_details.insert_i_deposit_details';

    /**
     * AFTER INSERT ON i_deposit_details — `insert_i_deposit_details`
     *
     * Oylik depozit ko'rsatkichlarini tashkilot/mahalla kesimida
     * `i_deposit_orgs` ga yig'adi:
     *   deposit / kg / amount / real_amount — hammasi QO'SHILIB boradi.
     *
     * `id_reg` bazadagidek kichik so'rov (subquery) bilan olinadi.
     *
     * @param array|object $newRow
     * @return void
     */
    public static function afterInsert($newRow)
    {
        $new = self::row($newRow);

        self::fire(self::T_AI, function () use ($new) {
            $deposit    = self::val($new, 'deposit');
            $kg         = self::val($new, 'kg');
            $amount     = self::val($new, 'amount');
            $realAmount = self::val($new, 'real_amount');

            self::upsert(
                self::T_AI,
                'i_deposit_orgs',
                '(mm, yy, id_org, id_mah, id_reg, deposit, kg, amount, real_amount)',
                '(?, ?, ?, ?, (SELECT id_region FROM organizations o WHERE o.id = ?), ?, ?, ?, ?)',
                ['mm', 'yy', 'id_org', 'id_mah'],   // birlamchi kalit
                [
                    'deposit'     => '+ ?',
                    'kg'          => '+ ?',
                    'amount'      => '+ ?',
                    'real_amount' => '+ ?',
                ],
                [
                    self::val($new, 'mm'),
                    self::val($new, 'yy'),
                    self::val($new, 'id_org'),
                    self::val($new, 'id_mah'),
                    self::val($new, 'id_org'),   // subquery uchun
                    $deposit, $kg, $amount, $realAmount,
                    $deposit, $kg, $amount, $realAmount,
                ],
                'i_deposit_orgs upsert'
            );
        });
    }
}
