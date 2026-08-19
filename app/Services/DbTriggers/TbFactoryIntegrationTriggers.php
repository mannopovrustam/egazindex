<?php

namespace App\Services\DbTriggers;

/**
 * `tb_factory_integration` jadvalidagi MySQL triggerining PHP versiyasi.
 *
 * Manba: docs/idxdb_triggers.sql
 *   tb_factory_integration_bi   BEFORE INSERT
 */
class TbFactoryIntegrationTriggers extends BaseTrigger
{
    const T_BI = 'tb_factory_integration.tb_factory_integration_bi';

    /**
     * HGT filial kodi → egaz tashkilot id (bazadagi CASE bilan aynan bir xil).
     */
    private static $filialMap = [
        '000000001' => 274,
        '000000002' => 275,
        '000000003' => 284,
        '000000004' => 276,
        '000000005' => 280,
        '000000006' => 279,
        '000000007' => 277,
        '000000008' => 278,
        '000000009' => 281,
        '000000010' => 282,
        '000000011' => 283,
        '000000012' => 273,
        '000000013' => 285,
    ];

    /**
     * Zavod kodi → egaz tashkilot id.
     */
    private static $factoryMap = [
        '000000002' => 292,
        '000000004' => 295,
        '000000006' => 290,
        '000000009' => 293,
        '000000010' => 304,
    ];

    /**
     * BEFORE INSERT ON tb_factory_integration — `tb_factory_integration_bi`
     *
     * HGT tomondan kelgan matnli kodlarni egaz tashkilot id lariga o'giradi.
     * Xaritada topilmasa qiymat O'ZGARISHSIZ qoladi (SQL dagi `ELSE
     * new.hgt_filial_egaz` / `ELSE new.factory_egaz` — ya'ni kelgan qiymat
     * saqlanadi).
     *
     * @param array|object $newRow
     * @return array
     */
    public static function beforeInsert($newRow)
    {
        $new = self::row($newRow);

        return self::fire(self::T_BI, function () use ($new) {
            $filial = self::val($new, 'hgt_filial');
            if ($filial !== null && isset(self::$filialMap[(string) $filial])) {
                $new['hgt_filial_egaz'] = self::$filialMap[(string) $filial];
            }

            $factory = self::val($new, 'factory');
            if ($factory !== null && isset(self::$factoryMap[(string) $factory])) {
                $new['factory_egaz'] = self::$factoryMap[(string) $factory];
            }

            self::log(self::T_BI, 'BEFORE INSERT tb_factory_integration → hgt_filial_egaz='
                . var_export(self::val($new, 'hgt_filial_egaz'), true)
                . ' factory_egaz=' . var_export(self::val($new, 'factory_egaz'), true));

            return $new;
        }, $new);
    }
}
