<?php

namespace App\Services\DualWrite;

use DB;
use Log;

/**
 * Dual write ning "miyasi": bayroqlar, ulanish juftliklari, log va nusxa
 * amallarini TRANZAKSIYAGA MOS uzatish.
 *
 * Bu klass bazaga o'zi yozmaydi — yozishni `Mirror` bajaradi. Bu yerda faqat
 * "nusxa olinsinmi, qachon va qaysi ulanishga" degan qarorlar.
 *
 * @see config/dual_write.php
 * @see docs/dual-write.md
 */
class DualWrite
{
    const LOG_TAG = 'DUALW';

    /**
     * Nusxa yozish AYNI PAYTDA bajarilyaptimi.
     *
     * Rekursiyadan saqlaydi: nusxa ulanishi ham `mysql` drayverida bo'lib
     * qolsa (masalan juftlik mysql => mysql_brrgz deb sozlansa), nusxa yozish
     * yana o'zini chaqirib ketardi.
     */
    protected static $mirroring = false;

    /** Ketma-ket xatolar soni (max_failures uchun) */
    protected static $failures = 0;

    /** Shu process uchun butunlay to'xtatildimi */
    protected static $halted = false;

    /** Bir marta yozilishi kerak bo'lgan log xabarlari: kalit => true */
    protected static $noted = [];

    // ------------------------------------------------------------------
    // Bayroqlar
    // ------------------------------------------------------------------

    public static function enabled()
    {
        return (bool) config('dual_write.enabled', false) && !self::$halted;
    }

    public static function isDryRun()
    {
        return (bool) config('dual_write.dry_run', false);
    }

    public static function failOpen()
    {
        return (bool) config('dual_write.fail_open', true);
    }

    public static function isLogging()
    {
        return (bool) config('dual_write.log', true);
    }

    public static function triggersEnabled()
    {
        return (bool) config('dual_write.triggers', true);
    }

    /** Juftliklar: asosiy => nusxa */
    public static function pairs()
    {
        return (array) config('dual_write.pairs', []);
    }

    /**
     * Berilgan ulanish uchun nusxa ulanishi (yo'q bo'lsa null).
     *
     * @param string|null $connection  null = standart ulanish
     * @return string|null
     */
    public static function mirrorOf($connection)
    {
        $name  = self::resolve($connection);
        $pairs = self::pairs();

        if (!isset($pairs[$name])) return null;

        $mirror = $pairs[$name];

        // O'zini o'ziga nusxalash — sozlama xatosi, jimgina o'tkazib yuboramiz.
        return ($mirror && $mirror !== $name) ? $mirror : null;
    }

    /** Ulanish nomini aniqlash: null => config('database.default') */
    public static function resolve($connection)
    {
        return $connection === null || $connection === ''
            ? config('database.default')
            : $connection;
    }

    /**
     * Shu ulanish + jadval uchun nusxa olinadimi.
     *
     * @return bool
     */
    public static function shouldMirror($connection, $table)
    {
        if (!self::enabled())  return false;
        if (self::$mirroring)  return false;   // nusxa yozish ichida — rekursiya yo'q
        if (!self::mirrorOf($connection)) return false;

        return self::tableAllowed($table);
    }

    /**
     * Jadval filtri (only_tables / skip_tables).
     *
     * @return bool
     */
    public static function tableAllowed($table)
    {
        $table = self::plainTable($table);

        $only = (array) config('dual_write.only_tables', []);
        if ($only && !in_array($table, $only, true)) return false;

        return !in_array($table, (array) config('dual_write.skip_tables', []), true);
    }

    /**
     * "jadval as alias" / "schema.jadval" dan toza jadval nomini olish.
     *
     * @param string $table
     * @return string
     */
    public static function plainTable($table)
    {
        $table = trim((string) $table);

        // "i_real_details as r" → "i_real_details"
        if (($p = stripos($table, ' as ')) !== false) {
            $table = substr($table, 0, $p);
        }

        return trim($table);
    }

    // ------------------------------------------------------------------
    // Nusxa amalini uzatish
    // ------------------------------------------------------------------

    /**
     * Nusxa amalini bajarish — asosiy ulanish TRANZAKSIYA ichida bo'lsa
     * COMMIT dan keyinga qoldiriladi.
     *
     * Sabab: nusxa boshqa bazada, ya'ni asosiy tranzaksiyaga kira olmaydi.
     * Darhol yozilsa va keyin rollback bo'lsa — PG da MySQL da yo'q qator
     * qolib ketardi. `afterCommit` esa rollback bo'lganda callback ni
     * BUTUNLAY tashlab yuboradi.
     *
     * @param string|null $connection asosiy ulanish
     * @param array       $op         Mirror::apply() tushunadigan amal
     * @return void
     */
    public static function dispatch($connection, array $op)
    {
        $primary = self::resolve($connection);
        $op['primary'] = $primary;

        $run = function () use ($op) {
            self::run($op);
        };

        try {
            $conn = DB::connection($primary);

            if ($conn->transactionLevel() > 0) {
                // Tranzaksiya ichida: COMMIT dan keyin. (Transactions manager
                // sozlanmagan bo'lsa RuntimeException — pastdagi catch ushlaydi.)
                $conn->afterCommit($run);
                return;
            }
        } catch (\Throwable $e) {
            self::noteOnce('aftercommit', 'afterCommit ishlatilmadi (' . $e->getMessage()
                . ') — nusxa DARHOL yoziladi.');
        }

        $run();
    }

    /**
     * Amalni himoyalangan holda bajarish: rekursiya qalqoni, dry-run,
     * xatolarni yutish (fail_open) va ketma-ket xatolar hisobi.
     *
     * @return void
     */
    protected static function run(array $op)
    {
        if (self::$mirroring || self::$halted) return;

        self::$mirroring = true;
        try {
            Mirror::apply($op);
            self::$failures = 0;
        } catch (\Throwable $e) {
            self::$mirroring = false;
            self::countFailure($e, $op);

            if (!self::failOpen()) throw $e;
        } finally {
            self::$mirroring = false;
        }
    }

    /**
     * Nusxa xatosini hisoblash; chegaradan oshsa nusxa olish to'xtatiladi.
     */
    protected static function countFailure(\Throwable $e, array $op)
    {
        self::$failures++;

        $table = isset($op['table']) ? $op['table'] : '?';
        $type  = isset($op['type']) ? $op['type'] : '?';

        Log::error(self::LOG_TAG . " [$table] $type nusxasi YOZILMADI: " . $e->getMessage());

        $max = (int) config('dual_write.max_failures', 20);
        if ($max > 0 && self::$failures >= $max) {
            self::$halted = true;
            Log::error(self::LOG_TAG . ' ketma-ket ' . self::$failures . ' ta xato — nusxa olish'
                . ' SHU PROCESS uchun to`xtatildi. Nusxa bazasini tekshirib, `php artisan dual:status`'
                . ' bilan holatni ko`ring.');
        }
    }

    // ------------------------------------------------------------------
    // Log
    // ------------------------------------------------------------------

    public static function log($message)
    {
        if (self::isLogging()) Log::debug(self::LOG_TAG . ' ' . $message);
    }

    public static function warn($message)
    {
        Log::warning(self::LOG_TAG . ' ' . $message);
    }

    /**
     * Bir xil ogohlantirishni process davomida FAQAT BIR MARTA yozish
     * (masalan "nusxa jadvalda bunday ustun yo'q" — har qatorda takrorlanmasin).
     */
    public static function noteOnce($key, $message)
    {
        if (isset(self::$noted[$key])) return;

        self::$noted[$key] = true;
        self::warn($message);
    }

    // ------------------------------------------------------------------
    // Holat (dual:status uchun)
    // ------------------------------------------------------------------

    public static function isHalted()
    {
        return self::$halted;
    }

    public static function isMirroring()
    {
        return self::$mirroring;
    }

    /** FAQAT test/tinker uchun */
    public static function reset()
    {
        self::$mirroring = false;
        self::$failures  = 0;
        self::$halted    = false;
        self::$noted     = [];
    }
}
