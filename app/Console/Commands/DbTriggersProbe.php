<?php

namespace App\Console\Commands;

use DB;
use Illuminate\Console\Command;
use App\Services\DbTriggers\TriggerBus;
use App\Services\DbTriggers\TriggerFlags;

/**
 * Bitta jadval triggerini DARVOZAMA-DARVOZA tekshiradi va HAQIQIY SQL ni
 * ko'rsatadi — bazaga hech narsa yozmasdan.
 *
 * NEGA KERAK. "Qator jadvalga tushdi, xato yo'q, lekin agregat o'smadi"
 * holatida sabab har doim jimgina yopilgan darvoza bo'ladi:
 *
 *   masterEnabled()   — umumiy kalit (db_triggers.enabled)
 *   enabled($name)    — shu triggerning bayrog'i (db_triggers.triggers)
 *   phpSideOn($conn)  — ulanish php_connections ro'yxatidami
 *   overrides         — runtime bekor qilish (TriggerFlags::override)
 *   isDryRun()        — yozmaslik rejimi
 *
 * `triggers:status` faqat YAKUNIY natijani ko'rsatadi (YOQIQ / o'chiq). Bu
 * buyruq esa QAYSI darvoza yopilganini aytadi, so'ng jadvaldagi HAQIQIY oxirgi
 * qator ustida triggerni yurgizib, bajarilgan SQL larni chop etadi va
 * tranzaksiyani QAYTARIB YUBORADI (rollback) — ya'ni baza o'zgarmaydi.
 *
 * MISOLLAR:
 *   # i_real_orgs nega o'smayapti — darvozalar va haqiqiy SQL
 *   php artisan triggers:probe i_real_details
 *
 *   # faqat darvozalar, SQL yurgizilmasin
 *   php artisan triggers:probe i_real_details --no-run
 *
 *   # PostgreSQL nusxasida tekshirish
 *   php artisan triggers:probe i_real_details --connection=pgsql
 *
 * @see \App\Services\DbTriggers\TriggerBus
 * @see \App\Console\Commands\DbTriggersStatus
 */
class DbTriggersProbe extends Command
{
    protected $signature = 'triggers:probe
                            {table : Jadval nomi (masalan i_real_details)}
                            {--event=after_insert : Hodisa: before_insert / after_insert / after_update / after_delete}
                            {--connection= : Ulanish nomi (standart: config db_triggers.connection)}
                            {--no-run : Faqat darvozalarni ko`rsat — SQL umuman yurgizilmasin}';

    protected $description = 'Trigger nega ishlamayotganini darvozama-darvoza ko`rsatadi va haqiqiy SQL ni chop etadi (bazaga yozmaydi)';

    public function handle()
    {
        $table = $this->argument('table');
        $event = $this->option('event');
        $conn  = $this->option('connection') ?: TriggerFlags::connection();

        $this->line('');
        $this->info('=== Trigger tashxisi: ' . $table . ' / ' . $event . ' ===');
        $this->line('');

        // ---- 1. Jadval ro'yxatga olinganmi -----------------------------------
        $triggers = TriggerBus::triggersOf($table);
        if (!$triggers) {
            $this->error('  Bu jadval TriggerBus da ro`yxatga olinmagan: ' . $table);
            $this->line('  Mavjudlari: ' . implode(', ', TriggerBus::tables()));
            return 1;
        }

        // ---- 2. Umumiy darvozalar --------------------------------------------
        $resolved = TriggerFlags::resolveConnection($conn);
        $list     = config('db_triggers.php_connections', null);

        $this->line('  Ulanish (yechilgan)   : ' . $resolved);
        $this->line('  db_triggers.enabled   : ' . ($this->yn(TriggerFlags::masterEnabled()))
            . (TriggerFlags::masterEnabled() ? '' : '   ← HAMMA trigger o`chiq!'));
        $this->line('  php_connections       : ' . ($list === null ? 'null (HAMMA ulanishda ishlaydi)' : implode(', ', (array) $list)));
        $this->line('  phpSideOn(' . $resolved . ')' . str_repeat(' ', max(0, 10 - strlen($resolved))) . ': ' . $this->yn(TriggerFlags::phpSideOn($conn))
            . (TriggerFlags::phpSideOn($conn) ? '' : '   ← shu ulanishda PHP tomoni O`CHIQ!'));
        $this->line('  dry_run               : ' . ($this->yn(TriggerFlags::isDryRun()))
            . (TriggerFlags::isDryRun() ? '   ← YOZILMAYDI, faqat log!' : ''));
        $this->line('  log                   : ' . $this->yn(TriggerFlags::isLogging()));
        $this->line('  fail_open             : ' . $this->yn(TriggerFlags::failOpen())
            . (TriggerFlags::failOpen() ? '   ← xatolar YUTILADI (faqat logda)' : ''));
        $this->line('');

        // ---- 3. Shu hodisadagi har bir trigger --------------------------------
        $rows    = [];
        $willRun = array();

        foreach ($triggers as $t) {
            if ($t['event'] !== $event) continue;

            $name    = $t['trigger'];
            $flagOn  = TriggerFlags::enabled($name);
            $sideOn  = TriggerFlags::phpSideOn($conn);
            $fires   = TriggerFlags::enabledOn($name, $conn);

            if ($fires) $willRun[] = $name;

            $rows[] = array(
                $name,
                $this->yn($flagOn),
                $this->yn($sideOn),
                $fires ? 'YONADI' : 'BLOKLANGAN: ' . $this->why($flagOn, $sideOn),
            );
        }

        if (!$rows) {
            $this->warn('  `' . $event . '` hodisasida bu jadvalga bog`langan trigger yo`q.');
            $this->line('  Mavjud hodisalar: ' . implode(', ', array_unique(array_map(
                function ($t) { return $t['event']; }, $triggers))));
            return 0;
        }

        $this->table(array('Trigger bayrog`i', 'Bayroq', 'Ulanish', 'Natija'), $rows);

        if (!$willRun) {
            $this->line('');
            $this->error('  Birorta trigger YONMAYDI — sabab yuqoridagi jadvalda.');
            $this->line('  Aynan shu holatda qator jadvalga tushadi, XATO OTILMAYDI,');
            $this->line('  lekin yon ta`sir (agregat, depozit) bajarilmaydi.');
            $this->line('');
            return 1;
        }

        $this->line('  Yonishi kerak: ' . implode(', ', $willRun));
        $this->line('');

        if ($this->option('no-run')) {
            $this->line('  --no-run berilgan — SQL yurgizilmadi.');
            $this->line('');
            return 0;
        }

        // ---- 4. Haqiqiy qator ustida yurgizish (rollback bilan) ---------------
        return $this->dryFire($table, $event, $conn);
    }

    /**
     * Jadvaldagi OXIRGI qatorni olib, trigger tanasini yurgizadi va bajarilgan
     * SQL larni chop etadi. Hammasi tranzaksiya ichida — oxirida rollback.
     *
     * @return int
     */
    private function dryFire($table, $event, $conn)
    {
        try {
            $row = DB::connection($conn)->table($table)->orderByDesc(
                in_array($table, array('i_real_details'), true) ? 'real_at' : 'id'
            )->first();
        } catch (\Exception $e) {
            // `id` ustuni bo'lmagan jadvallar uchun tartibsiz olamiz
            try {
                $row = DB::connection($conn)->table($table)->first();
            } catch (\Exception $e2) {
                $this->error('  Jadvaldan qator o`qilmadi: ' . $e2->getMessage());
                return 1;
            }
        }

        if ($row === null) {
            $this->warn('  Jadval bo`sh — yurgizish uchun namuna qator yo`q.');
            return 0;
        }

        $row = (array) $row;
        $this->line('  Namuna qator: ' . $this->brief($row));
        $this->line('');

        $captured = array();
        DB::connection($conn)->listen(function ($q) use (&$captured) {
            $captured[] = array($q->sql, $q->bindings, $q->time);
        });

        $this->info('--- Trigger yurgizilmoqda (oxirida ROLLBACK) ---');

        DB::connection($conn)->beginTransaction();
        try {
            TriggerBus::afterInsert($table, $row, $conn);
            $ok = true;
        } catch (\Throwable $e) {
            $ok = false;
            $this->line('');
            $this->error('  Trigger XATO berdi: ' . $e->getMessage());
        }
        DB::connection($conn)->rollBack();

        $this->line('');
        if (!$captured) {
            $this->error('  HECH QANDAY SQL BAJARILMADI.');
            $this->line('  Trigger chaqirildi, lekin ichida hech narsa yurgizilmadi —');
            $this->line('  demak `fire()` ichidagi bayroq tekshiruvi to`xtatdi yoki dry_run yoqiq.');
            $this->line('');
            return 1;
        }

        $this->info('  Bajarilgan SQL (' . count($captured) . ' ta) — baza O`ZGARMADI:');
        $this->line('');
        foreach ($captured as $c) {
            list($sql, $bind, $time) = $c;
            $this->line('    ' . preg_replace('/\s+/', ' ', $sql));
            $this->line('      qiymatlar: [' . implode(', ', array_map(function ($v) {
                return $v === null ? 'NULL' : (string) $v;
            }, $bind)) . ']   ' . $time . 'ms');
            $this->line('');
        }

        if ($ok) {
            $this->info('  Trigger xatosiz o`tdi. Yuqoridagi SQL haqiqiy yurishda ham AYNAN shunday bajariladi.');
            $this->line('  Agar bu SQL to`g`ri bo`lsa-yu jadval o`smasa — SQL ni QO`LDA yurgizib ko`ring:');
            $this->line('  natija 0 qator bo`lsa sabab ma`lumotda (mos kelmaydigan kalit), kodda emas.');
        }
        $this->line('');

        return $ok ? 0 : 1;
    }

    private function why($flagOn, $sideOn)
    {
        if (!TriggerFlags::masterEnabled()) return 'db_triggers.enabled = false';
        if (!$flagOn) return 'bayroq o`chiq (db_triggers.triggers)';
        if (!$sideOn) return 'ulanish php_connections da yo`q';
        return 'noma`lum';
    }

    private function yn($v)
    {
        return $v ? 'ha' : 'yo`q';
    }

    private function brief(array $row)
    {
        $out = array();
        $n   = 0;
        foreach ($row as $k => $v) {
            if ($n++ >= 6) { $out[] = '…'; break; }
            $out[] = $k . '=' . ($v === null ? 'NULL' : $v);
        }
        return implode(' ', $out);
    }
}
