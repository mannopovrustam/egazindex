<?php

namespace App\Console\Commands;

use DB;
use Schema;
use Illuminate\Console\Command;
use App\Services\DualWrite\DualWrite;
use App\Services\DualWrite\MirrorSchema;

/**
 * Dual write holatini ko'rsatadi: ulanish juftliklari, ularning ishlashi va
 * har bir jadval uchun asosiy ⇄ nusxa moslik.
 *
 *   php artisan dual:status
 *   php artisan dual:status --issues            faqat muammoli jadvallar
 *   php artisan dual:status --fix-sequences     PG serial larni MAX(id) ga tenglash
 *
 * NEGA `--fix-sequences` KERAK: nusxaga id MySQL dan ko'chiriladi, ya'ni PG
 * dagi serial ketma-ketligi o'sib bormaydi. Nusxa jadvalga BOSHQA joydan
 * (masalan qo'lda yoki `pg:sync` bilan) id siz yozilsa, sequence eski
 * qiymatdan boshlab dublikat id berishi mumkin. Bu komanda sequence ni
 * MAX(id) ga keltirib qo'yadi.
 */
class DualWriteStatus extends Command
{
    protected $signature = 'dual:status
                            {--issues : Faqat muammoli jadvallarni ko`rsatish}
                            {--fix-sequences : PostgreSQL serial ketma-ketliklarini MAX(id) ga tenglash}';

    protected $description = 'Ikki bazaga yozish (MySQL → PostgreSQL) holatini tekshiradi';

    public function handle()
    {
        $this->line('');
        $this->info('=== Dual write holati ===');

        $this->line('  Umumiy kalit : ' . ($this->flag(config('dual_write.enabled'))));
        $this->line('  Dry run      : ' . $this->flag(config('dual_write.dry_run')));
        $this->line('  Fail open    : ' . $this->flag(config('dual_write.fail_open'))
            . '  (nusxa xatosi asosiy amalni buzmaydi)');
        $this->line('  Nusxada PHP triggerlari: ' . $this->flag(config('dual_write.triggers')));
        $this->line('  Log          : ' . $this->flag(config('dual_write.log')) . '  (prefiks: DUALW)');
        $this->line('  Standart ulanish: ' . config('database.default'));

        if (!config('dual_write.enabled')) {
            $this->warn('  ⚠ O`CHIQ: ilova FAQAT MySQL ga yozadi (egaz-indexator dagi xatti-harakat).');
        }

        $pairs = (array) config('dual_write.pairs', []);
        if (!$pairs) {
            $this->error('config/dual_write.php → `pairs` bo`sh: nusxa olinadigan ulanish yo`q.');
            return 1;
        }

        $problems = 0;

        foreach ($pairs as $primary => $mirror) {
            $this->line('');
            $this->info("--- $primary  →  $mirror ---");

            $pOk = $this->probe($primary);
            $mOk = $this->probe($mirror);

            if (!$pOk || !$mOk) {
                $problems++;
                continue;
            }

            $problems += $this->compareTables($primary, $mirror);
        }

        $this->line('');
        if ($problems) {
            $this->error("$problems ta muammo topildi (yuqoriga qarang).");
        } else {
            $this->info('Muammo topilmadi: har bir jadval nusxa bazada bor va ustunlari mos.');
        }

        return 0;
    }

    /**
     * Ulanishni tekshirish va ma'lumotini chiqarish.
     *
     * @return bool
     */
    private function probe($name)
    {
        $cfg = config('database.connections.' . $name);

        if (!$cfg) {
            $this->error("  $name — config/database.php da bunday ulanish YO`Q.");
            return false;
        }

        try {
            $conn = DB::connection($name);
            $conn->select('select 1');

            $this->line('  ' . str_pad($name, 8) . ' ' . str_pad($conn->getDriverName(), 6)
                . ' ' . $conn->getDatabaseName() . '  ✔ ulanadi');

            return true;
        } catch (\Throwable $e) {
            $this->error('  ' . str_pad($name, 8) . ' ULANMADI: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Jadvallarni solishtirish: nusxada bor-yo'qligi, `id` / `created_at`,
     * sequence holati.
     *
     * @return int muammolar soni
     */
    private function compareTables($primary, $mirror)
    {
        $tables = (array) config('dual_write.watch_tables.' . $primary, []);
        if (!$tables) {
            $this->line('  (config/dual_write.php → watch_tables da bu ulanish uchun jadval ko`rsatilmagan)');
            return 0;
        }

        $idCol = config('dual_write.id_column', 'id');
        $tsCol = config('dual_write.created_at_column', 'created_at');

        $rows     = [];
        $problems = 0;

        foreach ($tables as $table) {
            $inPrimary = $this->hasTable($primary, $table);
            $cols      = MirrorSchema::columns($mirror, $table);

            $note = '';
            $bad  = false;

            if (!$inPrimary && $cols === null) {
                $note = 'ikkalasida ham YO`Q';
                $bad  = true;
            } elseif ($cols === null) {
                $note = 'nusxada YO`Q — nusxa olinmaydi';
                $bad  = true;
            } elseif (!$inPrimary) {
                $note = 'asosiy bazada yo`q (faqat nusxada)';
            }

            $id = '-';
            $ts = '-';

            if ($cols !== null) {
                if (isset($cols[$idCol])) {
                    $id = $cols[$idCol]['auto_increment']
                        ? 'serial'
                        : (MirrorSchema::mustProvide($cols[$idCol]) ? 'MAX()+1' : 'bor');
                }
                if (isset($cols[$tsCol])) $ts = 'bor';

                // Sequence orqada qolganmi (id qo'ldan yozilgani uchun bo'lishi mumkin).
                if ($id === 'serial') {
                    $lag = MirrorSchema::sequenceLag($mirror, $table, $idCol);
                    if ($lag && $lag['max'] > $lag['last']) {
                        if ($this->option('fix-sequences')) {
                            DB::connection($mirror)->statement(
                                'select setval(?, ?)', [$lag['sequence'], $lag['max']]
                            );
                            $note = trim($note . ' sequence tuzatildi (' . $lag['last'] . ' → ' . $lag['max'] . ')');
                        } else {
                            $note = trim($note . ' ⚠ sequence orqada: last=' . $lag['last']
                                . ', max(id)=' . $lag['max'] . ' (--fix-sequences)');
                            $bad  = true;
                        }
                    }
                }
            }

            if ($bad) $problems++;
            if ($this->option('issues') && !$bad) continue;

            $rows[] = [
                $table,
                $inPrimary ? 'bor' : '-',
                $cols === null ? '-' : count($cols),
                $id,
                $ts,
                $note,
            ];
        }

        if ($rows) {
            $this->table(
                ['Jadval', 'Asosiyda', 'Nusxa ustunlari', $idCol, $tsCol, 'Izoh'],
                $rows
            );
        }

        return $problems;
    }

    private function hasTable($connection, $table)
    {
        try {
            return Schema::connection($connection)->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function flag($value)
    {
        return $value ? 'YOQIQ' : 'o`chiq';
    }
}
