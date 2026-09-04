<?php

namespace App\Http;

class Constant
{
    const KG_BALLON = 20;
    const PRICE_KG_GAZ = 1600;

    public static function gotPrice($dt = null)
    {
        if (strtotime($dt) >= strtotime('2025-05-01')) return 2000;
        if (strtotime($dt) >= strtotime('2024-05-01')) return 1600;
        return 1120;
    }
}
