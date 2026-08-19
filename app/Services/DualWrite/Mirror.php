<?php

namespace App\Services\DualWrite;

use DB;
use App\Services\DbTriggers\TriggerBus;

/**
 * Nusxa yozuvchi: asosiy (MySQL) bazada bajarilgan amalni PostgreSQL
 * nusxasida takrorlaydi.
 *
 * Amal turlari (`type`):
 *   insert        — qator(lar)ni yozish; nusxaga `id` va `created_at` qo'shiladi
 *   update        — AYNAN shu shart (`wheres`) bo'yicha yangilash
 *   delete        — AYNAN shu shart bo'yicha o'chirish
 *   truncate      — jadvalni bo'shatish
 *   increment     — ustunni oshirish/kamaytirish (dialektga bog'liq xom SQL emas)
 *   upsert        — so'rov quruvchisining upsert() i
 *   counterUpsert — hisoblagichli xom upsert (CounterUpsert klassi)
 *
 * DIALEKT: hamma joyda Laravel so'rov quruvchisi ishlatiladi — SQL ni nusxa
 * ulanishining O'Z grammatikasi quradi. Ya'ni MySQL sintaksisi PG ga
 * "tarjima" qilinmaydi, amal QAYTA quriladi. Xom SQL faqat CounterUpsert da,
 * u ham har bir dialekt uchun alohida yoziladi.
 *
 * @see config/dual_write.php
 * @see docs/dual-write.md
 */
class Mirror
{
    /**
     * Amalni nusxa ulanishida bajarish.
     *
     * @param array $op
     * @return void
     */
    public static function apply(array $op)
    {
        $primary = isset($op['primary']) ? $op['primary'] : null;
        $mirror  = DualWrite::mirrorOf($primary);
        if (!$mirror) return;

        $table = DualWrite::plainTable($op['table']);
        $cols  = MirrorSchema::columns($mirror, $table);

        if ($cols === null) {
            DualWrite::noteOnce('notable|' . $mirror . '|' . $table,
                "[$table] nusxa bazada ($mirror) bunday jadval yo`q — nusxa olinmadi.");
            return;
        }

        switch ($op['type']) {
            case 'insert':
                self::doInsert($mirror, $table, $op, $cols);
                break;

            case 'update':
                self::doUpdate($mirror, $table, $op, $cols);
                break;

            case 'delete':
                self::doDelete($mirror, $table, $op);
                break;

            case 'truncate':
                self::doTruncate($mirror, $table);
                break;

            case 'increment':
                self::doIncrement($mirror, $table, $op, $cols);
                break;

            case 'upsert':
                self::doUpsert($mirror, $table, $op, $cols);
                break;

            case 'counterUpsert':
                CounterUpsert::mirror($mirror, $table, $op, $cols);
                break;
        }
    }

    // ------------------------------------------------------------------
    // INSERT
    // ------------------------------------------------------------------

    /**
     * Nusxaga qator(lar) yozish.
     *
     * Har bir qator uchun tartib MySQL dagi FOR EACH ROW semantikasi bilan
     * bir xil: BEFORE INSERT triggerlari → INSERT → AFTER INSERT triggerlari.
     * Triggerlar NUSXA ulanishida ishlaydi (PG da DB trigger yo'q).
     */
    protected static function doInsert($mirror, $table, array $op, array $cols)
    {
        $rows    = $op['rows'];
        $firstId = isset($op['first_id']) ? $op['first_id'] : null;

        // Ko'p qatorli INSERT da MySQL faqat BIRINCHI id ni beradi; qolganlari
        // ketma-ket bo'lishiga kafolat yo'q → standart holda id ko'chirilmaydi.
        $useIds = $firstId !== null
            && (bool) config('dual_write.copy_insert_id', true)
            && (count($rows) === 1 || (bool) config('dual_write.bulk_ids', false));

        $i = 0;
        foreach ($rows as $row) {
            $row = (array) $row;

            if (DualWrite::triggersEnabled()) {
                $row = TriggerBus::beforeInsert($table, $row, $mirror);
            }

            $id = $useIds ? ((int) $firstId + $i) : null;
            $mirrorRow = self::shapeRow($mirror, $table, $row, $cols, $id);

            DualWrite::log("[$table] INSERT nusxa " . self::brief($mirrorRow));

            if (!DualWrite::isDryRun()) {
                DB::connection($mirror)->table($table)->insert($mirrorRow);
            }

            if (DualWrite::triggersEnabled()) {
                // AFTER INSERT ga to'liq qator beriladi (id ham) — MySQL dagidek.
                TriggerBus::afterInsert($table, array_merge($row, $mirrorRow), $mirror);
            }

            $i++;
        }
    }

    /**
     * Qatorni nusxa jadvalga moslash:
     *   1. nusxada MAVJUD BO'LMAGAN ustunlar tashlanadi;
     *   2. `id` qo'shiladi (MySQL bergani, yoki MAX()+1 — kerak bo'lsa);
     *   3. `created_at` qo'shiladi (qatorda bo'lmasa).
     *
     * @return array
     */
    protected static function shapeRow($mirror, $table, array $row, array $cols, $id = null)
    {
        $idCol = config('dual_write.id_column', 'id');
        $tsCol = config('dual_write.created_at_column', 'created_at');

        $out = [];
        $dropped = [];

        foreach ($row as $key => $value) {
            if (isset($cols[$key])) {
                $out[$key] = $value;
            } else {
                $dropped[] = $key;
            }
        }

        if ($dropped) {
            DualWrite::noteOnce('drop|' . $mirror . '|' . $table . '|' . implode(',', $dropped),
                "[$table] nusxada bunday ustun(lar) yo`q, tashlab ketildi: " . implode(', ', $dropped));
        }

        // --- id ---------------------------------------------------------
        if (isset($cols[$idCol]) && !array_key_exists($idCol, $out)) {
            if ($id !== null && $id > 0) {
                // MySQL bergan AUTO_INCREMENT — ikki bazada id AYNAN bir xil.
                $out[$idCol] = $id;
            } elseif (MirrorSchema::mustProvide($cols[$idCol])
                && (bool) config('dual_write.generate_missing_id', true)) {
                $out[$idCol] = MirrorSchema::nextId($mirror, $table, $idCol);
            }
            // Aks holda: PG dagi serial/identity o'zi beradi.
        }

        // --- created_at -------------------------------------------------
        if (isset($cols[$tsCol]) && !array_key_exists($tsCol, $out)) {
            $out[$tsCol] = date('Y-m-d H:i:s');
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // UPDATE / DELETE / TRUNCATE
    // ------------------------------------------------------------------

    protected static function doUpdate($mirror, $table, array $op, array $cols)
    {
        $values = self::onlyExisting($mirror, $table, $op['values'], $cols);
        if (!$values) return;

        // Xom ifoda (DB::raw) ASOSIY baza grammatikasi bilan qurilgan bo'lishi
        // mumkin (MySQL da teskari apostrof) — u PG da sintaksis xatosi beradi.
        // increment/decrement bundan xoli (ular alohida ushlanadi), qolgan
        // hollarda ogohlantiramiz.
        foreach ($values as $key => $value) {
            if ($value instanceof \Illuminate\Contracts\Database\Query\Expression) {
                DualWrite::noteOnce('rawval|' . $mirror . '|' . $table . '|' . $key,
                    "[$table] UPDATE da `$key` uchun XOM ifoda berilgan — nusxada u"
                    . ' o`zgarishsiz ishlatiladi, dialektga mos bo`lishi shart.');
            }
        }

        DualWrite::log("[$table] UPDATE nusxa " . self::brief($values));
        if (DualWrite::isDryRun()) return;

        $q = self::query($mirror, $table, $op);

        if (DualWrite::triggersEnabled() && TriggerBus::active($table, TriggerBus::AFTER_UPDATE, $mirror)) {
            // Triggerli jadval: eski qatorlar kerak → TriggerBus o'zi bajaradi.
            TriggerBus::update($table, function ($b) use ($op) {
                self::copyWheres($b, $op);
            }, $values, $mirror);
            return;
        }

        $q->update($values);
    }

    protected static function doDelete($mirror, $table, array $op)
    {
        DualWrite::log("[$table] DELETE nusxa");
        if (DualWrite::isDryRun()) return;

        if (DualWrite::triggersEnabled() && TriggerBus::needsOld($table, $mirror)) {
            TriggerBus::delete($table, function ($b) use ($op) {
                self::copyWheres($b, $op);
            }, $mirror);
            return;
        }

        self::query($mirror, $table, $op)->delete();
    }

    protected static function doTruncate($mirror, $table)
    {
        DualWrite::log("[$table] TRUNCATE nusxa");
        if (DualWrite::isDryRun()) return;

        DB::connection($mirror)->table($table)->truncate();
    }

    protected static function doIncrement($mirror, $table, array $op, array $cols)
    {
        $columns = self::onlyExisting($mirror, $table, (array) $op['columns'], $cols);
        if (!$columns) return;

        $extra = self::onlyExisting($mirror, $table, (array) $op['extra'], $cols);

        DualWrite::log("[$table] INCREMENT nusxa " . self::brief($columns));
        if (DualWrite::isDryRun()) return;

        // Ifoda ("ustun + 1") nusxa ulanishining O'Z grammatikasi bilan
        // quriladi — MySQL dagi teskari apostroflar PG ga o'tmaydi.
        self::query($mirror, $table, $op)->incrementEach($columns, $extra);
    }

    protected static function doUpsert($mirror, $table, array $op, array $cols)
    {
        $rows = [];
        foreach ($op['values'] as $row) {
            $rows[] = self::shapeRow($mirror, $table, (array) $row, $cols);
        }
        if (!$rows) return;

        $update = $op['update'] === null
            ? null
            : array_keys(self::onlyExisting($mirror, $table, array_flip((array) $op['update']), $cols));

        DualWrite::log("[$table] UPSERT nusxa (" . count($rows) . ' qator)');
        if (DualWrite::isDryRun()) return;

        DB::connection($mirror)->table($table)->upsert($rows, $op['uniqueBy'], $update);
    }

    // ------------------------------------------------------------------
    // Yordamchilar
    // ------------------------------------------------------------------

    /**
     * Nusxa ulanishida shu jadval uchun so'rov quruvchisi — asosiy amaldagi
     * AYNAN shu shartlar bilan.
     *
     * `wheres` massivi grammatikaga bog'liq EMAS (u tuzilma: ustun, amal,
     * qiymat), shuning uchun uni PG quruvchisiga ko'chirish xavfsiz: SQL ni
     * PG grammatikasi qaytadan quradi.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function query($mirror, $table, array $op)
    {
        $q = DB::connection($mirror)->table($table);
        self::copyWheres($q, $op);

        return $q;
    }

    /**
     * Shartlarni (wheres + bindings) so'rov quruvchisiga ko'chirish.
     *
     * @param \Illuminate\Database\Query\Builder $q
     * @return void
     */
    protected static function copyWheres($q, array $op)
    {
        if (!empty($op['wheres'])) {
            $q->wheres = $op['wheres'];
            $q->bindings['where'] = isset($op['bindings']) ? (array) $op['bindings'] : [];
        }
    }

    /**
     * Massivdan nusxa jadvalda MAVJUD ustunlarni ajratib olish.
     *
     * @return array
     */
    protected static function onlyExisting($mirror, $table, array $values, array $cols)
    {
        $out = [];
        $dropped = [];

        foreach ($values as $key => $value) {
            if (isset($cols[$key])) {
                $out[$key] = $value;
            } else {
                $dropped[] = $key;
            }
        }

        if ($dropped) {
            DualWrite::noteOnce('drop|' . $mirror . '|' . $table . '|' . implode(',', $dropped),
                "[$table] nusxada bunday ustun(lar) yo`q, tashlab ketildi: " . implode(', ', $dropped));
        }

        return $out;
    }

    /**
     * Logga qisqartirilgan qator (parol/rasm kabi uzun qiymatlar kesiladi).
     *
     * @return string
     */
    protected static function brief(array $row)
    {
        $parts = [];
        foreach ($row as $k => $v) {
            if (is_object($v)) {
                $v = '(expr)';
            } elseif (is_null($v)) {
                $v = 'NULL';
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            } else {
                $v = (string) $v;
                if (mb_strlen($v) > 40) $v = mb_substr($v, 0, 40) . '…';
            }
            $parts[] = $k . '=' . $v;
        }

        return '{' . implode(', ', $parts) . '}';
    }
}
