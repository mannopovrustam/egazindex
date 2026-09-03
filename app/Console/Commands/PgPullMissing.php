<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MySQL (egaz-indexator, egaz_idxdb) dagi `i_real_details` / `i_money_details`
 * qatorlarini PostgreSQL bilan solishtirib, PG da YO'Q bo'lganlarini yozadi.
 *
 *     php artisan pg:pull                          # ikkala jadval, oxirgi 3 kun
 *     php artisan pg:pull i_real_details --days=7
 *     php artisan pg:pull --date=2026-08-01 --date-to=2026-08-31   # backfill oyna
 *     php artisan pg:pull --dry-run                # faqat hisobot, yozmaydi
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEGA `pg:sync` EMAS
 * ─────────────────────────────────────────────────────────────────────────
 * `pg:sync` sana rejimida PG dagi oynani O'CHIRIB qaytadan yozadi — to'liq
 * almashtirish. Bu buyruq esa FAQAT YETISHMAYOTGANINI QO'SHADI: hech narsa
 * o'chirilmaydi, mavjud qator yangilanmaydi ham. Push kanali (IndexPushService)
 * uzilib qolgan oynalarni xavfsiz to'ldirish uchun aynan shu kerak — jonli
 * push bilan bir vaqtda ishlasa ham to'qnashmaydi.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * "YO'QLIGI" QANDAY ANIQLANADI
 * ─────────────────────────────────────────────────────────────────────────
 * Har KUN uchun PG dan shu kunning PK kortejlar to'plami olinadi (xotirada
 * set — bir kunda ~100 ming qator, bemalol sig'adi), MySQL qatori kaliti shu
 * to'plamda bo'lmasa yoziladi. Bu `insertOrIgnore` ga tayanib qolmaydi:
 * PG jadvalida PK constraint tushib qolgan bo'lsa ham (audit: egaz_idxpost
 * da 17 UNIQUE yo'qolgani aniqlangan edi) DUPLIKAT PAYDO BO'LMAYDI.
 * Yozish baribir `insertOrIgnore` bilan — constraint bor joyda ikkinchi
 * himoya qatlami (parallel push bilan poyga bo'lsa ham xato bermaydi).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * TRIGGER / AGREGAT XAVFSIZLIGI
 * ─────────────────────────────────────────────────────────────────────────
 * Yozuv faqat `DB::table()->insertOrIgnore()` — TriggerBus chaqirilmaydi,
 * ya'ni PHP triggerlar (i_real_orgs/i_money_orgs agregatlari, deposit)
 * OTMAYDI. MySQL tomonda ular allaqachon bajarilgan; replika mantiqni
 * takrorlamaydi (ApplyIndexRows dagi qoidaning aynan o'zi). DualWrite
 * Mirror ham ishga tushmaydi — u faqat aniq chaqiruv orqali ishlaydi,
 * to'g'ridan-to'g'ri pgsql yozuvini ko'zgulamaydi.
 *
 * Jadvallar va PK ro'yxati `config/index_sync.php` dan olinadi — push kanali
 * bilan bitta manba, ikki joyda ikki xil ro'yxat yuritilmaydi.
 *
 * @see config/index_sync.php
 * @see \App\Jobs\ApplyIndexRows           (push kanalidagi yozuvchi)
 * @see docs (egaz-indexator): sync-indexator-to-egazindex.md
 */
class PgPullMissing extends Command
{
    protected $signature = 'pg:pull
        {table? : Jadval nomi (berilmasa index_sync.tables dagi hammasi)}
        {--connection=mysql : Manba MySQL ulanishi (mysql = egaz_idxdb)}
        {--target=pgsql : Nishon PostgreSQL ulanishi}
        {--date= : Oyna boshi YYYY-MM-DD (berilmasa: bugun minus --days)}
        {--date-to= : Oyna oxiri YYYY-MM-DD (berilmasa: bugun)}
        {--days=3 : --date berilmaganda bugundan necha kun orqaga}
        {--chunk=2000 : MySQL dan bir o\'qishda olinadigan qatorlar}
        {--dry-run : Hech narsa yozmaydi, faqat nechta yetishmasligini aytadi}';

    protected $description = 'MySQL dagi i_real_details/i_money_details qatorlaridan PostgreSQL da YO\'Q bo\'lganlarini qo\'shadi (hech narsa o\'chirmaydi)';

    public function handle(): int
    {
        $srcName = trim((string) $this->option('connection'));
        $dstName = trim((string) $this->option('target'));

        foreach ([$srcName, $dstName] as $conn) {
            if (config('database.connections.' . $conn) === null) {
                $this->error('"' . $conn . '" ulanishi config/database.php da yo\'q.');
                return 1;
            }
        }

        $tables = (array) config('index_sync.tables', []);
        if ($tables === []) {
            $this->error('config/index_sync.php → tables bo\'sh.');
            return 1;
        }

        $arg = $this->argument('table');
        if ($arg !== null && trim((string) $arg) !== '') {
            $arg = trim((string) $arg);
            if (! isset($tables[$arg])) {
                $this->error('"' . $arg . '" index_sync.tables ro\'yxatida yo\'q. Bor: '
                    . implode(', ', array_keys($tables)));
                return 1;
            }
            $tables = [$arg => $tables[$arg]];
        }

        $window = $this->resolveWindow();
        if ($window === null) {
            return 1;
        }
        [$from, $to] = $window;

        $isDry = (bool) $this->option('dry-run');
        $chunk = max(100, (int) $this->option('chunk'));

        $this->line('');
        $this->comment('Manba: ' . $srcName . '  →  Nishon: ' . $dstName
            . '   Oyna: ' . $from . ' … ' . $to . ($isDry ? '   [DRY-RUN]' : ''));

        $failed = 0;
        foreach ($tables as $table => $meta) {
            $pk = (array) ($meta['pk'] ?? []);
            if ($pk === []) {
                $this->error($table . ': index_sync.tables da `pk` berilmagan — o\'tkazib yuborildi.');
                $failed++;
                continue;
            }
            if (! $this->pullTable($srcName, $dstName, $table, $pk, $from, $to, $chunk, $isDry)) {
                $failed++;
            }
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Oyna chegaralari: [from, to] yoki xatoda null.
     *
     * @return array{0: string, 1: string}|null
     */
    private function resolveWindow(): ?array
    {
        $to = trim((string) ($this->option('date-to') ?? ''));
        $to = $to !== '' ? $to : date('Y-m-d');

        $from = trim((string) ($this->option('date') ?? ''));
        if ($from === '') {
            $days = max(0, (int) $this->option('days'));
            $from = date('Y-m-d', strtotime($to . ' -' . $days . ' days'));
        }

        foreach (['--date' => $from, '--date-to' => $to] as $name => $v) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || $v !== date('Y-m-d', strtotime($v))) {
                $this->error($name . ' noto\'g\'ri formatda: ' . $v . ' (kutilgani YYYY-MM-DD)');
                return null;
            }
        }
        if ($from > $to) {
            $this->error('--date (' . $from . ') --date-to (' . $to . ') dan katta.');
            return null;
        }

        return [$from, $to];
    }

    /**
     * Bitta jadvalni oyna bo'yicha tenglashtiradi.
     */
    private function pullTable(string $srcName, string $dstName, string $table, array $pk, string $from, string $to, int $chunk, bool $isDry): bool
    {
        $src = DB::connection($srcName);
        $dst = DB::connection($dstName);

        if (! $src->getSchemaBuilder()->hasTable($table)) {
            $this->error($table . ': ' . $srcName . ' da jadval yo\'q.');
            return false;
        }
        if (! $dst->getSchemaBuilder()->hasTable($table)) {
            $this->error($table . ': ' . $dstName . ' da jadval yo\'q — avval `php artisan pg:sync ' . $table . '` bilan yarating.');
            return false;
        }

        // Nishon sxemasi: MySQL da bor-u PG da yo'q ustunlar tashlab yuboriladi
        // (sxema drifti xato bermasin) — ApplyIndexRows::normalize bilan bir xil.
        $dstCols = [];
        foreach ($dst->getSchemaBuilder()->getColumns($table) as $col) {
            $dstCols[(string) $col['name']] = true;
        }
        foreach ($pk as $keyCol) {
            if (! isset($dstCols[$keyCol])) {
                $this->error($table . ': PK ustuni "' . $keyCol . '" ' . $dstName . ' da yo\'q.');
                return false;
            }
        }

        $this->line('');
        $this->info($table . '  (PK: ' . implode('+', $pk) . ')');

        $totIns = 0;
        $totHave = 0;
        $totBad = 0;

        // KUNMA-KUN: PG kalitlari to'plami bir kunlik hajmda ushlab turiladi —
        // yillik backfill'da ham xotira chegaralangan bo'ladi.
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $res = $this->pullDay($src, $dst, $table, $pk, $dstCols, $d, $chunk, $isDry);
            if ($res === null) {
                return false;   // xato pullDay ichida chop etilgan
            }

            [$read, $ins, $have, $bad] = $res;
            $totIns  += $ins;
            $totHave += $have;
            $totBad  += $bad;

            // Jim kunlar bitta qatorni ham band qilmasin — faqat ish bo'lganda yozamiz.
            if ($read > 0) {
                $this->line('  ' . $d . ': MySQL=' . $read
                    . '; yetishmagan=' . $ins . ($isDry ? ' (yozilmadi, dry-run)' : ' yozildi')
                    . '; bor edi=' . $have
                    . ($bad > 0 ? '; kalitsiz=' . $bad : ''));
            }
        }

        $this->info('  JAMI: ' . ($isDry ? 'yetishmayapti=' : 'yozildi=') . $totIns
            . '; allaqachon bor=' . $totHave
            . ($totBad > 0 ? '; kalitsiz tashlandi=' . $totBad : ''));

        return true;
    }

    /**
     * Bitta KUN: PG dagi kalitlar to'plami → MySQL qatorlari → farqni yozish.
     *
     * @return array{0:int,1:int,2:int,3:int}|null  [o'qildi, yozildi, bor edi, kalitsiz] | xatoda null
     */
    private function pullDay($src, $dst, string $table, array $pk, array $dstCols, string $day, int $chunk, bool $isDry): ?array
    {
        try {
            // 1) PG da shu kunda BOR kalitlar. select faqat PK ustunlari —
            //    qator emas, kalit kortej; 100k kun uchun ham yengil.
            $have = [];
            foreach ($dst->table($table)->where('dt', $day)->select($pk)->cursor() as $row) {
                $have[$this->keyOf((array) $row, $pk)] = true;
            }

            $read = 0;
            $ins = 0;
            $already = 0;
            $bad = 0;
            $batch = [];
            $offset = 0;

            while (true) {
                $q = $src->table($table)->where('dt', $day)->limit($chunk)->offset($offset);
                foreach ($pk as $col) {
                    $q->orderBy($col);
                }
                $rows = $q->get();
                $got = count($rows);
                if ($got === 0) {
                    break;
                }
                $read += $got;

                foreach ($rows as $row) {
                    $norm = $this->normalize((array) $row, $dstCols, $pk);
                    if ($norm === null) {
                        $bad++;
                        continue;
                    }

                    $key = $this->keyOf($norm, $pk);
                    if (isset($have[$key])) {
                        $already++;
                        continue;
                    }

                    // Shu ishga tushishning o'zida ham takror bo'lmasin
                    // (nazariy jihatdan MySQL da PK bor, lekin himoya arzon).
                    $have[$key] = true;
                    $batch[] = $norm;

                    if (count($batch) >= 500) {
                        $ins += $this->flush($dst, $table, $batch, $isDry);
                        $batch = [];
                    }
                }

                $offset += $got;
                if ($got < $chunk) {
                    break;
                }
            }

            if ($batch !== []) {
                $ins += $this->flush($dst, $table, $batch, $isDry);
            }

            return [$read, $ins, $already, $bad];
        } catch (\Throwable $e) {
            $this->error('  ' . $day . ' XATO: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Partiyani yozadi (dry-run da faqat sanaydi). Bitta INSERT dagi qatorlar
     * bir xil ustunlar to'plamiga ega bo'lishi shart — shakl bo'yicha guruhlanadi.
     *
     * @param list<array<string, mixed>> $batch
     */
    private function flush($dst, string $table, array $batch, bool $isDry): int
    {
        if ($isDry) {
            return count($batch);
        }

        $groups = [];
        foreach ($batch as $row) {
            $keys = array_keys($row);
            sort($keys);
            $groups[implode('|', $keys)][] = $row;
        }

        $n = 0;
        foreach ($groups as $group) {
            // insertOrIgnore — PK constraint BOR joyda parallel push bilan
            // poyga bo'lsa ham xato bermaydi; constraint yo'q joyda esa
            // duplikatdan yuqoridagi `have` to'plami saqlaydi.
            $n += (int) $dst->table($table)->insertOrIgnore($group);
        }

        return $n;
    }

    /**
     * Qatorni nishon sxemasiga moslaydi — ApplyIndexRows::normalize bilan
     * bir xil qoidalar: PG da yo'q ustun tashlanadi, MySQL "nol sanasi"
     * NULL ga aylanadi, PK ustuni yo'q/NULL bo'lsa qator yaroqsiz.
     *
     * @return array<string, mixed>|null
     */
    private function normalize(array $row, array $dstCols, array $pk): ?array
    {
        $out = [];
        foreach ($row as $col => $value) {
            if (! is_string($col) || ! isset($dstCols[$col])) {
                continue;
            }
            if (is_string($value)) {
                $t = trim($value);
                if ($t === '0000-00-00' || $t === '0000-00-00 00:00:00') {
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
     * PK kortejini solishtirish kalitiga aylantiradi.
     *
     * Kanoniklashtirish SHART: MySQL PDO (mysqlnd) sonlarni int qilib beradi,
     * PG PDO esa hammasini satr qaytaradi — `2026` va `'2026'` to'g'ridan-
     * to'g'ri qo'shilsa ikki xil kalit chiqib, bor qator "yo'q" deb qayta
     * yozilardi. Raqamli qiymatlar int-satrga keltiriladi; sana/vaqt qiymatlar
     * faqat sana qismigacha qisqartiriladi (PG `2026-09-02` bilan MySQL
     * `2026-09-02 00:00:00` bir xil kun).
     */
    private function keyOf(array $row, array $pk): string
    {
        $parts = [];
        foreach ($pk as $col) {
            $v = $row[$col] ?? null;
            $s = (string) $v;

            if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]|$)/', $s)) {
                $s = substr($s, 0, 10);
            } elseif ($s !== '' && ctype_digit($s)) {
                $s = (string) (int) $s;
            }

            $parts[] = $s;
        }

        return implode('|', $parts);
    }
}
