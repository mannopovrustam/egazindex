<?php

namespace App\Services\DualWrite;

use DB;
use Schema;

/**
 * Nusxa bazasidagi jadval ustunlari haqidagi ma'lumot (keshlangan).
 *
 * NEGA KERAK: MySQL va PostgreSQL sxemalari 1:1 emas — PG tomonda qo'shimcha
 * `id` / `created_at` bor, ba'zi ustunlar esa boshqacha nomlangan yoki
 * yo'q. Nusxa yozishdan oldin qatorni AYNAN nusxa jadvaldagi ustunlarga
 * moslash kerak, aks holda "column does not exist" xatosi chiqadi.
 *
 * Kesh process (so'rov / komanda) davomida saqlanadi — har bir jadval uchun
 * information_schema faqat BIR MARTA o'qiladi.
 */
class MirrorSchema
{
    /** "ulanish|jadval" => (ustun => meta) | null (jadval yo'q) */
    protected static $cache = [];

    /**
     * Nusxa jadvalning ustunlari: nom => ['nullable', 'default', 'auto_increment', 'type'].
     * Jadval bo'lmasa NULL.
     *
     * @param string $connection
     * @param string $table
     * @return array|null
     */
    public static function columns($connection, $table)
    {
        $table = DualWrite::plainTable($table);
        $key   = $connection . '|' . $table;

        if (array_key_exists($key, self::$cache)) return self::$cache[$key];

        try {
            $builder = Schema::connection($connection);

            if (!$builder->hasTable($table)) {
                return self::$cache[$key] = null;
            }

            $out = [];
            foreach ($builder->getColumns($table) as $col) {
                $out[$col['name']] = [
                    'type'           => isset($col['type_name']) ? $col['type_name'] : null,
                    'nullable'       => !empty($col['nullable']),
                    'default'        => isset($col['default']) ? $col['default'] : null,
                    'auto_increment' => !empty($col['auto_increment']),
                ];
            }

            return self::$cache[$key] = $out;
        } catch (\Throwable $e) {
            // Ulanish yo'q / huquq yetmadi — nusxa olishni to'xtatmaymiz,
            // Mirror bu holatni "jadval yo'q" deb qabul qiladi va ogohlantiradi.
            DualWrite::noteOnce('schema|' . $key, "nusxa sxemasi o`qilmadi [$connection.$table]: "
                . $e->getMessage());

            return self::$cache[$key] = null;
        }
    }

    /**
     * Ustun nusxa jadvalda bormi.
     *
     * @return bool
     */
    public static function has($connection, $table, $column)
    {
        $cols = self::columns($connection, $table);

        return $cols !== null && isset($cols[$column]);
    }

    /**
     * Ustun qiymatini BERISH SHART bo'lgan holat: NOT NULL, standart qiymati
     * ham yo'q, serial/identity ham emas.
     *
     * @param array $meta
     * @return bool
     */
    public static function mustProvide(array $meta)
    {
        return !$meta['auto_increment']
            && $meta['default'] === null
            && !$meta['nullable'];
    }

    /**
     * Keyingi bo'sh `id`: MAX(id) + 1.
     *
     * ⚠ Poyga (race) ga moyil — bir vaqtda ikki process yozsa ikkisi ham bir
     *   xil id oladi. Faqat nusxa jadvalda `id` NOT NULL bo'lib, serial ham
     *   bo'lmaganda ishlatiladi (config: generate_missing_id).
     *
     * @return int
     */
    public static function nextId($connection, $table, $idColumn = 'id')
    {
        $row = DB::connection($connection)->selectOne(
            'select coalesce(max(' . $idColumn . '), 0) + 1 as v from ' . $table
        );

        $next = $row && isset($row->v) ? (int) $row->v : 1;

        DualWrite::noteOnce('nextid|' . $connection . '|' . $table,
            "[$table] nusxadagi `$idColumn` NOT NULL va serial emas — id MAX()+1 bilan"
            . ' hisoblanmoqda. PG tomonda ustunni serial/identity qilish tavsiya etiladi.');

        return $next;
    }

    /**
     * Serial ketma-ketlik (sequence) MAX(id) dan orqada qolganmi.
     * `dual:status` uchun; yozish yo'lida chaqirilmaydi.
     *
     * @return array|null  ['sequence' => nom, 'last' => int, 'max' => int] | null
     */
    public static function sequenceLag($connection, $table, $idColumn = 'id')
    {
        try {
            $conn = DB::connection($connection);
            if ($conn->getDriverName() !== 'pgsql') return null;

            $seq = $conn->selectOne(
                'select pg_get_serial_sequence(?, ?) as seq', [$table, $idColumn]
            );
            if (!$seq || !$seq->seq) return null;

            $row = $conn->selectOne(
                'select (select last_value from ' . $seq->seq . ') as last_value, '
                . '(select coalesce(max(' . $idColumn . '), 0) from ' . $table . ') as max_id'
            );

            return [
                'sequence' => $seq->seq,
                'last'     => (int) $row->last_value,
                'max'      => (int) $row->max_id,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** FAQAT test/tinker uchun */
    public static function flush()
    {
        self::$cache = [];
    }
}
