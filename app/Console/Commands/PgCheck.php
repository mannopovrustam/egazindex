<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL (egaz-push) ulanishini tekshiradi — hech narsa o'zgartirmaydi.
 *
 * `pg:sync` ni ishga tushirishdan oldin shu komanda bilan ulanish va huquqlar
 * joyidami-yo'qmi ko'rib olinadi.
 *
 * Tekshiriladigan PostgreSQL ulanishi --target orqali tanlanadi:
 *   pgsql  — ilovaning o'z bazasi (STANDART; `pg:sync` ham shunga yozadi)
 *   pgsql1 — egaz-push asosiy bazasi
 *
 * Nimalarni ko'radi:
 *   1. PHP kengaytmalari (pdo_pgsql / pgsql) o'rnatilganmi
 *   2. config/database.php dagi tanlangan ulanish sozlamalari (parol yashiriladi)
 *   3. Ulanish ochiladimi + PostgreSQL versiyasi va javob tezligi
 *   4. Sessiya: baza, foydalanuvchi, schema, kodlash, vaqt mintaqasi
 *   5. Yozish huquqi — VAQTINCHALIK (TEMP) jadval orqali, doimiy o'zgarish YO'Q
 *   6. Jadvallar soni
 *   7. Manba tomoni ham xuddi shunday tekshiriladi (solishtirish uchun).
 *      Manba MySQL ham, PostgreSQL ham bo'lishi mumkin — dialekt driver'ga
 *      qarab tanlanadi (DATABASE() / EXTRA / STATISTICS faqat MySQL'da bor).
 *   8. --table berilsa o'sha jadval ikkala bazada bormi
 *
 * Misollar:
 *   php artisan pg:check
 *   php artisan pg:check --table=i_balance
 *   php artisan pg:check --connection=mysql1        # egaz asosiy (brrgz) tomonini
 *   php artisan pg:check --connection=pgsql         # manba ham PG bo'lsa
 *   php artisan pg:check --target=pgsql1            # egaz-push bazasini
 *
 * DIQQAT: egaz-indexator Laravel 5.5 da ishlaydi — bu faylda 7.1+ sintaksisi
 * (nullable type hint, void, arrow function, str_contains) ISHLATILMAYDI.
 */
class PgCheck extends Command
{
    protected $signature = 'pg:check
                            {--connection=mysql : Tekshiriladigan manba ulanishi — MySQL yoki PostgreSQL (mysql = egaz_idxdb, mysql1 = egaz asosiy brrgz, pgsql = indexator PG, pgsql1 = egaz-push)}
                            {--target=pgsql : Tekshiriladigan PostgreSQL ulanishi (pgsql = ilovaning o\'z bazasi, STANDART; pgsql1 = egaz-push)}
                            {--table= : Shu jadval ikkala bazada bor-yo\'qligini ham tekshiradi}';

    protected $description = 'PostgreSQL (egaz-push / indexator) ulanishini tekshiradi — hech narsa o\'zgartirmaydi';

    public function handle()
    {
        $ok      = true;
        $srcName = trim($this->option('connection'));
        $dstName = trim($this->option('target'));

        // ── 1. PHP kengaytmalari ──────────────────────────────────────────
        $this->line('');
        $this->comment('1) PHP kengaytmalari');
        $this->kv('PHP versiyasi', PHP_VERSION);

        $pdoDrivers = class_exists('PDO') ? \PDO::getAvailableDrivers() : array();
        $hasPdoPg   = in_array('pgsql', $pdoDrivers, true);

        $this->kv('pdo_pgsql', $hasPdoPg ? 'BOR' : 'YO\'Q');
        $this->kv('pgsql', extension_loaded('pgsql') ? 'BOR' : 'YO\'Q');
        $this->kv('PDO drayverlari', $pdoDrivers ? implode(', ', $pdoDrivers) : '(bo\'sh)');

        if (! $hasPdoPg) {
            $this->line('');
            $this->error('pdo_pgsql kengaytmasi yo\'q — ulanish umuman ishlamaydi.');
            $this->missingExtensionHelp();
            return 1;
        }

        // ── 2. Sozlamalar ─────────────────────────────────────────────────
        $this->line('');
        $this->comment('2) config/database.php → connections.' . $dstName);

        $cfg = config('database.connections.' . $dstName);
        if (! is_array($cfg)) {
            $this->error('"' . $dstName . '" ulanishi config/database.php da topilmadi.');
            return 1;
        }
        foreach (array('driver', 'host', 'port', 'database', 'username', 'schema', 'sslmode') as $k) {
            $this->kv($k, isset($cfg[$k]) ? (string) $cfg[$k] : '(yo\'q)');
        }
        $this->kv('password', $this->maskSecret(isset($cfg['password']) ? $cfg['password'] : ''));

        // ── 3. Ulanish ────────────────────────────────────────────────────
        $this->line('');
        $this->comment('3) PostgreSQL ulanishi (' . $dstName . ')');

        try {
            $t0  = microtime(true);
            $pg  = DB::connection($dstName);
            $ver = $pg->selectOne('SELECT version() AS v');
            $ms  = round((microtime(true) - $t0) * 1000, 1);

            $this->info('  ✓ Ulanish muvaffaqiyatli (' . $ms . ' ms)');
            $this->kv('Versiya', $this->firstLine($ver->v));
        } catch (\Exception $e) {
            $this->error('  ✗ Ulanib bo\'lmadi');
            $this->line('');
            $this->line('  ' . $e->getMessage());
            $this->line('');
            $this->hintForError($e->getMessage(), $cfg, $this->envPrefix($dstName));
            return 1;
        }

        // ── 4. Sessiya ma'lumotlari ───────────────────────────────────────
        $this->line('');
        $this->comment('4) Sessiya');
        try {
            $s = $pg->selectOne(
                'SELECT current_database() AS db, current_user AS usr, current_schema() AS sch,
                        current_setting(\'server_encoding\') AS senc,
                        current_setting(\'client_encoding\') AS cenc,
                        current_setting(\'TimeZone\') AS tz,
                        inet_server_addr()::text AS srv, inet_server_port() AS prt'
            );
            $this->kv('Baza', $s->db);
            $this->kv('Foydalanuvchi', $s->usr);
            $this->kv('Schema', $s->sch);
            $this->kv('Kodlash (server/client)', $s->senc . ' / ' . $s->cenc);
            $this->kv('Vaqt mintaqasi', $s->tz);
            $this->kv('Server manzili', ($s->srv === null ? 'local socket' : $s->srv) . ':' . $s->prt);

            if (strtoupper($s->senc) !== 'UTF8') {
                $this->warn('  ! Server kodlashi UTF8 emas — kirill matnlarda muammo bo\'lishi mumkin.');
                $ok = false;
            }
        } catch (\Exception $e) {
            $this->warn('  ! Sessiya ma\'lumotlarini o\'qib bo\'lmadi: ' . $e->getMessage());
        }

        // ── 5. Yozish huquqi (TEMP jadval — doimiy o'zgarish yo'q) ────────
        $this->line('');
        $this->comment('5) Yozish huquqi (vaqtincha jadval, doimiy o\'zgarish yo\'q)');
        try {
            $pg->statement('CREATE TEMP TABLE _pg_check_tmp (id int, nom text)');
            $pg->statement('INSERT INTO _pg_check_tmp (id, nom) VALUES (1, \'sinov\')');
            $n = $pg->selectOne('SELECT count(*) AS c FROM _pg_check_tmp');
            $pg->statement('DROP TABLE _pg_check_tmp');
            $this->info('  ✓ CREATE / INSERT / DROP ishladi (' . $n->c . ' qator)');
        } catch (\Exception $e) {
            $this->warn('  ! Yozib bo\'lmadi: ' . $e->getMessage());
            $this->line('    Faqat o\'qish huquqi bo\'lsa pg:sync ishlamaydi.');
            $ok = false;
        }

        // ── 6. Jadvallar ──────────────────────────────────────────────────
        $this->line('');
        $this->comment('6) Jadvallar');
        try {
            $c = $pg->selectOne(
                'SELECT count(*) AS c FROM information_schema.tables
                  WHERE table_schema = current_schema() AND table_type = \'BASE TABLE\''
            );
            $this->kv('PostgreSQL jadvallari', $c->c . ' ta');
        } catch (\Exception $e) {
            $this->warn('  ! ' . $e->getMessage());
        }

        // ── 7. MySQL tomoni ───────────────────────────────────────────────
        $this->line('');
        $srcCfg = config('database.connections.' . $srcName);
        $srcDrv = is_array($srcCfg) && isset($srcCfg['driver']) ? $srcCfg['driver'] : '?';

        $this->comment('7) Manba (' . $srcName . ' → ' . $srcDrv . ') tomoni');

        $my = null;
        if ($srcCfg === null) {
            $this->error('  ✗ "' . $srcName . '" ulanishi config/database.php da yo\'q.');
            $this->line('    Mavjud ulanishlar: ' . implode(', ', array_keys((array) config('database.connections'))));
            $ok = false;
        } else {
            try {
                $t0 = microtime(true);
                $my = DB::connection($srcName);

                // Manba MySQL bo'lishi shart emas: idxdb ham PostgreSQL'ga ko'chirilgan,
                // shuning uchun `--connection=pgsql` ham beriladi. DATABASE() / EXTRA /
                // information_schema.STATISTICS — MySQL'ga xos, PG'da yo'q.
                $mv = $my->selectOne($this->isPg($my)
                    ? 'SELECT version() AS v, current_database() AS db'
                    : 'SELECT VERSION() AS v, DATABASE() AS db');
                $ms = round((microtime(true) - $t0) * 1000, 1);

                $mc = $my->selectOne($this->isPg($my)
                    ? 'SELECT count(*) AS c FROM information_schema.tables
                        WHERE table_schema = current_schema() AND table_type = \'BASE TABLE\''
                    : 'SELECT COUNT(*) AS c FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'');

                $this->info('  ✓ Ulanish muvaffaqiyatli (' . $ms . ' ms)');
                $this->kv('Versiya', $this->firstLine($mv->v));
                $this->kv('Baza', $mv->db);
                $this->kv('Jadvallar', $mc->c . ' ta');
            } catch (\Exception $e) {
                $this->error('  ✗ "' . $srcName . '" ga ulanib bo\'lmadi: ' . $e->getMessage());
                $my = null;
                $ok = false;
            }
        }

        // ── 8. Jadval tekshiruvi ──────────────────────────────────────────
        $table = $this->option('table');
        if ($table !== null && $table !== '') {
            $this->line('');
            $this->comment('8) "' . $table . '" jadvali');

            $pgColsSql = 'SELECT count(*) AS c FROM information_schema.columns
                           WHERE table_schema = current_schema() AND table_name = ?';
            $myColsSql = 'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';

            $inPg = $this->tableInfo($pg, $pgColsSql, $table);
            $inMy = $my === null ? null : $this->tableInfo($my,
                $this->isPg($my) ? $pgColsSql : $myColsSql, $table);

            $this->kv('PostgreSQL da (' . $dstName . ')', $inPg === null ? '(tekshirilmadi)' : ($inPg > 0 ? 'BOR (' . $inPg . ' ustun)' : 'YO\'Q'));
            $this->kv('Manbada (' . $srcName . ')', $inMy === null ? '(tekshirilmadi)' : ($inMy > 0 ? 'BOR (' . $inMy . ' ustun)' : 'YO\'Q'));

            if ($inMy === 0) {
                $this->warn('  ! ' . $srcName . ' da yo\'q — pg:sync ishlamaydi.');
                $ok = false;
            } elseif ($inPg === 0) {
                $this->line('  PostgreSQL da yo\'q — pg:sync uni MySQL strukturasidan yaratadi.');
            }

            // Jadvalda o'suvchi kalit bormi — pg:sync qaysi rejimda ishlashini belgilaydi
            if ($inMy !== null && $inMy > 0) {
                $this->keyHint($my, $table);
            }
        }

        $this->line('');
        if ($ok) {
            $this->info('Natija: ulanish ishlayapti. Endi sinab ko\'ring:');
            $this->line('    php artisan pg:sync <jadval> --dry-run'
                . ($srcName === 'mysql' ? '' : ' --connection=' . $srcName));
        } else {
            $this->warn('Natija: yuqoridagi ogohlantirishlarga qarang.');
        }
        $this->line('');

        return 0;
    }

    /**
     * Jadvalda auto_increment kalit bor-yo'qligiga qarab pg:sync ning qaysi
     * rejimi kerakligini aytadi. Indexator agregatlarining ko'pchiligida
     * o'suvchi kalit yo'q — ular faqat --full bilan ko'chadi.
     */
    private function keyHint($conn, $table)
    {
        $isPg = $this->isPg($conn);

        try {
            $auto = $conn->selectOne($isPg
                ? 'SELECT column_name AS c FROM information_schema.columns
                    WHERE table_schema = current_schema() AND table_name = ?
                      AND (column_default LIKE \'nextval(%\' OR is_identity = \'YES\')
                    LIMIT 1'
                : 'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                      AND EXTRA LIKE \'%auto_increment%\' LIMIT 1',
                array($table)
            );
        } catch (\Exception $e) {
            return;
        }

        if ($auto !== null && $auto->c !== null) {
            $this->kv('O\'suvchi kalit', $auto->c);
            if ($auto->c !== 'id') {
                $this->line('  Kalit "id" emas — pg:sync ga bering: --key=' . $auto->c);
            }
            return;
        }

        $this->kv('O\'suvchi kalit', 'YO\'Q');

        try {
            $pk = $conn->select($isPg
                ? 'SELECT kcu.column_name AS c
                     FROM information_schema.table_constraints tc
                     JOIN information_schema.key_column_usage kcu
                       ON kcu.constraint_name = tc.constraint_name
                      AND kcu.table_schema    = tc.table_schema
                    WHERE tc.table_schema    = current_schema()
                      AND tc.table_name      = ?
                      AND tc.constraint_type = \'PRIMARY KEY\'
                    ORDER BY kcu.ordinal_position'
                : 'SELECT COLUMN_NAME AS c FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = \'PRIMARY\'
                    ORDER BY SEQ_IN_INDEX',
                array($table)
            );
        } catch (\Exception $e) {
            $pk = array();
        }

        $cols = array();
        foreach ($pk as $r) {
            $cols[] = $r->c;
        }
        $this->kv('Birlamchi kalit', $cols ? implode(', ', $cols) : '(yo\'q)');
        $this->line('  O\'suvchi kalit yo\'q — bu jadval faqat to\'liq ko\'chadi:');
        $this->line('      php artisan pg:sync ' . $table . ' --full');
    }

    /**
     * Ulanish PostgreSQL'mi. Manba har doim MySQL emas — idxdb ham PG'ga
     * ko'chirilgan, shuning uchun `--connection=pgsql` ham beriladi.
     */
    private function isPg($conn)
    {
        try {
            return $conn->getDriverName() === 'pgsql';
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Jadval ustunlari sonini qaytaradi; xato bo'lsa null. */
    private function tableInfo($conn, $sql, $table)
    {
        try {
            $r = $conn->selectOne($sql, array($table));
            return (int) $r->c;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** pdo_pgsql yo'q bo'lsa — ishlayotgan OS ga mos o'rnatish yo'riqnomasi. */
    private function missingExtensionHelp()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows (WAMP — lokal ishlab chiqish)
            $this->line('  php.ini da quyidagi qatorlardan ";" ni olib tashlang va PHP ni qayta ishga tushiring:');
            $this->line('      extension=php_pdo_pgsql.dll');
            $this->line('      extension=php_pgsql.dll');
        } else {
            // Linux (server) — .dll emas, apt paketi kerak
            $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $this->line('  Serverda paketni o\'rnating (joriy PHP ' . $v . ' uchun):');
            $this->line('      sudo apt-get update');
            $this->line('      sudo apt-get install -y php' . $v . '-pgsql');
            $this->line('      sudo phpenmod -v ' . $v . ' pdo_pgsql pgsql');
            $this->line('      sudo systemctl restart php' . $v . '-fpm    # veb tomoni uchun');
            $this->line('  Tekshirish: php -m | grep -i pgsql');
        }

        $this->line('  Joriy php.ini: ' . (php_ini_loaded_file() ? php_ini_loaded_file() : '(aniqlanmadi)'));
        $this->line('  PHP fayli: ' . (defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : '(aniqlanmadi)'));
    }

    /**
     * Ulanish nomiga mos .env kalit prefiksi — maslahatlarda to'g'ri
     * o'zgaruvchi nomini ko'rsatish uchun (pgsql → PGIDX_, pgsql1 → PGPUSH_).
     */
    private function envPrefix($conn)
    {
        return $conn === 'pgsql' ? 'PGIDX' : 'PGPUSH';
    }

    /** Xato matniga qarab amaliy maslahat beradi. */
    private function hintForError($msg, array $cfg, $env = 'PGPUSH')
    {
        $m    = strtolower($msg);
        $host = isset($cfg['host']) ? $cfg['host'] : '?';
        $port = isset($cfg['port']) ? $cfg['port'] : '5432';

        if (strpos($m, 'could not find driver') !== false) {
            $this->line('  → pdo_pgsql yoqilmagan.');
            $this->missingExtensionHelp();
            return;
        }
        if (strpos($m, 'no password supplied') !== false || strpos($m, 'fe_sendauth') !== false) {
            $this->line('  → Parol berilmagan, lekin server uni talab qilyapti (scram-sha-256/md5).');
            $this->line('    .env da ' . $env . '_PASSWORD ni to\'ldiring. .env fayli umuman bormi tekshiring:');
            $this->line('    hozirgi qiymatlar config default\'lari bo\'lib qolgan bo\'lishi mumkin.');
            return;
        }
        if (strpos($m, 'role') !== false && strpos($m, 'does not exist') !== false) {
            $this->line('  → Bunday foydalanuvchi (role) yo\'q. ' . $env . '_USERNAME ni tekshiring.');
            return;
        }
        if (strpos($m, 'password authentication failed') !== false || strpos($m, 'authentication') !== false) {
            $this->line('  → Login/parol noto\'g\'ri yoki pg_hba.conf usuli mos emas.');
            $this->line('    .env dagi ' . $env . '_USERNAME / ' . $env . '_PASSWORD ni tekshiring.');
            return;
        }
        if (strpos($m, 'does not exist') !== false && strpos($m, 'database') !== false) {
            $this->line('  → Bunday baza yo\'q. ' . $env . '_DATABASE qiymatini tekshiring.');
            return;
        }
        if (strpos($m, 'no pg_hba.conf entry') !== false) {
            $this->line('  → Serverdagi pg_hba.conf ga shu mashina IP si qo\'shilmagan.');
            $this->line('    Masalan: host <baza> <user> <IP>/32 scram-sha-256');
            return;
        }
        if (strpos($m, 'connection refused') !== false || strpos($m, 'timed out') !== false
            || strpos($m, 'timeout') !== false || strpos($m, 'unreachable') !== false) {
            $this->line('  → ' . $host . ':' . $port . ' ga yetib borilmadi. Tekshiring:');
            $this->line('    - PostgreSQL ishlayaptimi');
            $this->line('    - postgresql.conf da listen_addresses tashqi interfeysni tinglaydimi');
            $this->line('    - firewall da ' . $port . ' porti ochiqmi');
            return;
        }

        $this->line('  → Sozlamalarni (host/port/baza/login) va tarmoqni tekshiring.');
    }

    private function maskSecret($v)
    {
        $v = (string) $v;
        if ($v === '') {
            return '(bo\'sh)';
        }
        return str_repeat('*', min(8, strlen($v))) . ' (' . strlen($v) . ' belgi)';
    }

    private function firstLine($s)
    {
        $parts = explode("\n", (string) $s);
        return trim($parts[0]);
    }

    private function kv($k, $v)
    {
        $this->line('  ' . str_pad($k, 24, '.') . ' ' . $v);
    }
}
