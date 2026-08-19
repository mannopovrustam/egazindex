<?php

namespace App\Services\DbTriggers;

use DB;

/**
 * egaz_idxdb trigger servislari uchun umumiy asos.
 *
 * Har bir yozish amali shu yerdagi yordamchilardan o'tadi — shu sababli
 * `dry_run`, log, xato boshqaruvi va ULANISH tanlash bitta joyda.
 *
 * ULANISH: bu loyihada bir nechta baza bor (pgsql = indexator bazasi,
 * pgsql1 = asosiy egaz bazasi). Trigger o'z
 * yon ta'sirlarini AYNAN qaysi bazada bajarayotgan bo'lsa, o'sha bazada
 * bajarishi shart — shuning uchun `TriggerBus` har bir chaqiruvdan oldin
 * ulanishni o'rnatib qo'yadi.
 *
 * DIALEKT: yozish yordamchilari (upsert / insIgnore / toUInt) SQL ni ulanish
 * driveriga qarab quradi, ya'ni bir xil trigger kodi MySQL da ham, PostgreSQL
 * da ham ishlaydi. Dialektga bog'liq SQL faqat shu klassda.
 *
 * MySQL semantikasiga sodiqlik qoidalari (manba triggerlar MySQL da yozilgan):
 *   - NEW.x  → `$new` massividagi qiymat (BEFORE triggerlarda o'zgartiriladi)
 *   - OLD.x  → `$old` massividagi qiymat
 *   - AFTER UPDATE da haqiqiy NEW = eski qator ustiga UPDATE payload'i
 *     qo'yilgani (`effective()`).
 *   - SQL da `a != b` da bir tomon NULL bo'lsa natija NULL (ya'ni FALSE).
 */
abstract class BaseTrigger
{
    const LOG_TAG = 'DBTRG';

    /** Joriy ulanish nomi (null = config dagi standart) */
    protected static $conn = false; // false = hali o'rnatilmagan

    // ------------------------------------------------------------------
    // Ulanish
    // ------------------------------------------------------------------

    /**
     * Joriy ulanishni o'rnatish. TriggerBus chaqiradi.
     *
     * @param string|null $name
     * @return string|null  oldingi qiymat (qaytarib qo'yish uchun)
     */
    public static function useConnection($name)
    {
        $prev = self::$conn;
        self::$conn = $name;
        return $prev;
    }

    /** Joriy ulanish nomi */
    protected static function connName()
    {
        return self::$conn === false ? TriggerFlags::connection() : self::$conn;
    }

    /**
     * Joriy ulanish uchun so'rov quruvchisi.
     *
     * @param string $table
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function q($table)
    {
        return DB::connection(self::connName())->table($table);
    }

    /** Joriy ulanish */
    protected static function db()
    {
        return DB::connection(self::connName());
    }

    // ------------------------------------------------------------------
    // Dialekt (MySQL / PostgreSQL)
    //
    // Trigger mantig'i IKKALA bazada ham ishlashi kerak: `db_triggers.connection`
    // eski MySQL bazasiga ham (`mysql`, `mysql1`), yangi PostgreSQL bazasiga ham
    // (`pgsql`, `pgsql1`) qaratilishi mumkin. Dialektga bog'liq SQL FAQAT shu
    // bo'limda — trigger klasslari uni bilmaydi.
    //
    // DIQQAT: getDriverName() ulanish OCHMAYDI — u config dagi `driver` ni
    // qaytaradi, ya'ni dry-run da ham to'g'ri dialekt tanlanadi.
    // ------------------------------------------------------------------

    /** Joriy ulanish PostgreSQL'mi */
    protected static function isPg()
    {
        try {
            return self::db()->getDriverName() === 'pgsql';
        } catch (\Exception $e) {
            return false; // ulanish sozlanmagan bo'lsa eski xatti-harakat (MySQL)
        }
    }

    /** Identifikatorni dialektga mos qavsga olish: `x` yoki "x" */
    protected static function qi($ident)
    {
        return self::isPg() ? '"' . $ident . '"' : '`' . $ident . '`';
    }

    /**
     * Satrni butun songa aylantiruvchi ifoda (raqam bo'lmasa 0).
     *
     * MySQL: CONVERT(kod, UNSIGNED INTEGER) — raqamsiz satrni jimgina 0 qiladi.
     * PG:    ::bigint yiqilardi, shuning uchun regexp bilan qorovullangan CASE.
     */
    protected static function toUInt($column)
    {
        return self::isPg()
            ? "CASE WHEN " . $column . " ~ '^[0-9]+$' THEN " . $column . "::bigint ELSE 0 END"
            : 'CONVERT(' . $column . ', UNSIGNED INTEGER)';
    }

    // ------------------------------------------------------------------
    // Qator (row) bilan ishlash
    // ------------------------------------------------------------------

    /**
     * stdClass / Model / massivni oddiy massivga keltirish.
     *
     * @param mixed $row
     * @return array
     */
    protected static function row($row)
    {
        if (is_array($row)) return $row;
        if ($row instanceof \Illuminate\Database\Eloquent\Model) return $row->getAttributes();
        if (is_object($row)) return get_object_vars($row);
        return [];
    }

    /**
     * NEW.x / OLD.x o'qish.
     */
    protected static function val($row, $key, $default = null)
    {
        $r = self::row($row);
        return array_key_exists($key, $r) ? $r[$key] : $default;
    }

    /**
     * AFTER UPDATE uchun haqiqiy NEW qatorini yasash:
     * eski qator + UPDATE da berilgan ustunlar.
     */
    protected static function effective($newPartial, $old)
    {
        return array_merge(self::row($old), self::row($newPartial));
    }

    /** MySQL `a != b` semantikasi: bir tomon NULL bo'lsa — FALSE. */
    protected static function neq($a, $b)
    {
        if ($a === null || $b === null) return false;
        return $a != $b;
    }

    /** MySQL `a = b` semantikasi: bir tomon NULL bo'lsa — FALSE. */
    protected static function eq($a, $b)
    {
        if ($a === null || $b === null) return false;
        return $a == $b;
    }

    /** MySQL IFNULL(a, b) */
    protected static function ifnull($a, $b)
    {
        return $a === null ? $b : $a;
    }

    /**
     * MySQL `if (x)` rostlik tekshiruvi — `if new.status = true then`.
     * NULL, 0, '0', '' → false; qolgani true.
     */
    protected static function truthy($v)
    {
        if ($v === null) return false;
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((float) $v) != 0.0;
        return $v !== '' && $v !== '0';
    }

    /** CURRENT_DATE */
    protected static function today()
    {
        return date('Y-m-d');
    }

    /** CAST(x AS DATE) */
    protected static function toDate($v)
    {
        if ($v === null || $v === '') return null;
        $ts = is_numeric($v) ? (int) $v : strtotime($v);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }

    /** MySQL MONTH(x) */
    protected static function monthOf($v)
    {
        if ($v === null || $v === '') return null;
        $ts = is_numeric($v) ? (int) $v : strtotime($v);
        if ($ts === false) return null;
        return (int) date('n', $ts);
    }

    /**
     * MySQL LPAD(str, len, pad) — uzun bo'lsa chapdan `len` ta belgi.
     */
    protected static function lpad($str, $len, $pad)
    {
        $str = (string) $str;
        if (mb_strlen($str) >= $len) return mb_substr($str, 0, $len);
        return str_pad($str, $len, $pad, STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------
    // Bayroq / bajarish
    // ------------------------------------------------------------------

    protected static function on($trigger)
    {
        return TriggerFlags::enabled($trigger);
    }

    /**
     * Trigger tanasini bajarish: bayroq tekshiruvi + xato boshqaruvi.
     *
     * @param string   $trigger
     * @param callable $body
     * @param mixed    $whenOff  bayroq o'chiq bo'lsa qaytariladigan qiymat
     * @return mixed
     */
    protected static function fire($trigger, callable $body, $whenOff = null)
    {
        if (!self::on($trigger)) return $whenOff;

        try {
            return $body();
        } catch (\Throwable $e) {
            if (TriggerFlags::failOpen()) {
                \Log::error(self::LOG_TAG . " [$trigger] XATO (fail_open): " . $e->getMessage());
                return $whenOff;
            }
            \Log::error(self::LOG_TAG . " [$trigger] XATO: " . $e->getMessage());
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Yozish yordamchilari (dry_run va log shu yerda)
    // ------------------------------------------------------------------

    protected static function log($trigger, $message)
    {
        if (!TriggerFlags::isLogging()) return;
        $mode = TriggerFlags::isDryRun() ? 'DRY' : 'RUN';
        $conn = self::connName();
        \Log::info(self::LOG_TAG . " [$trigger] $mode " . ($conn ? "($conn) " : '') . $message);
    }

    /** INSERT INTO $table ... */
    protected static function ins($trigger, $table, array $row)
    {
        self::log($trigger, "INSERT $table " . self::brief($row));
        if (TriggerFlags::isDryRun()) return true;
        return self::q($table)->insert($row);
    }

    /**
     * "Dublikat bo'lsa jim o'tkazib yubor" INSERT.
     *
     * MySQL: `INSERT IGNORE`
     * PG:    `ON CONFLICT DO NOTHING` — konflikt maqsadi ko'rsatilmagani uchun
     *        HAR QANDAY unique/exclusion cheklovi buzilganda qator tashlanadi.
     *
     * ⚠ To'liq ekvivalent EMAS: `INSERT IGNORE` ma'lumot xatolarini ham
     *   (uzun satr, noto'g'ri sana) yutib, qatorni buzib yozardi; PG da bunday
     *   xato baribir otiladi. Bu ATAYLAB — jim ma'lumot buzilishi yo'q.
     */
    protected static function insIgnore($trigger, $table, array $row)
    {
        $cols = array_keys($row);
        $ph   = implode(',', array_fill(0, count($cols), '?'));

        if (self::isPg()) {
            $sql = 'INSERT INTO ' . self::qi($table) . ' ("' . implode('","', $cols) . '") VALUES ('
                 . $ph . ') ON CONFLICT DO NOTHING';
        } else {
            $sql = 'INSERT IGNORE INTO ' . self::qi($table) . ' (`' . implode('`,`', $cols) . '`) VALUES ('
                 . $ph . ')';
        }

        self::log($trigger, (self::isPg() ? 'INSERT ... ON CONFLICT DO NOTHING ' : 'INSERT IGNORE ')
            . $table . ' ' . self::brief($row));
        if (TriggerFlags::isDryRun()) return true;

        return self::db()->insert($sql, array_values($row));
    }

    /**
     * Upsert — MySQL `ON DUPLICATE KEY UPDATE`, PG `ON CONFLICT ... DO UPDATE`.
     *
     * `$bumps` har doim `ustun = <o'sha ustunning MAVJUD qiymati> <ifoda>`
     * ko'rinishida bo'ladi (bazadagi triggerlarning hammasi shunday):
     *
     *     ['amount_total' => '+ ?', 'total_qty' => '+ 1']
     *
     * MySQL da o'ng tomondagi yalang'och ustun nomi mavjud qiymatni bildiradi;
     * PG da esa uni `jadval.ustun` deb yozish SHART, aks holda PG uni
     * INSERT qilinayotgan YANGI qiymat deb tushunadi.
     *
     * `$conflict` — PG uchun konflikt ustunlari (odatda birlamchi kalit).
     * MySQL da bu ro'yxat ishlatilmaydi (u kalitni o'zi topadi).
     *
     * @param string $columns  "(a, b, c)"
     * @param string $values   "(?, ?, (SELECT ...))"
     * @return bool
     */
    protected static function upsert($trigger, $table, $columns, $values, array $conflict, array $bumps, array $bind = [], $label = null)
    {
        $existing = self::isPg() ? $table . '.' : '';

        $sets = [];
        foreach ($bumps as $col => $expr) {
            $sets[] = $col . ' = ' . $existing . $col . ' ' . $expr;
        }

        $tail = self::isPg()
            ? 'ON CONFLICT (' . implode(', ', $conflict) . ') DO UPDATE SET ' . implode(', ', $sets)
            : 'ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);

        return self::stmt(
            $trigger,
            'INSERT INTO ' . $table . ' ' . $columns . ' VALUES ' . $values . ' ' . $tail,
            $bind,
            $label
        );
    }

    /** UPDATE $table SET ... WHERE ... (where — oddiy `ustun => qiymat`) */
    protected static function upd($trigger, $table, array $where, array $values)
    {
        self::log($trigger, "UPDATE $table SET " . self::brief($values) . ' WHERE ' . self::brief($where));
        if (TriggerFlags::isDryRun()) return 0;
        return self::q($table)->where($where)->update($values);
    }

    /** DELETE FROM $table WHERE ... */
    protected static function del($trigger, $table, array $where)
    {
        self::log($trigger, "DELETE $table WHERE " . self::brief($where));
        if (TriggerFlags::isDryRun()) return 0;
        return self::q($table)->where($where)->delete();
    }

    /** Xom SQL (ON DUPLICATE KEY UPDATE va shunga o'xshash holatlar uchun) */
    protected static function stmt($trigger, $sql, array $bind = [], $label = null)
    {
        self::log($trigger, ($label ? $label . ' :: ' : '') . 'SQL ' . preg_replace('/\s+/', ' ', $sql)
            . ' [' . implode(', ', array_map(function ($v) { return $v === null ? 'NULL' : $v; }, $bind)) . ']');
        if (TriggerFlags::isDryRun()) return true;
        return self::db()->statement($sql, $bind);
    }

    /**
     * tb_statistics hisoblagichini o'zgartirish.
     * SQL: UPDATE tb_statistics SET qty = qty+$delta WHERE name=$name AND <extra>
     */
    protected static function statBump($trigger, $name, array $where, $delta)
    {
        $sign = $delta >= 0 ? '+' : '-';
        $abs  = abs((int) $delta);

        return self::upd(
            $trigger,
            'tb_statistics',
            array_merge(['name' => $name], $where),
            ['qty' => DB::raw('qty ' . $sign . ' ' . $abs)]
        );
    }

    /** Log uchun qisqartirilgan massiv ko'rinishi */
    private static function brief(array $data)
    {
        $parts = [];
        foreach ($data as $k => $v) {
            if ($v instanceof \Illuminate\Database\Query\Expression) {
                // L13: Expression::getValue() endi Grammar talab qiladi (__toString() olib tashlangan).
                $v = (string) $v->getValue(DB::connection(self::connName())->getQueryGrammar());
            } elseif (is_null($v)) {
                $v = 'NULL';
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif (is_string($v) && mb_strlen($v) > 40) {
                $v = mb_substr($v, 0, 40) . '…';
            }
            $parts[] = $k . '=' . $v;
        }
        return '{' . implode(', ', $parts) . '}';
    }
}
