<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * egaz-indexator dan kelgan `i_real_details` / `i_money_details` qatorlarini
 * PostgreSQL ga yozadi.
 *
 * Kirish: `POST /api/v1/index/rows` → `IndexSyncController::store()`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ⚠ BU YERDA TRIGGER MANTIQI QAYTA BAJARILMAYDI
 * ─────────────────────────────────────────────────────────────────────────
 * Replika mantiqni takrorlamaydi, faqat holatni ko'zguga tushiradi. Aks holda
 * egaz-indexator MySQL'da allaqachon bajarilgan hosila amallar ikkinchi marta
 * bajarilardi:
 *
 *   i_real_details.insert_i_real_details  → i_real_orgs agregati
 *   i_real_details.minus_deposit_orgs     → organizations.deposit -= amount
 *   i_money_details.plus_deposit_org      → organizations.deposit += amount
 *
 * Ya'ni depozit HAR BIR qator uchun ikki marta o'zgarardi. Shuning uchun:
 *   - faqat `DB::connection()->table()` (hech qanday `TriggerBus::` chaqiruvi yo'q);
 *   - `handle()` boshida `db_triggers.enabled` = false qilib qo'yiladi.
 *
 * Agregat jadvallar (`i_real_orgs`, `i_money_orgs`, `idx_*`) bu kanaldan
 * UMUMAN kelmaydi — ular `php artisan pg:sync` bilan alohida ko'chiriladi.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * FAQAT INSERT — `insert ... on conflict do nothing`
 * ─────────────────────────────────────────────────────────────────────────
 * Ikkala jadval ham APPEND-ONLY: qator bir marta qo'shiladi va keyin
 * o'zgarmaydi (tuzatish kerak bo'lsa kun butunlay qayta hisoblanadi —
 * `calcRealizations` / `calcDebit` o'sha kunni DELETE qilib qaytadan yozadi).
 * Shuning uchun UPDATE yo'li umuman kerak emas.
 *
 * Konflikt MAQSADI ko'rsatilmaydi (`insertOrIgnore`), ya'ni PostgreSQL dan
 * hech qanday UNIQUE/PK constraint TALAB QILINMAYDI va `42P10` chiqmaydi.
 * Qator allaqachon bo'lsa (navbat retry'i, takroriy yuborish, pg:sync ustiga
 * tushish) jimgina o'tkazib yuboriladi — amal IDEMPOTENT.
 *
 * @see config/index_sync.php
 */
class ApplyIndexRows implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Sekundlarda: 5s → 15s → 1daq → 5daq → 15daq */
    public array $backoff = [5, 15, 60, 300, 900];

    /** ulanish.jadval => [ustun => pg tipi] — worker jarayoni davomidagi kesh */
    private static array $schemaCache = [];

    /**
     * @param string $table  'i_real_details' | 'i_money_details'
     * @param array<int, array<string, mixed>> $rows to'liq qator tasvirlari
     * @param string|null $uid  yuboruvchi tarafdagi batch identifikatori
     */
    public function __construct(
        public readonly string $table,
        public readonly array $rows,
        public readonly ?string $uid = null,
    ) {}

    public function handle(): void
    {
        // ⚠ Qulf: PHP trigger shini bu oqimda otmasin (yuqoridagi izohga qarang).
        config(['db_triggers.enabled' => false]);

        $meta = (array) (config('index_sync.tables')[$this->table] ?? []);
        if ($meta === []) {
            // Jadval oq ro'yxatdan chiqarilgan bo'lsa job'ni yiqitmaymiz —
            // navbatda abadiy aylanib yurmasligi uchun jimgina tashlab yuboramiz.
            Log::warning("IDXSYNC apply: `{$this->table}` oq ro`yxatda yo`q — "
                . count($this->rows) . ' qator tashlandi');
            return;
        }

        $conn = (string) config('index_sync.connection', 'pgsql');
        $pk   = (array) ($meta['pk'] ?? []);
        $cols = $this->columns($conn, $this->table);

        if ($cols === []) {
            // Jadval PostgreSQL da yo'q — bu sozlama xatosi, qayta urinish
            // uni tuzatmaydi. Job'ni yiqitamiz: failed_jobs da ko'rinsin.
            throw new \RuntimeException(
                "`{$this->table}` jadvali `{$conn}` ulanishida topilmadi — "
                . 'avval `php artisan pg:sync ' . $this->table . '` bilan yarating.'
            );
        }

        $clean   = [];
        $skipped = 0;
        foreach ($this->rows as $row) {
            $normalized = $this->normalize((array) $row, $cols, $pk);
            if ($normalized === null) {
                $skipped++;
                continue;
            }
            $clean[] = $normalized;
        }

        if ($skipped > 0) {
            Log::warning("IDXSYNC apply: {$this->table} — {$skipped} qator kalitsiz/bo`sh "
                . "bo`lgani uchun tashlandi (uid={$this->uid})");
        }
        if ($clean === []) {
            return;
        }

        $applied = 0;
        // Bitta INSERT dagi hamma qator AYNAN bir xil ustunlar to'plamiga ega
        // bo'lishi shart, shuning uchun "shakl" bo'yicha guruhlanadi.
        foreach ($this->groupByShape($clean) as $group) {
            $applied += DB::connection($conn)->table($this->table)->insertOrIgnore($group);
        }

        if (config('index_sync.log', true)) {
            $dup = count($clean) - $applied;
            Log::info("IDXSYNC apply: {$this->table} — {$applied} qator yozildi"
                . ($dup > 0 ? ", {$dup} ta allaqachon bor edi" : '')
                . " (uid={$this->uid})");
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error(sprintf(
            'IDXSYNC apply XATO: %s (%d qator, uid=%s): %s',
            $this->table, count($this->rows), $this->uid ?? '-', $e->getMessage()
        ));
    }

    // ------------------------------------------------------------------

    /**
     * Qatorni PG sxemasiga moslash:
     *   - jadvalda yo'q ustunlar tashlanadi (sxema drifti xato bermasin);
     *   - MySQL ning "nol sanasi" NULL ga aylantiriladi.
     *
     * Kalit ustunlaridan birortasi yo'q/NULL bo'lsa `null` qaytadi: kalitsiz
     * qator qaysi kunga/qaysi ballonga tegishli ekani noma'lum bo'lib qoladi.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $cols ustun => pg tipi
     * @param list<string>          $pk
     * @return array<string, mixed>|null
     */
    private function normalize(array $row, array $cols, array $pk): ?array
    {
        $out = [];

        foreach ($row as $col => $value) {
            if (! is_string($col) || ! isset($cols[$col])) {
                continue;   // jadvalda bunday ustun yo'q
            }

            if (is_string($value)) {
                $trimmed = trim($value);

                // MySQL "nol sanasi" — PostgreSQL da bunday qiymat yo'q, xato beradi.
                if ($trimmed === '0000-00-00' || $trimmed === '0000-00-00 00:00:00') {
                    $value = null;
                }
            }

            $out[$col] = $value;
        }

        if ($out === []) {
            return null;
        }

        foreach ($pk as $keyCol) {
            if (! array_key_exists($keyCol, $out) || $out[$keyCol] === null || $out[$keyCol] === '') {
                return null;
            }
        }

        return $out;
    }

    /**
     * Qatorlarni ustunlar to'plami ("shakl") bo'yicha guruhlaydi.
     *
     * Laravel bitta `insert()` da hamma qator bir xil kalitlarga ega deb
     * hisoblaydi. Yuboruvchi odatda bir xil shakl beradi, lekin ixtiyoriy
     * ustun (masalan `payer_inn`) ba'zi qatorlarda tushib qolishi mumkin.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<array<string, mixed>>>
     */
    private function groupByShape(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $keys = array_keys($row);
            sort($keys);
            $shape = implode('|', $keys);

            $groups[$shape][] = $row;
        }

        return array_values($groups);
    }

    /**
     * Jadval ustunlari: ustun => tip. Worker jarayoni davomida keshlanadi.
     *
     * @return array<string, string>
     */
    private function columns(string $conn, string $table): array
    {
        $cacheKey = $conn . '.' . $table;

        if (isset(self::$schemaCache[$cacheKey])) {
            return self::$schemaCache[$cacheKey];
        }

        try {
            $out = [];
            foreach (DB::connection($conn)->getSchemaBuilder()->getColumns($table) as $col) {
                $out[(string) $col['name']] = (string) ($col['type_name'] ?? $col['type'] ?? '');
            }
        } catch (Throwable $e) {
            Log::warning("IDXSYNC apply: {$table} sxemasi o`qilmadi ({$conn}): " . $e->getMessage());
            $out = [];
        }

        return self::$schemaCache[$cacheKey] = $out;
    }
}
