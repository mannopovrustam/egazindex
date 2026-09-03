<?php

namespace App\Services\DbTriggers;

/**
 * Baza triggerlarining PHP versiyalari uchun bayroq boshqaruvi.
 *
 * Barcha bayroqlar `config/db_triggers.php` da. Standart holat — yoqiq, LEKIN
 * faqat `php_connections` da ko'rsatilgan ulanishlarda:
 *
 *   mysql / mysql1  → bazaning O'Z triggerlari bor  → PHP tomoni O'CHIQ
 *   pgsql / pgsql1  → PG da DB trigger yo'q          → PHP tomoni YOQIQ
 *
 * Shu sababli dual write da amal hech qayerda ikki marta bajarilmaydi:
 * MySQL da ishni baza qiladi, PostgreSQL nusxasida esa PHP.
 *
 * @see config/db_triggers.php
 * @see config/dual_write.php
 * @see docs/db-triggers-to-service-migration.md
 */
class TriggerFlags
{
    /** Runtime (test/tinker) uchun vaqtinchalik bekor qilishlar: nom => bool */
    protected static $overrides = [];

    /**
     * Umumiy kalit yoqilganmi (avariya o'chirgichi).
     */
    public static function masterEnabled()
    {
        return (bool) config('db_triggers.enabled', false);
    }

    /**
     * Aynan shu trigger PHP tomonda yoqilganmi.
     *
     * @param string $name  masalan: 'i_real_details.insert_i_real_details'
     * @return bool
     */
    public static function enabled($name)
    {
        if (array_key_exists($name, self::$overrides)) {
            return (bool) self::$overrides[$name] && self::masterEnabled();
        }
        if (!self::masterEnabled()) return false;

        // DIQQAT: `config('db_triggers.triggers.' . $name)` ISHLAMAYDI —
        // bayroq nomining O'ZIDA nuqta bor ('cms_users.incUsers'), Arr::get esa
        // uni ichma-ich massiv yo'li deb tushunadi va DOIM default (false)
        // qaytaradi. Shuning uchun ro'yxat butunligicha olinib, kalit to'g'ridan
        // to'g'ri qidiriladi.
        $list = config('db_triggers.triggers', []);

        return isset($list[$name]) ? (bool) $list[$name] : false;
    }

    /**
     * Shu trigger AYNAN SHU ULANISHDA PHP tomonda ishlaydimi.
     *
     * Ikki shart: bayroq yoqilgan BO'LSIN va ulanish `php_connections`
     * ro'yxatida bo'lsin (ya'ni o'sha bazada DB trigger YO'Q).
     *
     * @param string      $name
     * @param string|null $connection  null = standart ulanish
     * @return bool
     */
    public static function enabledOn($name, $connection = null)
    {
        return self::enabled($name) && self::phpSideOn($connection);
    }

    /**
     * PHP triggerlari shu ulanishda ishlashi kerakmi.
     *
     * `php_connections` berilmagan (null) bo'lsa — hamma ulanishda ishlaydi
     * (dual write dan oldingi xatti-harakat).
     *
     * @param string|null $connection
     * @return bool
     */
    public static function phpSideOn($connection = null)
    {
        $list = config('db_triggers.php_connections', null);
        if ($list === null) return true;

        return in_array(self::resolveConnection($connection), (array) $list, true);
    }

    /**
     * Ulanish nomini haqiqiy nomga keltirish: null → db_triggers.connection →
     * standart ulanish (config database.default).
     *
     * @param string|null $connection
     * @return string
     */
    public static function resolveConnection($connection = null)
    {
        if ($connection === null) $connection = self::connection();

        return $connection === null ? config('database.default') : $connection;
    }

    /**
     * Berilgan triggerlardan hech bo'lmasa bittasi yoqilganmi.
     *
     * @param string|array $names
     * @return bool
     */
    public static function anyEnabled($names)
    {
        foreach ((array) $names as $n) {
            if (self::enabled($n)) return true;
        }
        return false;
    }

    /**
     * Ro'yxatga olingan barcha trigger nomlari va holati: nom => bool.
     *
     * @param string|null|false $connection  false = ulanishni hisobga olmaslik
     *                                       (faqat bayroqning o'zi)
     */
    public static function all($connection = false)
    {
        $list = config('db_triggers.triggers', []);
        $out  = [];
        foreach ($list as $name => $_) {
            $out[$name] = $connection === false
                ? self::enabled($name)
                : self::enabledOn($name, $connection);
        }
        return $out;
    }

    /**
     * Bir xil hodisada yonadigan trigger juftliklari.
     */
    public static function pairs()
    {
        return config('db_triggers.pairs', []);
    }

    /**
     * Triggerli jadvallar yotgan ulanish nomi (null = standart `mysql`).
     */
    public static function connection()
    {
        return config('db_triggers.connection', null);
    }

    public static function isDryRun()
    {
        return (bool) config('db_triggers.dry_run', false);
    }

    public static function isLogging()
    {
        return (bool) config('db_triggers.log', true);
    }

    public static function failOpen()
    {
        return (bool) config('db_triggers.fail_open', false);
    }

    // ---------------------------------------------------------------------
    // Runtime override — FAQAT test/tinker uchun.
    // ---------------------------------------------------------------------

    public static function override($name, $value)
    {
        self::$overrides[$name] = (bool) $value;
    }

    public static function forget($name)
    {
        unset(self::$overrides[$name]);
    }

    public static function resetOverrides()
    {
        self::$overrides = [];
    }
}
