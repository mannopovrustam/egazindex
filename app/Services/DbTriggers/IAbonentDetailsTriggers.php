<?php

namespace App\Services\DbTriggers;

/**
 * `i_abonent_details` jadvalidagi MySQL triggerlarining PHP versiyasi.
 *
 * Manba: docs/idxdb_triggers.sql
 *   i_abonent_details_ai   AFTER INSERT
 *   i_abonent_details_au   AFTER UPDATE
 *
 * ⚠ IKKALASINING TANASI AYNAN BIR XIL — ya'ni AFTER UPDATE ham hisoblagichni
 *   KAMAYTIRMAY, `all_users` (va status rost bo'lsa `active_users`) ni YANA
 *   BITTAGA OSHIRADI. Bu bazadagi mavjud xatti-harakat, ataylab aynan
 *   takrorlangan. Har bir UPDATE `i_abonent_orgs` ni shishiradi — shuning
 *   uchun `i_abonent_details_au` ni yoqishdan oldin UPDATE yozuv nuqtalarini
 *   ko'rib chiqing. docs/db-triggers-to-service-migration.md
 */
class IAbonentDetailsTriggers extends BaseTrigger
{
    const T_AI = 'i_abonent_details.i_abonent_details_ai';
    const T_AU = 'i_abonent_details.i_abonent_details_au';

    /**
     * AFTER INSERT ON i_abonent_details — `i_abonent_details_ai`
     *
     * @param array|object $newRow
     * @return void
     */
    public static function afterInsert($newRow)
    {
        $new = self::row($newRow);
        self::fire(self::T_AI, function () use ($new) {
            self::bumpOrgs(self::T_AI, $new);
        });
    }

    /**
     * AFTER UPDATE ON i_abonent_details — `i_abonent_details_au`
     *
     * Tanasi `afterInsert` bilan bir xil (bazadagidek). `$old` ishlatilmaydi —
     * bazadagi trigger ham OLD ga umuman qaramaydi.
     *
     * @param array|object $newPartial  UPDATE payload'i (yoki to'liq yangi qator)
     * @param array|object $old         UPDATE dan oldingi qator
     * @return void
     */
    public static function afterUpdate($newPartial, $old)
    {
        $new = self::effective($newPartial, $old);
        self::fire(self::T_AU, function () use ($new) {
            self::bumpOrgs(self::T_AU, $new);
        });
    }

    /**
     * Ikkala triggerning umumiy tanasi:
     *
     *   IF new.status THEN  all_users+1, active_users+1
     *   ELSE                all_users+1
     *
     * `id_reg` bazadagidek kichik so'rov (subquery) bilan olinadi.
     */
    private static function bumpOrgs($trigger, array $new)
    {
        $active = self::truthy(self::val($new, 'status')) ? 1 : 0;

        // Bazadagi triggerdagidek: status rost bo'lsa ikkala hisoblagich ham
        // oshadi, aks holda faqat `all_users`.
        $bumps = $active
            ? ['all_users' => '+ 1', 'active_users' => '+ 1']
            : ['all_users' => '+ 1'];

        self::upsert(
            $trigger,
            'i_abonent_orgs',
            '(id_reg, id_org, id_mah, all_users, active_users)',
            '((SELECT id_region FROM organizations o WHERE o.id = ?), ?, ?, 1, ?)',
            ['id_org', 'id_mah'],            // PG konflikt maqsadi = birlamchi kalit
            $bumps,
            [
                self::val($new, 'id_org'),   // subquery uchun
                self::val($new, 'id_org'),
                self::val($new, 'id_mah'),
                $active,
            ],
            'i_abonent_orgs upsert (active=' . $active . ')'
        );
    }
}
