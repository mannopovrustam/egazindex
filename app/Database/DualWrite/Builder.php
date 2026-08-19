<?php

namespace App\Database\DualWrite;

use Illuminate\Database\Query\Builder as BaseBuilder;
use App\Services\DualWrite\DualWrite;

/**
 * Yozish amallarini "ushlab qoladigan" so'rov quruvchisi.
 *
 * MySQL ulanishlari uchun `Connection::query()` AYNAN shu klassni qaytaradi
 * (App\Database\DualWrite\MySqlConnection), shuning uchun ilovadagi oddiy
 *
 *     DB::table('i_real_details')->insert($row);
 *
 * chaqiruvi hech qanday o'zgarishsiz PostgreSQL nusxasiga ham yoziladi.
 * Chaqiruv joylari (app/ ichidagi 40+ joy) TEGILMAGAN.
 *
 * QOIDA: nusxa FAQAT asosiy amal muvaffaqiyatli bo'lganda uzatiladi, va
 * HAR DOIM asosiy amaldan KEYIN — ya'ni MySQL birlamchi manba (source of
 * truth), PostgreSQL esa nusxa.
 *
 * O'QISH amallari (select) teginilmaydi — ular oldingidek faqat shu ulanishga
 * boradi.
 *
 * @see config/dual_write.php
 * @see docs/dual-write.md
 */
class Builder extends BaseBuilder
{
    /**
     * Ichki (nested) amal nusxalanmasin.
     *
     * increment() / decrement() ichida update() chaqiriladi. Agar ikkisi ham
     * nusxalansa amal PG da IKKI MARTA bajarilardi — shuning uchun tashqi
     * metod ichkarisini vaqtincha o'chirib qo'yadi.
     */
    protected $mirrorSuppressed = false;

    // ------------------------------------------------------------------
    // INSERT
    // ------------------------------------------------------------------

    /** @inheritDoc */
    public function insert(array $values)
    {
        $ok = parent::insert($values);

        if ($ok && $this->mirrors()) {
            $rows = $this->rows($values);

            $this->send([
                'type'     => 'insert',
                'rows'     => $rows,
                'first_id' => count($rows) ? $this->lastInsertId() : null,
            ]);
        }

        return $ok;
    }

    /** @inheritDoc */
    public function insertOrIgnore(array $values)
    {
        $affected = parent::insertOrIgnore($values);

        // 0 — qator "ignore" qilingan (dublikat), ya'ni nusxada ham kerak emas.
        if ($affected > 0 && $this->mirrors()) {
            $rows = $this->rows($values);

            $this->send([
                'type'     => 'insert',
                'rows'     => $rows,
                'first_id' => $this->lastInsertId(),
                'ignore'   => true,
            ]);
        }

        return $affected;
    }

    /** @inheritDoc */
    public function insertGetId(array $values, $sequence = null)
    {
        $id = parent::insertGetId($values, $sequence);

        if ($this->mirrors()) {
            $this->send([
                'type'     => 'insert',
                'rows'     => [$values],
                'first_id' => $id,
            ]);
        }

        return $id;
    }

    /**
     * INSERT ... SELECT — nusxalanmaydi.
     *
     * Sabab: tanlov (SELECT) ASOSIY bazada bajariladi, nusxa bazada esa
     * natijasi boshqa bo'lishi mumkin. Bunday amalni ko'chirish uchun
     * `php artisan pg:sync` ishlatiladi.
     */
    public function insertUsing(array $columns, $query)
    {
        $affected = parent::insertUsing($columns, $query);

        if ($affected > 0 && $this->mirrors()) {
            DualWrite::noteOnce('insertUsing|' . $this->from,
                '[' . $this->from . '] INSERT..SELECT nusxalanmaydi (natija ikki bazada'
                . ' farq qilishi mumkin) — kerak bo`lsa `pg:sync` ishlating.');
        }

        return $affected;
    }

    // ------------------------------------------------------------------
    // UPDATE
    // ------------------------------------------------------------------

    /** @inheritDoc */
    public function update(array $values)
    {
        $affected = parent::update($values);

        if ($affected > 0 && $this->mirrors()) {
            $this->send(['type' => 'update', 'values' => $values] + $this->conditions());
        }

        return $affected;
    }

    /** @inheritDoc */
    public function upsert(array $values, $uniqueBy, ?array $update = null)
    {
        $affected = parent::upsert($values, $uniqueBy, $update);

        if ($affected > 0 && $this->mirrors()) {
            $this->send([
                'type'     => 'upsert',
                'values'   => $this->rows($values),
                'uniqueBy' => $uniqueBy,
                'update'   => $update,
            ]);
        }

        return $affected;
    }

    /**
     * Ustunlarni oshirish.
     *
     * ⚠ NEGA `update()` ga tashlab qo'yilmadi: increment() "`ustun` + 1" degan
     *   XOM ifoda quradi va uni ASOSIY ulanish grammatikasi bilan qavslaydi
     *   (MySQL da teskari apostrof). Shu ifodani PG ga uzatish sintaksis
     *   xatosi bo'lardi — shuning uchun nusxada amal QAYTA quriladi.
     *
     * `increment()` / `decrement()` shu metodga kelib tushadi, shuning uchun
     * ular alohida ushlanmaydi.
     *
     * @inheritDoc
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $affected = $this->withoutMirror(function () use ($columns, $extra) {
            return parent::incrementEach($columns, $extra);
        });

        if ($affected > 0 && $this->mirrors()) {
            $this->send([
                'type'    => 'increment',
                'columns' => $columns,
                'extra'   => $extra,
            ] + $this->conditions());
        }

        return $affected;
    }

    /** @inheritDoc */
    public function decrementEach(array $columns, array $extra = [])
    {
        $affected = $this->withoutMirror(function () use ($columns, $extra) {
            return parent::decrementEach($columns, $extra);
        });

        if ($affected > 0 && $this->mirrors()) {
            // Nusxada har doim incrementEach ishlatiladi — miqdor manfiy bo'ladi
            // ("ustun + -5" ikkala dialektda ham to'g'ri SQL).
            $signed = [];
            foreach ($columns as $col => $amount) {
                $signed[$col] = -$amount;
            }

            $this->send([
                'type'    => 'increment',
                'columns' => $signed,
                'extra'   => $extra,
            ] + $this->conditions());
        }

        return $affected;
    }

    // ------------------------------------------------------------------
    // DELETE / TRUNCATE
    // ------------------------------------------------------------------

    /** @inheritDoc */
    public function delete($id = null)
    {
        $affected = parent::delete($id);

        // DIQQAT: shartlar parent::delete() dan KEYIN olinadi — `delete($id)`
        // ko'rinishida u `where id = ?` ni O'ZI qo'shadi.
        if ($affected > 0 && $this->mirrors()) {
            $this->send(['type' => 'delete'] + $this->conditions());
        }

        return $affected;
    }

    /** @inheritDoc */
    public function truncate()
    {
        parent::truncate();

        if ($this->mirrors()) {
            $this->send(['type' => 'truncate']);
        }
    }

    // ------------------------------------------------------------------
    // Yordamchilar
    // ------------------------------------------------------------------

    /** Shu jadval uchun nusxa olinadimi */
    protected function mirrors()
    {
        if ($this->mirrorSuppressed) return false;

        return DualWrite::shouldMirror($this->connection->getName(), $this->from);
    }

    /** Nusxa amalini uzatish */
    protected function send(array $op)
    {
        $op['table'] = DualWrite::plainTable($this->from);

        DualWrite::dispatch($this->connection->getName(), $op);
    }

    /**
     * Amaldagi shartlar (WHERE) — nusxada AYNAN shu shart ishlatiladi.
     *
     * @return array
     */
    protected function conditions()
    {
        $bindings = $this->getRawBindings();

        return [
            'wheres'   => $this->wheres,
            'bindings' => isset($bindings['where']) ? $bindings['where'] : [],
        ];
    }

    /**
     * INSERT qiymatlarini bir xil ko'rinishga keltirish: qatorlar RO'YXATI.
     *
     * @return array[]
     */
    protected function rows(array $values)
    {
        if (empty($values)) return [];

        // Bitta qator (['a' => 1]) yoki qatorlar ro'yxati ([['a' => 1], ...]).
        return is_array(reset($values)) ? array_values($values) : [$values];
    }

    /**
     * MySQL bergan oxirgi AUTO_INCREMENT qiymati (bo'lmasa null).
     *
     * `insert()` id qaytarmaydi, lekin nusxada id AYNAN bir xil bo'lishi uchun
     * uni PDO dan olamiz. AUTO_INCREMENT ustuni bo'lmagan jadvalda MySQL "0"
     * qaytaradi — bu holda null beramiz (PG o'z serial ini ishlatadi).
     *
     * @return int|null
     */
    protected function lastInsertId()
    {
        if (!(bool) config('dual_write.copy_insert_id', true)) return null;

        try {
            $id = (int) $this->connection->getPdo()->lastInsertId();

            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ichki amal nusxalanmasin (increment/decrement uchun).
     *
     * @return mixed
     */
    protected function withoutMirror(callable $callback)
    {
        $prev = $this->mirrorSuppressed;
        $this->mirrorSuppressed = true;

        try {
            return $callback();
        } finally {
            $this->mirrorSuppressed = $prev;
        }
    }
}
