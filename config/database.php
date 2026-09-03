<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

/*
|--------------------------------------------------------------------------
| egaz-index13 — ulanishlar egaz-indexator dagilar bilan AYNAN bir xil
|--------------------------------------------------------------------------
|
|   mysql       → DB_DATABASE   (egaz_idxdb — STANDART; ilovaning o'z bazasi)
|   mysql_brrgz → DB_DATABASE1  (DB_HOST da — asosiy egaz bazasi)
|   mysql1      → DB_DATABASE1  (DB_HOST1 da — EGAZ MAIN / brrgz: manba o'qish
|                                va tb_fc_invoices / tb_factory_signatures /
|                                tb_social_sphere yozish)
|   mysql_egaz  → DB_DATABASE2  (DB_HOST1 da)
|   clickhouse  → CLICKHOUSE_*  (smi2/phpclickhouse; Laravel driver EMAS,
|                                faqat sozlama saqlagich — ClickHouseServiceProvider o'qiydi)
|   pgsql       → PGIDX_*       (indexator PostgreSQL — egaz_idxpost)
|   pgsql1      → PGPUSH_*      (egaz-push asosiy PostgreSQL — egaz_push)
|
| DIQQAT: env kalit nomlari o'zgartirilmadi — mavjud .env fayl shundayligicha ishlaydi.
|
| PostgreSQL ulanishlari egaz-push dagilarning shu loyihadagi nomlari. Push'dan
| kod ko'chirilganda ulanish nomi shu jadval bo'yicha almashtiriladi:
|
|   egaz-push          egaz-index13   Baza
|   ---------          ------------   -----
|   postgres_02   →    pgsql          egaz_idxpost (indexator/statistika DB)
|   pgsql         →    pgsql1         egaz_push    (push asosiy DB)
|
| Sabab: bu loyihada "raqamsiz" nom har doim LOYIHANING O'ZINIKI (mysql =
| egaz_idxdb), "1" li nom esa TASHQI bazani bildiradi (mysql1 = egaz brrgz).
| PostgreSQL tomonida ham shu tartib: pgsql = indexator, pgsql1 = push.
|
| ⚙ ILOVA EGAZ-INDEXATOR DAGIDEK MYSQL DA ISHLAYDI:
|     o'qish — manba jadvallar (cms_users, organizations, tb_gas_debit,
|              tb_requests* ...) `mysql1` dan, o'z jadvallari `mysql` dan;
|     yozish — `mysql` (egaz_idxdb) va `mysql1` (brrgz).
|   PostgreSQL ulanishlari (pgsql, pgsql1) FAQAT NUSXA uchun: DUAL_WRITE=true
|   bo'lsa har bir yozuv ularga ham ko'chiriladi (config/dual_write.php);
|   DUAL_WRITE=false bo'lsa ularga umuman tegilmaydi — ilova aynan
|   egaz-indexator kabi ishlaydi. `pg:sync` / `pg:pull` — nusxani tiklash qurollari.
|
| ⚠️ Lokalda ikkala PG ulanishi bitta bazaga qaratilgan bo'lishi mumkin —
|   prod'da egaz_idxpost alohida serverda (192.168.0.6) turadi.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    */

    // Standart ulanish — MySQL (`mysql` = egaz_idxdb), egaz-indexator dagidek.
    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        // egaz_idxdb — indexator o'z bazasi (barcha i_* / idx_* agregatlari,
        // integration_logs, tb_factory_signature_logs, tb_scales_logs, ...)
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mysql_brrgz' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE1', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // EGAZ MAIN (brrgz) — cms_users, organizations, tb_gas_debit,
        // tb_requests(_ballons), tb_fc_invoices, tb_factory_signatures, ...
        'mysql1' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST1', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE1', 'forge'),
            'username' => env('DB_USERNAME1', 'forge'),
            'password' => env('DB_PASSWORD1', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mysql_egaz' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST1', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE2', 'forge'),
            'username' => env('DB_USERNAME1', 'forge'),
            'password' => env('DB_PASSWORD1', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // ClickHouse — Laravel PDO drayveri emas. Bu blok faqat sozlamalarni
        // bir joyda ushlab turadi; haqiqiy klient App\Providers\ClickHouseServiceProvider
        // da CLICKHOUSE_* env lardan quriladi (egaz-indexator dagidek).
        'clickhouse' => [
            'driver' => 'clickhouse',
            'host' => env('CLICKHOUSE_HOST'),
            'port' => env('CLICKHOUSE_PORT', '8123'),
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            'timeout_connect' => env('CLICKHOUSE_TIMEOUT_CONNECT', 2),
            'timeout_query' => env('CLICKHOUSE_TIMEOUT_QUERY', 2),
            'https' => (bool) env('CLICKHOUSE_HTTPS', null),
            'retries' => env('CLICKHOUSE_RETRIES', 0),
            'settings' => [ // optional
                'max_partitions_per_insert_block' => 300,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | `pgsql` — indexator PostgreSQL (egaz-push dagi `postgres_02`)
        |----------------------------------------------------------------------
        |
        | Tarkibi: i_* / idx_* agregatlari, vw_* view'lar, tb_scales_logs,
        | tb_levelmeters, tb_gas_dispensers, integration_logs, forecast_* ...
        | Ya'ni shu loyihaning O'Z bazasi (`mysql` = egaz_idxdb) ning PG nusxasi.
        |
        | Push tarafda bu ulanish `postgres_02` deb ataladi va IDX_DB_* env
        | larini o'qiydi; bu yerda nomi `pgsql`, env prefiksi esa PGIDX_* —
        | chunki DB_* bu loyihada MySQL niki (DB_HOST=...:3306).
        |
        | `pg:sync` STANDART holda bu ulanishga EMAS, `pgsql1` ga yozadi
        | (eski xatti-harakat saqlangan) — `--target=pgsql` bilan almashtiriladi.
        */
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('PGIDX_HOST', '127.0.0.1'),
            'port' => env('PGIDX_PORT', '5432'),
            'database' => env('PGIDX_DATABASE', 'egaz_idxpost'),
            'username' => env('PGIDX_USERNAME', 'postgres'),
            'password' => env('PGIDX_PASSWORD', ''),
            'charset' => env('PGIDX_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // L5.5 da kalit `schema` edi, L9+ da `search_path`. Ikkalasi ham qoldirildi:
            // `search_path` ni framework o'qiydi, `schema` ni esa `pg:check` chiqaradi.
            'search_path' => env('PGIDX_SCHEMA', 'public'),
            'schema' => env('PGIDX_SCHEMA', 'public'),
            'sslmode' => env('PGIDX_SSLMODE', 'prefer'),
            'options' => extension_loaded('pdo_pgsql') ? array_replace([
                PDO::ATTR_TIMEOUT => (int) env('PGIDX_TIMEOUT', 30),
                PDO::ATTR_PERSISTENT => false,
            ], env('PGIDX_STRINGIFY', false) ? [
                // Push'dagi `postgres_02` da bu YOQIQ (eski mysql_02 satr qaytarardi).
                // Bu yerda STANDART O'CHIQ: pg:sync MAX(id) / count() larni son deb
                // o'qiydi. Push'dan ko'chirilgan wire-parity kerak bo'lgan kodni
                // ishlatsangiz PGIDX_STRINGIFY=true qiling.
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ] : []) : [],
        ],

        /*
        |----------------------------------------------------------------------
        | `pgsql1` — egaz-push asosiy PostgreSQL (push tarafdagi `pgsql`)
        |----------------------------------------------------------------------
        |
        | egaz asosiy bazasining (brrgz) PG variantidagi o'rnini bosuvchi baza:
        | cms_users, organizations, tb_gas_debit, tb_requests(_ballons), ...
        | MySQL tomonidagi `mysql1` ning PostgreSQL ekvivalenti.
        |
        | `pg:sync` STANDART holda shu ulanishga yozadi.
        | DIQQAT: DB_* o'zgaruvchilari MySQL niki, shuning uchun alohida PGPUSH_*.
        */
        'pgsql1' => [
            'driver' => 'pgsql',
            'host' => env('PGPUSH_HOST', '127.0.0.1'),
            'port' => env('PGPUSH_PORT', '5432'),
            'database' => env('PGPUSH_DATABASE', 'forge'),
            'username' => env('PGPUSH_USERNAME', 'forge'),
            'password' => env('PGPUSH_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('PGPUSH_SCHEMA', 'public'),
            'schema' => env('PGPUSH_SCHEMA', 'public'),
            'sslmode' => env('PGPUSH_SSLMODE', 'prefer'),
            'options' => extension_loaded('pdo_pgsql') ? array_replace([
                PDO::ATTR_TIMEOUT => (int) env('PGPUSH_TIMEOUT', 30),
                PDO::ATTR_PERSISTENT => false,
            ], env('PGPUSH_STRINGIFY', false) ? [
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ] : []) : [],
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
