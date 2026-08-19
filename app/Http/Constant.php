<?php

namespace App\Http;

class Constant
{
    const KG_BALLON = 20;
    const PRICE_KG_GAZ = 1600;

    public static function gotPrice($dt = null)
    {
        if (!$dt) return self::PRICE_KG_GAZ;
        if (strtotime($dt) >= strtotime('2024-05-01')) return 1600;
        return 1120;
    }
}