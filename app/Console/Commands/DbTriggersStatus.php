<?php

namespace App\Console\Commands;

use DB;
use Illuminate\Console\Command;
use App\Services\DbTriggers\TriggerBus;
use App\Services\DbTriggers\TriggerFlags;

/**
 * Bazadagi triggerlar va ularning PHP versiyalarining holatini bir jadvalda
 * ko'rsatadi. Dual write da asosiy nazorat vositasi.
 *
 * DIQQAT: holat HAR BIR ULANISH uchun alohida. Dual write da to'g'ri manzara
 * quyidagicha:
 *
 *   --connection=mysql  → "bazada (asl holat)"   (DB triggerlari ishlaydi, PHP o'chiq)
 *   --connection=pgsql  → "PHP da (ko'chirilgan)" (DB trigger yo'q, PHP ishlaydi)
 *
 * Ikkala ro'yxatda ham "IKKI MARTA BAJARILADI" yoki "HECH QAYERDA YO'Q"
 * bo'lmasligi kerak.
 *
 *   php artisan triggers:status
 *   php artisan triggers:status --conflicts
 *   php artisan triggers:status --connection=pgsql
 *   php artisan triggers:status --all          (hamma ulanish bo'yicha ketma-ket)
 */
class DbTriggersStatus extends Command
{
    protected $signature = 'triggers:status
                            {--conflicts : Faqat muammoli (ikki marta bajariladigan yoki yo`qolgan) triggerlarni ko`rsatish}
                            {--connection= : Qaysi ulanishdagi bazani tekshirish (standart: config db_triggers.connection)}
                            {--all : Dual write dagi HAMMA ulanish bo`yicha ketma-ket tekshirish}';

    protected $description = 'Baza triggerlari va ularning PHP versiyalari holatini solishtiradi';

    /** information_schema o'qildimi */
    private $dbReadable = true;

    public function handle()
    {
        if (!$this->option('all')) {
            return $this->report($this->option('connection') ?: TriggerFlags::connection());
        }

        // --all: asosiy ulanishlar va ularning nusxalari (config/dual_write.php).
        $conns = [];
        foreach ((array) config('dual_write.pairs', []) as $primary => $mirror) {
            $conns[] = $primary;
            if ($mirror) $conns[] = $mirror;
        }
        if (!$conns) $conns = [TriggerFlags::connection()];

        $code = 0;
        foreach (array_unique($conns) as $c) {
            $this->dbReadable = true;
            $code = $this->report($c) ?: $code;
        }

        return $code;
    }

    /**
     * Bitta ulanish bo'yicha hisobot.
     *
     * @param string|null $conn
     * @return int
     */
    private function report($conn)
    {
        $dbTriggers = $this->loadDbTriggers($conn);
        $flags      = TriggerFlags::all($conn);

        $meta = [];
        foreach (TriggerBus::tables() as $table) {
            foreach (TriggerBus::triggersOf($table) as $t) {
                $meta[$t['trigger']] = ['table' => $table, 'event' => $t['event']];
            }
        }

        $rows      = [];
        $conflicts = 0;
        $gaps      = 0;

        foreach ($flags as $name => $phpOn) {
            $parts       = explode('.', $name, 2);
            $triggerName = isset($parts[1]) ? $parts[1] : $name;
            $table       = $parts[0];

            $inDb  = isset($dbTriggers[$triggerName]);
            // Nomi bor, tanasi bo'sh trigger — bazada BOR, lekin HECH NARSA qilmaydi.
            $hollow = $inDb && $this->isHollow($dbTriggers[$triggerName]);
            // Amalda bazada ishlaydimi — quyidagi mantiq faqat shunga tayanadi.
            $dbOn  = $inDb && !$hollow;
            $event = isset($meta[$name]) ? $meta[$name]['event'] : '—';

            $isNoop = in_array($name, (array) config('db_triggers.noop', []), true);

            if (!$this->dbReadable) {
                $state = $phpOn ? 'PHP da YOQIQ (baza noma`lum)' : 'PHP da o`chiq (baza noma`lum)';
                $bad = false;
            } elseif (!$this->tableExists($conn, $table)) {
                // Bu jadval shu bazada umuman yo'q (masalan i_* jadvallari faqat
                // indexator bazasida, cms_users esa faqat EGAZ MAIN da) — ya'ni
                // "hech qayerda yo'q" XATO EMAS.
                $state = 'jadval bu bazada yo`q';
                $bad = false;
            } elseif (!$dbOn && !$phpOn && $isNoop) {
                // Asl bazada ham bo'sh trigger edi — "hech qayerda yo'q" TO'G'RI holat.
                $state = 'no-op (asl bazada ham bo`sh)';
                $bad = false;
            } elseif ($dbOn && $phpOn) {
                $state = 'IKKI MARTA BAJARILADI';
                $conflicts++;
                $bad = true;
            } elseif (!$dbOn && !$phpOn) {
                // Tanasi kommentga olingan trigger AYNAN shu yerga tushadi:
                // `information_schema` da "bor", lekin mantiq yo'qolgan.
                $state = $hollow
                    ? 'TANASI BO`SH — MANTIQ YO`QOLGAN'
                    : 'HECH QAYERDA YO`Q';
                $gaps++;
                $bad = true;
            } elseif ($dbOn) {
                $state = 'bazada (asl holat)';
                $bad = false;
            } else {
                $state = 'PHP da (ko`chirilgan)';
                $bad = false;
            }

            if ($this->option('conflicts') && !$bad) continue;

            $rows[] = [
                $triggerName,
                $table,
                $event,
                !$this->dbReadable ? '?' : ($hollow ? 'bor (bo`sh)' : ($inDb ? 'bor' : '-')),
                $phpOn ? 'YOQIQ' : 'o`chiq',
                $state,
            ];
        }

        $resolved = TriggerFlags::resolveConnection($conn);

        $this->line('');
        $this->info('=== Trigger holati (baza ⇄ PHP) — ulanish: ' . $resolved
            . ($conn === null ? ' (standart)' : '') . ' ===');

        if (!TriggerFlags::masterEnabled()) {
            $this->warn('Umumiy kalit O`CHIQ (config/db_triggers.php → enabled=false): PHP triggerlarning HAMMASI ishlamaydi.');
        }
        if (!TriggerFlags::phpSideOn($conn)) {
            $this->line('  PHP tomoni bu ulanishda ATAYLAB o`chiq (config/db_triggers.php → php_connections):'
                . ' ' . $resolved . ' bazasida DB triggerlari ishlaydi.');
        }
        if (TriggerFlags::isDryRun()) {
            $this->warn('DRY RUN rejimi: yoqilgan PHP triggerlar bazaga YOZMAYDI, faqat logga chiqaradi.');
        }

        $this->table(['Trigger', 'Jadval', 'Hodisa', 'Bazada', 'PHP', 'Holat'], $rows);

        if ($this->dbReadable) $this->checkPairs($dbTriggers, $conn);

        $this->line('');
        if (!$this->dbReadable) {
            $this->warn('Bazaga ulanib bo`lmadi — faqat PHP tomon holati ko`rsatildi.');
            return 0;
        }
        if ($conflicts) {
            $this->error("XAVF: $conflicts ta trigger ham bazada, ham PHP da yoqiq — amal IKKI MARTA bajariladi.");
        }
        if ($gaps) {
            $this->error("XAVF: $gaps ta trigger na bazada, na PHP da — mantiq YO`QOLGAN.");
        }
        if (!$conflicts && !$gaps) {
            $this->info('Muammo topilmadi: har bir trigger aynan bitta joyda ishlayapti.');
        }

        $known = [];
        foreach (array_keys($flags) as $n) {
            $p = explode('.', $n, 2);
            $known[isset($p[1]) ? $p[1] : $n] = true;
        }
        $unknown = array_diff(array_keys($dbTriggers), array_keys($known));
        if ($unknown) {
            $this->line('');
            $this->warn('Bazada bor, lekin config/db_triggers.php da ro`yxatga OLINMAGAN triggerlar:');
            foreach ($unknown as $u) {
                $this->line('  - ' . $u . '  (' . $dbTriggers[$u]->EVENT_OBJECT_TABLE . ')');
            }
        }

        return 0;
    }

    /**
     * Bitta hodisada yonadigan juftliklar bir xil holatda bo'lishi kerak.
     */
    private function checkPairs(array $dbTriggers, $conn = null)
    {
        $problems = [];

        foreach (TriggerFlags::pairs() as $table => $list) {
            $states = [];
            foreach ($list as $full) {
                $short = $this->shortName($full);
                $states[$full] = [
                    'php' => TriggerFlags::enabledOn($full, $conn),
                    'db'  => isset($dbTriggers[$short]) && !$this->isHollow($dbTriggers[$short]),
                ];
            }

            $phpVals = array_unique(array_column($states, 'php'));
            $dbVals  = array_unique(array_column($states, 'db'));

            if (count($phpVals) > 1 || count($dbVals) > 1) {
                $desc = [];
                foreach ($states as $full => $s) {
                    $desc[] = $full . ' (baza: ' . ($s['db'] ? 'bor' : 'yo`q')
                        . ', PHP: ' . ($s['php'] ? 'yoqiq' : 'o`chiq') . ')';
                }
                $problems[] = $table . ' — bitta INSERT da yonadigan triggerlar har xil holatda: ' . implode(' | ', $desc);
            }
        }

        if ($problems) {
            $this->line('');
            $this->error('Juftlik muammolari:');
            foreach ($problems as $p) {
                $this->line('  - ' . $p);
            }
        }
    }

    private function shortName($full)
    {
        $p = explode('.', $full, 2);
        return isset($p[1]) ? $p[1] : $full;
    }

    /**
     * Trigger tanasi AMALDA bo'shmi — ya'ni faqat izoh va `begin`/`end` qolganmi.
     *
     * ENG XAVFLI HOLAT SHU: `information_schema` da trigger BOR bo'lib ko'rinadi,
     * lekin hech narsa qilmaydi. DB triggerini PHP ga ko'chirayotganda tanasi
     * kommentga olinib, `config/db_triggers.php` → `php_connections` ga o'sha
     * ulanish QO'SHILMASA — mantiq na bazada, na PHP da qoladi.
     *
     * Aynan shu `tb_factory_integration_bi` da yuz bergan: hgt_filial_egaz
     * (NOT NULL, DEFAULT'siz) to'ldirilmay, INSERT yiqilib, zavod yuk xatlari
     * `integration_logs` da qolib ketgan edi.
     *
     * PG da `action_statement` doim `EXECUTE FUNCTION ...` — u yerda tana alohida
     * funksiyada bo'ladi, shuning uchun bu tekshiruv amalda faqat MySQL ga tegishli.
     *
     * @param  object $row information_schema qatori
     * @return bool
     */
    private function isHollow($row)
    {
        if (!isset($row->ACTION_STATEMENT)) return false;

        $body = (string) $row->ACTION_STATEMENT;

        $body = preg_replace('!/\*.*?\*/!s', ' ', $body);       // /* ... */
        $body = preg_replace('/(?:--|#)[^\n]*/', ' ', $body);   // -- ...  va  # ...
        $body = preg_replace('/\b(?:begin|end)\b/i', ' ', $body);
        $body = preg_replace('/[\s;]+/', '', $body);

        return $body === '';
    }

    /** "ulanish|jadval" => bool */
    private $tableCache = [];

    /**
     * Jadval shu ulanishdagi bazada bormi (natija keshlanadi).
     *
     * @return bool
     */
    private function tableExists($conn, $table)
    {
        $key = ($conn ?: '(default)') . '|' . $table;

        if (!array_key_exists($key, $this->tableCache)) {
            try {
                $this->tableCache[$key] = \Schema::connection($conn)->hasTable($table);
            } catch (\Throwable $e) {
                $this->tableCache[$key] = true; // aniqlanmadi — eski xatti-harakat
            }
        }

        return $this->tableCache[$key];
    }

    /**
     * Berilgan ulanishdagi triggerlar: nom => qator.
     */
    private function loadDbTriggers($conn)
    {
        try {
            $c = DB::connection($conn);

            if ($c->getDriverName() === 'pgsql') {
                // PG: information_schema ustunlari KICHIK harfda, `DATABASE()` esa yo'q.
                // Ustunlar qo'shtirnoqli alias bilan MySQL dagi nomlariga keltiriladi,
                // shunda quyidagi kod ($r->TRIGGER_NAME) ikkala dialektda bir xil.
                // PG bitta triggerni har bir hodisa uchun alohida qator qilib beradi —
                // pastdagi `$out[nom] = $r` shu takrorni yig'ib yuboradi.
                $rows = $c->select(
                    'SELECT trigger_name AS "TRIGGER_NAME", event_object_table AS "EVENT_OBJECT_TABLE", '
                    . 'action_timing AS "ACTION_TIMING", event_manipulation AS "EVENT_MANIPULATION", '
                    . 'action_statement AS "ACTION_STATEMENT" '
                    . 'FROM information_schema.triggers WHERE trigger_schema = current_schema()'
                );
            } else {
                // ACTION_STATEMENT — trigger TANASI. Nomi bor-u tanasi kommentga
                // olingan trigger ham "bor" bo'lib ko'rinadi, shuning uchun kerak.
                $rows = $c->select(
                    'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT '
                    . 'FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
                );
            }
        } catch (\Exception $e) {
            $this->dbReadable = false;
            $this->warn('information_schema.TRIGGERS o`qilmadi: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[$r->TRIGGER_NAME] = $r;
        }
        return $out;
    }
}
