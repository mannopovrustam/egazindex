<?php

namespace App\Services\DbTriggers;

use DB;

/**
 * egaz_idxdb DB triggerlari uchun yagona kirish nuqtasi.
 *
 * Kod joylarida faqat SHU klass chaqiriladi — qaysi jadvalga qaysi trigger
 * bog'langani shu yerda. Bayroqlar o'chiq bo'lsa hamma metod no-op:
 * BEFORE metodlari qatorni o'zgarishsiz qaytaradi, AFTER metodlari hech narsa
 * qilmaydi, `snapshot()` esa NULL qaytaradi (ortiqcha SELECT bo'lmaydi).
 *
 * ULANISH: har bir metodning oxirgi argumenti — ulanish nomi.
 *   null     → `config('db_triggers.connection')` (standart: `mysql` = egaz_idxdb)
 *   'pgsql'  → PostgreSQL nusxasi (Mirror shu ulanish bilan chaqiradi)
 *   'mysql1' / 'pgsql1' → asosiy egaz bazasi va uning nusxasi
 * Trigger yon ta'sirlari HAR DOIM shu ulanishda bajariladi.
 *
 * ⚠ PHP triggerlari FAQAT DB triggeri BO'LMAGAN ulanishlarda ishlaydi
 *   (config/db_triggers.php → `php_connections`, standart: pgsql, pgsql1).
 *   MySQL ga yozganda bu metodlar yon ta'sir bermaydi — u yerda ishni
 *   bazaning o'z triggerlari bajaradi.
 *
 * TIPIK ISHLATILISHI:
 *
 *   // INSERT
 *   TriggerBus::insert('i_real_details', $row);
 *   $id = TriggerBus::insertGetId('i_money_details', $row);
 *   TriggerBus::insertMany('i_real_details', $chunk);   // bulk, trigger har qatorga
 *
 *   // UPDATE
 *   TriggerBus::update('i_abonent_details', ['id' => $id], $payload);
 *
 *   // DELETE
 *   TriggerBus::delete('i_money_details', ['id' => $id]);
 *
 * @see config/db_triggers.php
 * @see docs/db-triggers-to-service-migration.md
 */
class TriggerBus
{
    const BEFORE_INSERT = 'before_insert';
    const AFTER_INSERT  = 'after_insert';
    const BEFORE_UPDATE = 'before_update';
    const AFTER_UPDATE  = 'after_update';
    const BEFORE_DELETE = 'before_delete';
    const AFTER_DELETE  = 'after_delete';

    /**
     * jadval => hodisa => [[klass, metod, bayroq_nomi], ...]
     * Tartib bazadagi trigger yaratilish tartibiga mos (MySQL shu tartibda bajaradi).
     */
    private static $handlers = [

        'cms_users' => [
            self::BEFORE_INSERT => [[CmsUsersTriggers::class, 'setIDkodUser',     CmsUsersTriggers::T_SET_KOD]],
            self::AFTER_INSERT  => [[CmsUsersTriggers::class, 'incUsers',         CmsUsersTriggers::T_INC]],
            self::BEFORE_UPDATE => [[CmsUsersTriggers::class, 'updateKod',        CmsUsersTriggers::T_UPD_KOD]],
            self::AFTER_UPDATE  => [[CmsUsersTriggers::class, 'updSubscrbStatus', CmsUsersTriggers::T_UPD_STATUS]],
            self::AFTER_DELETE  => [[CmsUsersTriggers::class, 'decUSers',         CmsUsersTriggers::T_DEC]],
        ],

        'i_abonent_details' => [
            self::AFTER_INSERT => [[IAbonentDetailsTriggers::class, 'afterInsert', IAbonentDetailsTriggers::T_AI]],
            self::AFTER_UPDATE => [[IAbonentDetailsTriggers::class, 'afterUpdate', IAbonentDetailsTriggers::T_AU]],
        ],

        'i_deposit_details' => [
            self::AFTER_INSERT => [[IDepositDetailsTriggers::class, 'afterInsert', IDepositDetailsTriggers::T_AI]],
        ],

        'i_money_details' => [
            self::AFTER_INSERT => [[IMoneyDetailsTriggers::class, 'plusDepositOrg',      IMoneyDetailsTriggers::T_PLUS]],
            self::AFTER_DELETE => [[IMoneyDetailsTriggers::class, 'iMoneyDetailsOrgs',   IMoneyDetailsTriggers::T_DEL]],
        ],

        // ⚠ IKKI trigger BITTA INSERT da yonadi — bazadagi tartibda
        'i_real_details' => [
            self::AFTER_INSERT => [
                [IRealDetailsTriggers::class, 'insertIRealDetails', IRealDetailsTriggers::T_ORGS],
                [IRealDetailsTriggers::class, 'minusDepositOrgs',   IRealDetailsTriggers::T_DEPOSIT],
            ],
        ],

        'tb_factory_integration' => [
            self::BEFORE_INSERT => [[TbFactoryIntegrationTriggers::class, 'beforeInsert', TbFactoryIntegrationTriggers::T_BI]],
        ],

        'tb_scales_logs' => [
            self::BEFORE_INSERT => [[TbScalesLogsTriggers::class, 'beforeInsert', TbScalesLogsTriggers::T_BI]],
        ],
    ];

    // ------------------------------------------------------------------
    // BEFORE — qatorni o'zgartiradi va qaytaradi
    // ------------------------------------------------------------------

    /**
     * BEFORE INSERT. Qaytgan massivni INSERT ga bering.
     *
     * @param string       $table
     * @param array|object $row
     * @param string|null  $connection
     * @return array
     */
    public static function beforeInsert($table, $row, $connection = null)
    {
        $row = self::toArray($row);
        $hs  = self::handlers($table, self::BEFORE_INSERT, $connection);
        if (!$hs) return $row;

        $prev = BaseTrigger::useConnection(self::conn($connection));
        try {
            foreach ($hs as $h) {
                $row = call_user_func([$h[0], $h[1]], $row);
            }
        } finally {
            BaseTrigger::useConnection($prev);
        }
        return $row;
    }

    /**
     * BEFORE UPDATE. Qaytgan massivni UPDATE ga bering.
     *
     * @return array
     */
    public static function beforeUpdate($table, $payload, $connection = null)
    {
        $payload = self::toArray($payload);
        $hs      = self::handlers($table, self::BEFORE_UPDATE, $connection);
        if (!$hs) return $payload;

        $prev = BaseTrigger::useConnection(self::conn($connection));
        try {
            foreach ($hs as $h) {
                $payload = call_user_func([$h[0], $h[1]], $payload);
            }
        } finally {
            BaseTrigger::useConnection($prev);
        }
        return $payload;
    }

    /**
     * BEFORE DELETE. O'chirishdan OLDIN chaqiring (qator hali jadvalda turganda).
     *
     * @return void
     */
    public static function beforeDelete($table, $old, $connection = null)
    {
        if ($old === null) return;
        self::dispatch($table, self::BEFORE_DELETE, [$old], $connection);
    }

    // ------------------------------------------------------------------
    // AFTER — yon ta'sirlar
    // ------------------------------------------------------------------

    /**
     * AFTER INSERT. `$row` ichida yangi `id` ham bo'lishi kerak (kerak bo'lsa).
     *
     * @return void
     */
    public static function afterInsert($table, $row, $connection = null)
    {
        self::dispatch($table, self::AFTER_INSERT, [$row], $connection);
    }

    /**
     * AFTER UPDATE.
     *
     * @param array|object      $payload UPDATE da berilgan ustunlar
     * @param array|object|null $old     UPDATE dan OLDINGI qator
     * @return void
     */
    public static function afterUpdate($table, $payload, $old, $connection = null)
    {
        if ($old === null) return; // eski qatorsiz AFTER UPDATE hisoblanmaydi
        self::dispatch($table, self::AFTER_UPDATE, [$payload, $old], $connection);
    }

    /**
     * AFTER DELETE.
     *
     * @return void
     */
    public static function afterDelete($table, $old, $connection = null)
    {
        if ($old === null) return;
        self::dispatch($table, self::AFTER_DELETE, [$old], $connection);
    }

    // ------------------------------------------------------------------
    // Bir qatorli o'ramlar
    //
    // Bayroqlar o'chiq bo'lganda bular AYNAN eski kod bilan bir xil:
    // bitta so'rov, qo'shimcha SELECT yo'q.
    // ------------------------------------------------------------------

    /**
     * INSERT + AFTER INSERT.
     *
     * @return bool
     */
    public static function insert($table, array $row, $connection = null)
    {
        $row = self::beforeInsert($table, $row, $connection);
        $ok  = self::q($table, $connection)->insert($row);

        if ($ok) self::afterInsert($table, $row, $connection);

        return $ok;
    }

    /**
     * INSERT + AFTER INSERT, yangi id ni qaytaradi.
     *
     * @return int
     */
    public static function insertGetId($table, array $row, $connection = null, $pk = 'id')
    {
        $row = self::beforeInsert($table, $row, $connection);
        $id  = self::q($table, $connection)->insertGetId($row);

        $row[$pk] = $id;
        self::afterInsert($table, $row, $connection);

        return $id;
    }

    /**
     * Ommaviy (bulk) INSERT — bitta so'rov bilan, lekin triggerlar MySQL dagidek
     * HAR BIR QATOR uchun alohida chaqiriladi (FOR EACH ROW).
     *
     * Bayroqlar o'chiq bo'lsa aynan bitta bulk INSERT bajariladi.
     *
     * @param array[] $rows
     * @return bool
     */
    public static function insertMany($table, array $rows, $connection = null)
    {
        if (empty($rows)) return true;

        if (self::active($table, self::BEFORE_INSERT, $connection)) {
            foreach ($rows as $i => $r) {
                $rows[$i] = self::beforeInsert($table, $r, $connection);
            }
        }

        $ok = self::q($table, $connection)->insert($rows);

        if ($ok && self::active($table, self::AFTER_INSERT, $connection)) {
            foreach ($rows as $r) {
                self::afterInsert($table, $r, $connection);
            }
        }

        return $ok;
    }

    /**
     * BEFORE UPDATE + UPDATE + AFTER UPDATE (har bir tegilgan qator uchun).
     *
     * @param array|\Closure $where  ['ustun' => qiymat] yoki function ($q) { ... }
     * @return int ta'sirlangan qatorlar soni
     */
    public static function update($table, $where, array $values, $connection = null)
    {
        $olds = self::active($table, self::AFTER_UPDATE, $connection) ? self::fetch($table, $where, $connection) : [];

        $values   = self::beforeUpdate($table, $values, $connection);
        $affected = self::applyWhere(self::q($table, $connection), $where)->update($values);

        foreach ($olds as $old) {
            self::afterUpdate($table, $values, $old, $connection);
        }

        return $affected;
    }

    /**
     * BEFORE DELETE + DELETE + AFTER DELETE (har bir o'chirilgan qator uchun).
     *
     * @param array|\Closure $where
     * @return int
     */
    public static function delete($table, $where, $connection = null)
    {
        $needOld = self::active($table, self::BEFORE_DELETE, $connection)
                || self::active($table, self::AFTER_DELETE, $connection);
        $olds    = $needOld ? self::fetch($table, $where, $connection) : [];

        foreach ($olds as $old) {
            self::beforeDelete($table, $old, $connection);
        }

        $affected = self::applyWhere(self::q($table, $connection), $where)->delete();

        foreach ($olds as $old) {
            self::afterDelete($table, $old, $connection);
        }

        return $affected;
    }

    // ------------------------------------------------------------------
    // Yordamchilar
    // ------------------------------------------------------------------

    /**
     * UPDATE/DELETE dan oldin eski qatorni o'qish.
     * Eski qator kerak bo'ladigan trigger yoqilmagan bo'lsa NULL qaytaradi.
     *
     * @return array|null
     */
    public static function snapshot($table, $id, $pk = 'id', $connection = null)
    {
        if (!self::needsOld($table, $connection)) return null;

        $row = self::q($table, $connection)->where($pk, $id)->first();
        return $row ? (array) $row : null;
    }

    /**
     * Shu jadval uchun eski qatorni talab qiladigan trigger yoqilganmi.
     *
     * @return bool
     */
    public static function needsOld($table, $connection = null)
    {
        $events = [self::AFTER_UPDATE, self::BEFORE_DELETE, self::AFTER_DELETE];
        foreach ($events as $ev) {
            if (self::handlers($table, $ev, $connection)) return true;
        }
        return false;
    }

    /**
     * Shu jadval (va ixtiyoriy hodisa) bo'yicha birorta trigger yoqilganmi.
     *
     * @return bool
     */
    public static function active($table, $event = null, $connection = null)
    {
        if ($event !== null) return (bool) self::handlers($table, $event, $connection);

        foreach (array_keys(self::eventsOf($table)) as $ev) {
            if (self::handlers($table, $ev, $connection)) return true;
        }
        return false;
    }

    /**
     * Jadvalga bog'langan barcha triggerlar (yoqilgan-yoqilmaganidan qat'i nazar).
     */
    public static function triggersOf($table)
    {
        $out = [];
        foreach (self::eventsOf($table) as $event => $list) {
            foreach ($list as $h) {
                $out[] = ['event' => $event, 'trigger' => $h[2], 'handler' => $h[0] . '::' . $h[1]];
            }
        }
        return $out;
    }

    /** Ro'yxatga olingan barcha jadvallar */
    public static function tables()
    {
        return array_keys(self::$handlers);
    }

    // ------------------------------------------------------------------

    /**
     * AFTER hodisalarini ulanish kontekstida chaqirish.
     */
    private static function dispatch($table, $event, array $args, $connection)
    {
        $hs = self::handlers($table, $event, $connection);
        if (!$hs) {
            self::warnAllBlocked($table, $event, $connection);
            return;
        }

        $prev = BaseTrigger::useConnection(self::conn($connection));
        try {
            foreach ($hs as $h) {
                call_user_func_array([$h[0], $h[1]], $args);
            }
        } finally {
            BaseTrigger::useConnection($prev);
        }
    }

    /** Ogohlantirish bir jarayonda bir marta: 'jadval|hodisa|ulanish' => true */
    private static $warned = [];

    /**
     * Jadvalga trigger BOG'LANGAN, lekin HAMMASI o'chiq bo'lsa ogohlantiradi.
     *
     * NEGA KERAK. Bu holat MUTLAQO JIM kechadi: qator jadvalga yoziladi, xato
     * otilmaydi, lekin yon ta'sir (agregat, depozit) bajarilmaydi. Bazadagi DB
     * trigger tanasi kommentga olingan bo'lsa esa mantiq umuman YO'QOLADI —
     * `i_real_orgs` shunday o'smay qolgan edi.
     *
     * TIPIK SABAB: uzoq yashaydigan jarayon (Apache/opcache, `queue:work`)
     * ESKIRGAN configni ushlab turadi. `config/db_triggers.php` da
     * `php_connections` o'zgartirilgan bo'lsa ham, o'sha jarayon eski ro'yxatni
     * ko'radi va `mysql` ro'yxatda bo'lmagani uchun hech narsa yonmaydi.
     * CLI da esa (masalan `triggers:irl-detail`) config yangi o'qiladi va
     * hammasi ishlaydi — "komandada ishlaydi, saytda ishlamaydi" shundan.
     *
     * @return void
     */
    private static function warnAllBlocked($table, $event, $connection)
    {
        $events = self::eventsOf($table);
        if (!isset($events[$event]) || !$events[$event]) return; // trigger yo'q — normal

        // Asl bazada ham bo'sh bo'lgan triggerlar ogohlantirishga kirmaydi.
        $noop = (array) config('db_triggers.noop', []);
        $real = [];
        foreach ($events[$event] as $h) {
            if (!in_array($h[2], $noop, true)) $real[] = $h[2];
        }
        if (!$real) return;

        $conn = TriggerFlags::resolveConnection(self::conn($connection));
        $key  = $table . '|' . $event . '|' . $conn;
        if (isset(self::$warned[$key])) return;
        self::$warned[$key] = true;

        \Log::warning(BaseTrigger::LOG_TAG . ' [' . $table . '] ' . $event . ' — ro`yxatdagi '
            . count($real) . ' ta triggerning HAMMASI o`chiq (' . $conn . '): ' . implode(', ', $real)
            . '. Qator yozildi, lekin YON TA`SIR BAJARILMADI.'
            . ' Sabab odatda eskirgan config (Apache/opcache yoki queue:work qayta ishga tushirilmagan).'
            . ' Tekshirish: php artisan triggers:probe ' . $table);
    }

    /**
     * Faqat YOQILGAN handlerlar.
     *
     * Bayroqdan tashqari ULANISH ham hisobga olinadi: bazasida DB trigger bor
     * ulanishda (mysql) PHP tomoni ishlamaydi, aks holda amal ikki marta
     * bajarilardi. config/db_triggers.php → `php_connections`.
     */
    private static function handlers($table, $event, $connection = null)
    {
        $events = self::eventsOf($table);
        if (!isset($events[$event])) return [];

        $conn = self::conn($connection);

        $out = [];
        foreach ($events[$event] as $h) {
            if (TriggerFlags::enabledOn($h[2], $conn)) $out[] = $h;
        }
        return $out;
    }

    private static function eventsOf($table)
    {
        return isset(self::$handlers[$table]) ? self::$handlers[$table] : [];
    }

    /** Ulanish nomini aniqlash: berilmagan bo'lsa configdagi standart */
    private static function conn($connection)
    {
        return $connection === null ? TriggerFlags::connection() : $connection;
    }

    /** Berilgan ulanishdagi so'rov quruvchisi */
    private static function q($table, $connection)
    {
        return DB::connection(self::conn($connection))->table($table);
    }

    /**
     * Shartga mos qatorlarni massiv ko'rinishida o'qish.
     *
     * ⚠ Bu FAQAT eski qator kerak bo'lgan trigger yoqilganda chaqiriladi.
     * Ba'zi joylarda shart butun SANA ORALIG'INI qamraydi (masalan
     * calcDebit.php dagi `whereBetween('dt', …)`) — u holda bu yerga millionlab
     * qator kelib xotirani yeb qo'yishi mumkin. Shuning uchun avval sanaymiz va
     * chegaradan oshsa ogohlantiramiz. docs/db-triggers-to-service-migration.md
     */
    private static function fetch($table, $where, $connection)
    {
        $limit = (int) config('db_triggers.max_old_rows', 5000);

        if ($limit > 0) {
            $count = self::applyWhere(self::q($table, $connection), $where)->count();
            if ($count > $limit) {
                \Log::warning(BaseTrigger::LOG_TAG . " [$table] OGOHLANTIRISH: AFTER UPDATE/DELETE "
                    . "triggeri uchun $count ta eski qator o`qilmoqda (chegara: $limit). "
                    . 'Bu ommaviy qayta hisoblash bo`lsa — mos bayroqni o`chiring.');
            }
        }

        $rows = self::applyWhere(self::q($table, $connection), $where)->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = (array) $r;
        }
        return $out;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $q
     * @param array|\Closure                     $where
     */
    private static function applyWhere($q, $where)
    {
        if ($where instanceof \Closure) {
            $where($q);
            return $q;
        }
        return $q->where($where);
    }

    private static function toArray($row)
    {
        if (is_array($row)) return $row;
        if (is_object($row)) return (array) $row;
        return [];
    }
}
