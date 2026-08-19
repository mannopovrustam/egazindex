<?php

namespace App\Services\DualWrite;

use DB;

/**
 * Hisoblagichli upsert — "qator bo'lmasa qo'sh, bo'lsa hisoblagichlarga qo'sh".
 *
 * Bu naqsh agregat jadvallarda ishlatiladi (i_money_orgs, idx_dayli_by_orgs):
 *
 *   MySQL: INSERT INTO t (...) VALUES (...) ON DUPLICATE KEY UPDATE
 *              amount_click = t.amount_click + ?, qty_click = t.qty_click + 1
 *   PG:    INSERT INTO t (...) VALUES (...) ON CONFLICT (dt, yy, id_org) DO UPDATE SET
 *              amount_click = t.amount_click + ?, qty_click = t.qty_click + 1
 *
 * ⚠ NEGA ALOHIDA KLASS: bu amal so'rov quruvchisidan o'tmaydi (xom SQL),
 *   ya'ni App\Database\DualWrite\Builder uni USHLAY OLMAYDI. Shuning uchun
 *   nusxani o'zi uzatadi. Ilovada xom SQL bilan yozadigan joy faqat shular:
 *       app/Actions/IMoneyDebit.php          (i_money_orgs)
 *       app/Console/Commands/calcTransanctions.php (idx_dayli_by_orgs, 2 joy)
 *
 * `$sums` dagi ustunlar uchun qo'shiladigan qiymat AYNAN `$row` dagi qiymat
 * bo'ladi — bazadagi eski SQL lar ham shunday edi (`qty_x` ga 1 yozilib,
 * konfliktda `qty_x + 1` qilinardi).
 */
class CounterUpsert
{
    /**
     * Asosiy bazaga yozib, nusxaga uzatish.
     *
     * @param string      $table       jadval
     * @param array       $row         ustun => qiymat (bog'lanadi, xom SQL emas)
     * @param array       $conflict    PG uchun konflikt ustunlari (birlamchi kalit)
     * @param array       $sums        konfliktda O'SADIGAN ustunlar ro'yxati
     * @param string|null $connection  asosiy ulanish (null = standart)
     * @return bool
     */
    public static function run($table, array $row, array $conflict, array $sums, $connection = null)
    {
        $primary = DualWrite::resolve($connection);

        $ok = self::exec($primary, $table, $row, $conflict, $sums);

        if ($ok && DualWrite::shouldMirror($primary, $table)) {
            DualWrite::dispatch($primary, [
                'type'     => 'counterUpsert',
                'table'    => $table,
                'row'      => $row,
                'conflict' => $conflict,
                'sums'     => $sums,
            ]);
        }

        return $ok;
    }

    /**
     * Nusxa tomonda bajarish (Mirror chaqiradi).
     *
     * Nusxadagi qo'shimcha ustunlar (`id`, `created_at`) INSERT qismiga
     * qo'shiladi; `sums` dan nusxada yo'q ustunlar tashlanadi.
     *
     * @return void
     */
    public static function mirror($mirror, $table, array $op, array $cols)
    {
        $row  = [];
        $drop = [];

        foreach ($op['row'] as $k => $v) {
            if (isset($cols[$k])) $row[$k] = $v; else $drop[] = $k;
        }

        if ($drop) {
            DualWrite::noteOnce('drop|' . $mirror . '|' . $table . '|' . implode(',', $drop),
                "[$table] nusxada bunday ustun(lar) yo`q, tashlab ketildi: " . implode(', ', $drop));
        }

        $idCol = config('dual_write.id_column', 'id');
        $tsCol = config('dual_write.created_at_column', 'created_at');

        // Agregat jadvalda ham `id` NOT NULL (serial emas) bo'lishi mumkin.
        if (isset($cols[$idCol]) && !array_key_exists($idCol, $row)
            && MirrorSchema::mustProvide($cols[$idCol])
            && (bool) config('dual_write.generate_missing_id', true)) {
            $row[$idCol] = MirrorSchema::nextId($mirror, $table, $idCol);
        }

        if (isset($cols[$tsCol]) && !array_key_exists($tsCol, $row)) {
            $row[$tsCol] = date('Y-m-d H:i:s');
        }

        $sums     = array_values(array_filter($op['sums'], function ($c) use ($cols) { return isset($cols[$c]); }));
        $conflict = array_values(array_filter($op['conflict'], function ($c) use ($cols) { return isset($cols[$c]); }));

        DualWrite::log("[$table] UPSERT nusxa (hisoblagich: " . implode(', ', $sums) . ')');
        if (DualWrite::isDryRun()) return;

        self::exec($mirror, $table, $row, $conflict, $sums);
    }

    /**
     * Bitta ulanishda bajarish — SQL ulanish dialektiga qarab quriladi.
     *
     * @return bool
     */
    protected static function exec($connection, $table, array $row, array $conflict, array $sums)
    {
        $conn = DB::connection($connection);
        $cols = array_keys($row);

        $sql = 'insert into ' . $table . ' (' . implode(', ', $cols) . ') values ('
             . implode(', ', array_fill(0, count($cols), '?')) . ') ';

        $bindings = array_values($row);

        // Konfliktda o'sadigan ustunlar. O'ng tomonda ustun JADVAL NOMI bilan
        // yoziladi — bu MySQL da ham, PG da ham "MAVJUD qator qiymati" degani
        // (PG da qavssiz yozilsa u INSERT qilinayotgan yangi qiymatni tushunadi).
        $sets = [];
        foreach ($sums as $col) {
            if (!array_key_exists($col, $row)) continue;
            $sets[]     = $col . ' = ' . $table . '.' . $col . ' + ?';
            $bindings[] = $row[$col];
        }

        if (!$sets) {
            // O'sadigan ustun yo'q — dublikatda hech narsa qilinmaydi.
            $sql .= $conn->getDriverName() === 'pgsql'
                ? 'on conflict do nothing'
                : 'on duplicate key update ' . $cols[0] . ' = ' . $table . '.' . $cols[0];
        } elseif ($conn->getDriverName() === 'pgsql') {
            $sql .= 'on conflict (' . implode(', ', $conflict) . ') do update set ' . implode(', ', $sets);
        } else {
            $sql .= 'on duplicate key update ' . implode(', ', $sets);
        }

        return $conn->insert($sql, $bindings);
    }
}
