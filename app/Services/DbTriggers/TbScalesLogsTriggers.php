<?php

namespace App\Services\DbTriggers;

/**
 * `tb_scales_logs` jadvalidagi MySQL triggerining PHP versiyasi.
 *
 * Manba: docs/idxdb_triggers.sql
 *   tb_scales_logs_bi   BEFORE INSERT
 */
class TbScalesLogsTriggers extends BaseTrigger
{
    const T_BI = 'tb_scales_logs.tb_scales_logs_bi';

    /**
     * Tarozidan kelgan org_id → haqiqiy tashkilot id (bazadagi CASE).
     */
    private static $orgMap = [
        1   => 13,
        239 => 350,
    ];

    /**
     * BEFORE INSERT ON tb_scales_logs — `tb_scales_logs_bi`
     *
     * Tarozi qurilmasi yuboradigan noto'g'ri tashkilot id larini tuzatadi.
     * Xaritada bo'lmasa qiymat o'zgarishsiz qoladi.
     *
     * @param array|object $newRow
     * @return array
     */
    public static function beforeInsert($newRow)
    {
        $new = self::row($newRow);

        return self::fire(self::T_BI, function () use ($new) {
            $orgId = self::val($new, 'org_id');
            if ($orgId !== null && isset(self::$orgMap[(int) $orgId])) {
                $new['org_id'] = self::$orgMap[(int) $orgId];
                self::log(self::T_BI, 'BEFORE INSERT tb_scales_logs → org_id ' . $orgId . ' => ' . $new['org_id']);
            }

            return $new;
        }, $new);
    }
}
