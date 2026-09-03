# egaz-indexator (Laravel 5.5) → egaz-index13 (Laravel 13) ko'chirish hisoboti

**Maqsad:** `egaz-indexator` ni Laravel 13 da qaytadan qurish, lekin **tashqi
xulq-atvorini o'zgartirmasdan** — URL lar, HTTP javob tanalari va status
kodlari, artisan komanda nomlari/argumentlari, DB ulanish nomlari va jadval
nomlari AYNAN o'sha holicha qoldi. Boshqa servislar (1C, tarozi, GNP kamera,
dispenserlar, UzGPS, ijtimoiy soha, sath o'lchagichlar, egaz asosiy tizimi)
hech narsani o'zgartirmasdan shu loyihaga ulanaveradi.

`egaz-indexator` ga **hech qanday o'zgartirish kiritilmadi** — u ishlab
turaveradi. Bu papka mustaqil, yangi loyiha.

| | egaz-indexator | egaz-index13 |
|---|---|---|
| Framework | Laravel 5.5 | Laravel 13.24 |
| PHP | >= 7.0 | ^8.4 |
| Qo'shimcha paketlar | `smi2/phpclickhouse`, `fideloper/proxy` | `smi2/phpclickhouse`, `laravel/ui` |
| Skelet | `app/Http/Kernel.php`, `app/Console/Kernel.php`, `app/Exceptions/Handler.php`, `RouteServiceProvider` | hammasi `bootstrap/app.php` da |

---

## 1. URL lar — 1:1 mos

`php artisan route:list` bilan tekshirilgan. Quyidagilar **aynan** o'sha:

| Metod | URL | Ishlov beruvchi |
|---|---|---|
| GET | `/` | `welcome` ko'rinishi |
| GET | `/home` (nomi `home`) | `HomeController@index` (+`auth`) |
| GET | `/test-bid/{id}` | closure (`dd`) |
| POST | `/datatransactions` | `App\Jobs\Transaction` |
| POST | `/datarealizations` | `App\Jobs\Realizations` |
| POST | `/uzgps/{ver}` | `App\Services\UzGPS` |
| POST | `/scales/info`, `/scales/photo` | `App\Services\Scales` |
| POST | `/gnp-camera` | `App\Services\GnpCamera` |
| POST | `/factory-invoice` | `App\Services\FactoryInvoice` |
| POST | `/factory-signature` | `App\Services\FactorySignature` |
| POST | `/dispensers` | `App\Services\GasDispenser` |
| POST | `/engraving` | `App\Services\Engraving` |
| POST | `/social-sphere` | `App\Services\SocialSphere` |
| POST | `/levelmeters` | `App\Services\Levelmeters` |
| GET/POST | `Auth::routes()` — `login`, `logout`, `register`, `password/*` | `laravel/ui` |
| GET | `/api/user` | `auth:api` |

**Ataylab QO'SHILMAGAN** (L13 skeleti taklif qiladi, asl loyihada yo'q edi):

* `GET /up` — `withRouting(health: ...)` berilmadi.
* `GET|PUT /storage/{path}` — `config/filesystems.php` da `local` diskning
  `serve` bayrog'i `false` qilindi.

### CSRF

`App\Http\Middleware\VerifyCsrfToken::$except` ro'yxati `bootstrap/app.php`
dagi `validateCsrfTokens(except: [...])` ga aynan ko'chirildi. Tekshirildi:
autentifikatsiyasiz POST so'rov **419 emas**, asl loyihadagidek "Authentication
failed" JSON qaytaradi.

> ⚠ Yangi integratsiya manzili qo'shsangiz, uni shu ro'yxatga ham qo'shing —
> aks holda tashqi tizim 419 oladi.

### Tekshirilgan javoblar (autentifikatsiyasiz POST)

```
/factory-signature   HTTP=401  {"api_status":0,"api_message":"Authentication failed!","api_http":401}
/scales/info         HTTP=200  {"api_status":0,"api_message":"Authentication failed!","api_http":401}
/scales/photo        HTTP=200  {"api_status":0,"api_message":"Authentication failed!","api_http":401}
/dispensers          HTTP=200  {"api_status":0,"api_message":"Authentication failed!","api_http":401}
/engraving           HTTP=200  {"api_status":0,"api_message":"Authentication failed!","api_http":401}
/social-sphere       HTTP=200  {"api_status":0,"api_message":"Authentication failed!","api_http":401}
/levelmeters         HTTP=401  {"api_status":0,"api_message":"Authentication failed!","api_http":401}
/gnp-camera          HTTP=200  {"status":"error","message":"Unauthorized access!","data":null}
/factory-invoice     HTTP=200  {"api_status":0,"api_message":"Authentication failed!","api_http":401}
/uzgps/v1            HTTP=200  {"status":"error","message":"Unauthorized access!","data":null}
```

HTTP status kodlaridagi farq (200 vs 401) — bu asl koddagi holat:
faqat `Levelmeters` va `FactorySignature` `response()->json(..., 401)` deb
ikkinchi argument beradi, qolganlari bermaydi. Ataylab tegilmadi.

---

## 2. Artisan komandalar — 1:1 mos

`app/Console/Commands` dagi 23 ta klass bir xil nom va signatura bilan
ro'yxatdan o'tadi (`php artisan list` bilan tekshirilgan):

```
calcTransactions {arg}        realDetails {arg}             calcRequests {arg}
debit:make {arg}              check:abonents                hour:realize {arg}
abon:info                     deposit:abondepositcalc {status}
debit2clickhouse {type} {value}   real2clickhouse {type} {value}
data2clickhouse {data} {type} {value}   abonent2clickhouse
org:balance {arg}             subscriber-stats {type} {value}
factory_signature:normalize [--limit=100]
export:monthly {month} [--source=*] [--set=] [--connection=] [--column=] [--path=] [--delimiter=]
pg:sync [table] [--connection=] [--full] [--id=] [--key=] [--chunk=] [--no-create] [--no-index] [--dry-run] [--force]
pg:check [--connection=] [--table=]
triggers:status [--conflicts] [--connection=]
haqdorlik {arg}
real:ballons-mah [--dt=] [--region=] [--source=mysql1] [--chunk=500] [--dry-run]
fill:recipientin [--connection=mysql] [--refresh] [--id-abonent=] [--pinfl=] [--limit=] [--chunk=] [--dry-run]
triggers:irl-detail [--date=] [--from=] [--to=] [--org=] [--append] [--dry-run] [--chunk=] [--connection=] [--force]
```

Oxirgi uchtasi keyinroq — egaz-indexator ning `76f4a4e` va `21497f2`
commitlaridan — ko'chirildi. Dialekt farqlari:

| Komanda | egaz-indexator | egaz-index13 |
|---|---|---|
| `real:ballons-mah` | `--source=mysql1`, `datediff(...)`, `` `1to30` `` | `--source=mysql1` (indexator dagidek; `--source=pgsql1` ham mumkin), ulanish drayveriga qarab `datediff` yoki `(DATE 'x' - rdt)` |
| `triggers:irl-detail` | `IFNULL`, `information_schema.TRIGGERS`+`DATABASE()` | `COALESCE`, PG da `information_schema.triggers`+`current_schema()`; ish davomida `php_connections` vaqtincha olib turiladi |
| `fill:recipientin` | — | o'zgarishsiz (so'rov quruvchisi, dialektga bog'liq emas) |

`real:ballons-mah` uchun PostgreSQL jadvali ham kerak:
`database/sql/i_real_ballons_mahallas.pg.sql` (MySQL varianti yonida).

L5.5 da `Console\Kernel::commands()` -> `$this->load(__DIR__.'/Commands')`
qilardi; L13 da `Application::configure()` ichidagi `withCommands()` xuddi
shu papkani avtomatik skanerlaydi — natija bir xil.

**Tekshirildi:** `triggers:status` (bazasiz — ogohlantirish bilan to'liq
jadval chiqaradi) va `pg:check` (jonli PostgreSQL 17 ga ulanib, 8 bosqichning
hammasini bajardi).

---

## 3. Bazalar

`config/database.php` dagi ulanish nomlari va env kalitlari o'zgarmadi:

| Ulanish | Baza | Izoh |
|---|---|---|
| `mysql` | `DB_DATABASE` | egaz_idxdb — **standart** |
| `mysql_brrgz` | `DB_DATABASE1` @ `DB_HOST` | |
| `mysql1` | `DB_DATABASE1` @ `DB_HOST1` | **EGAZ MAIN (brrgz)** |
| `mysql_egaz` | `DB_DATABASE2` @ `DB_HOST1` | |
| `clickhouse` | `CLICKHOUSE_*` | Laravel drayveri emas — sozlama saqlagich |
| `pgsql` | `PGIDX_*` | **indexator PostgreSQL** (egaz_idxpost) |
| `pgsql1` | `PGPUSH_*` | **egaz-push asosiy PG** — `mysql1` ning nusxasi; `pg:sync --target=pgsql1` |

`pgsql` / `pgsql1` bloklarida `search_path` (L9+ nomi) va `schema` (L5.5 nomi)
**ikkalasi ham** bor: birinchisini framework o'qiydi, ikkinchisini `pg:check`
chiqaradi.

### egaz-push → egaz-index13 ulanish nomlari

egaz-push dan kod ko'chirilganda PostgreSQL ulanish nomi shunday almashtiriladi:

| egaz-push | egaz-index13 | Baza |
|---|---|---|
| `postgres_02` | `pgsql` | `egaz_idxpost` — indexator/statistika |
| `pgsql` (standart) | `pgsql1` | `egaz_push` — push asosiy bazasi |

Tartib MySQL tomonidagidek: raqamsiz nom — **loyihaning o'z** bazasi
(`mysql` = egaz_idxdb, `pgsql` = egaz_idxpost), raqamli nom — **tashqi**
baza (`mysql1` = brrgz, `pgsql1` = egaz_push).

Ilgari `pgsql` egaz-push bazasi edi. `pg:sync` va `pg:check` da `--target=`
opsiyasi bor; **standart qiymat `pgsql`** — ilova endi i_* / idx_* agregatlarini
aynan shu bazadan o'qiydi, demak ko'chirish ham shu yerga tushishi kerak.
egaz-push bazasi bilan ishlash uchun `--target=pgsql1` bering.

### ILOVA POSTGRESQL DA (MySQL dan to'liq o'tish)

> ⚙ **BU BO'LIM ENDI QISMAN TARIXIY.** Keyinchalik ilova **ikki bazaga
> yozadigan** qilindi: yozish `egaz-indexator` dagidek MySQL ga ketadi va
> darhol keyin PostgreSQL nusxasiga ko'chiriladi (`id` + `created_at`
> qo'shilib). Ya'ni standart ulanish yana **`mysql`**.
>
> | | Yozish | Nusxa (faqat `DUAL_WRITE=true`) | Manba o'qish |
> |---|---|---|---|
> | O'z bazasi (i_*, idx_*, tb_*) | `mysql` | `pgsql` | `mysql` |
> | Asosiy egaz bazasi | `mysql1` | `pgsql1` | **`mysql1`** (egaz-indexator dagidek) |
>
> 2026-09-03: manba o'qish ham `mysql1` ga (MySQL dialektiga) QAYTARILDI —
> `DUAL_WRITE=false` bo'lganda ilova aynan `egaz-indexator` kabi ishlashi
> shart. Batafsil: [`docs/dual-write.md`](dual-write.md). Pastdagi dialekt
> jadvali endi FAQAT nusxa tomonga (trigger qatlami, `pg:sync`) tegishli —
> ilova kodidagi manba so'rovlari yana MySQL dialektida.

`app/` dagi butun kod PostgreSQL ga o'tkazilgan edi. Ulanish nomlari (o'sha
bosqichda):

| Ilgari | O'sha bosqichda | Baza |
|---|---|---|
| `mysql` (standart) | `pgsql` (standart) | indexator bazasi — i_*, idx_*, vw_* |
| `mysql1` | `pgsql1` | egaz asosiy bazasi (egaz-push) |

`config/database.php` dagi `mysql*` ulanishlari **o'chirilmadi** — dual write
da `mysql` yana standart yozish manzili, `mysql1` esa asosiy egaz bazasiga
yozish uchun ishlatiladi (`pg:sync` uchun manba ham shular).

SQL dialektidagi o'zgarishlar (hammasi izoh bilan belgilangan):

| MySQL | PostgreSQL | Qayerda |
|---|---|---|
| `ON DUPLICATE KEY UPDATE c = c + x` | `ON CONFLICT (<PK>) DO UPDATE SET c = jadval.c + x` | calcTransanctions, Transaction, IMoneyDebit |
| `INSERT IGNORE` | `ON CONFLICT DO NOTHING` | BaseTrigger::insIgnore |
| `IFNULL()` | `COALESCE()` | calcBalance, calcRequests, calcTransanctions, AbonDepositCalc, FindProvider |
| `if(a, b, c)` | `case when a then b else c end` | calcHourRealize, AbonInfos |
| `HOUR(x)` / `YEAR(x)` / `MONTH(x)` | `EXTRACT(... FROM x)` | calcHourRealize, calcRequests |
| `month('$d')` / `year('$d')` | PHP da hisoblanadi (`$submonth->month`) | OrgDebit |
| `DATE(x)` | `x::date` | calcBalance, calcHourRealize, ClickhouseService |
| `DATE_SUB(d, INTERVAL 1 DAY)` | `d::date - INTERVAL '1 day'` | calcBalance |
| `JSON_OBJECT(...)` | `json_build_object(...)` | ClickhouseService |
| `CONVERT(kod, UNSIGNED)` | `CASE WHEN kod ~ '^[0-9]+$' THEN kod::bigint ELSE 0 END` | CmsUsersTriggers |
| `information_schema.TRIGGERS` + `DATABASE()` | kichik harfli ustunlar + `current_schema()` | DbTriggersStatus |
| errno `1062` | SQLSTATE `23505` | FactorySignature |

**Istisno — `app/Services/DbTriggers/`**: trigger qatlami PG ga bog'lanmadi,
u **ikkala dialektda** ishlaydi. SQL ulanish driveriga qarab
`BaseTrigger::upsert()` / `insIgnore()` / `toUInt()` / `qi()` da quriladi,
trigger klasslarining o'zida dialektga bog'liq SQL yo'q. Sabab:
`db_triggers.connection` eski MySQL bazasiga (`mysql`, `mysql1`) ham
qaratilishi mumkin. `triggers:status` ham ikkala `information_schema`
ko'rinishini o'qiydi.

PG qat'iyroq bo'lgani uchun ikki joyda **haqiqiy xato ham tuzatildi**:

* `calcBalance::reportByDate` — bitta `FROM` da `re as r` va `regions as r`,
  ya'ni **ikki marta `r` aliasi**. Viloyat jadvali `rg` ga o'zgartirildi.
  Shuningdek `tb`/`tr`/`ta` CTE larida `group by o.id` → tanlangan ustunning
  o'zi bo'yicha (`i.id_to` / `f.id_from` / `t.id_to`).
* `AbonInfos` — `group by u.id_region, u.id_district`, lekin `d.id_region, d.id`
  tanlanardi; `group by d.id_region, d.id` ga o'zgartirildi. Shuningdek
  `u.is_nfc = 1` → `u.is_nfc = '1'` (ustun `varchar`).

Migratsiyalar asl fayl nomlari bilan ko'chirildi (`users`, `password_resets`,
`jobs`, `failed_jobs`) — L13 skeletining `password_reset_tokens` / `sessions` /
`cache` / `job_batches` variantlari **ishlatilmadi**.

---

## 4. Laravel 13 da sinadigan joylar va ularning tuzatilishi

Quyidagilar ko'chirishda **majburiy** o'zgargan yagona kod joylari.
Har biri tekshirib tasdiqlangan (skript bilan L13 da xato berishi ko'rsatilgan).

### 4.1 `DB::select(DB::raw(...))` → `DB::select("...")`

L10 dan `Illuminate\Database\Query\Expression` da `__toString()` YO'Q, va
`getValue()` endi `Grammar` talab qiladi. Ulanish darajasidagi
`select()` / `selectOne()` / `insert()` / `unprepared()` / `statement()`
metodlari **xom satr** kutadi.

```
TypeError: PDO::prepare(): Argument #1 ($query) must be of type string,
           Illuminate\Database\Query\Expression given
```

Tegilgan fayllar (faqat `DB::raw(` o'rami olib tashlandi, SQL matni **bir harf
ham o'zgarmadi**):

* `app/Actions/FindProvider.php` — `calcDebosit()`
* `app/Actions/IMoneyDebit.php` — `unprepared()` (i_money_orgs upsert)
* `app/Jobs/OrgDebit.php` — oxirgi oy depoziti
* `app/Console/Commands/AbonDepositCalc.php` — `select()` ×2, `insert()` ×1
* `app/Console/Commands/calcBalance.php` — `reportByDate()`
* `app/Console/Commands/calcDebit.php` — `select()` ×5
* `app/Console/Commands/calcRequests.php` — `select()` ×2
* `app/Console/Commands/calcTransanctions.php` — `select()` ×2, `unprepared()` ×2

> So'rov **quruvchisi** darajasidagi `->select(DB::raw('...'))`,
> `->where(DB::raw('...'), ...)`, `->orderBy()`, `->whereRaw()` chaqiruvlariga
> TEGILMADI — ular Expression ni to'g'ri qabul qiladi va o'zgarishsiz ishlaydi.

### 4.2 `BaseTrigger::brief()` — `Expression::getValue()`

`app/Services/DbTriggers/BaseTrigger.php` da log uchun Expression qiymati
o'qilardi:

```php
$v = (string) $v->getValue();          // ArgumentCountError (L13)
$v = (string) $v->getValue(DB::connection(self::connName())->getQueryGrammar());   // ✔
```

Bu `statBump()` orqali `DB::raw('qty + 1')` yozganda **har safar** ishlaydi
(log yoqiq bo'lsa), ya'ni tuzatilmasa PHP trigger'lari yoqilishi bilan sinardi.

### 4.3 `ClickhouseService::debit()` — `orderBy()` uch argument bilan

```php
->orderBy('d.id','d.sys_bid','d.dt_pay')   // L13: InvalidArgumentException
```

`orderBy($column, $direction)` — ikkinchi argument yo'nalish, uchinchisi
umuman yo'q. L5.5 da `'d.sys_bid'` "asc emas" deb hisoblanib **`d.id DESC`**
bo'lardi. Shu **haqiqiy natija** saqlandi:

```php
->orderBy('d.id', 'desc')
```

### 4.4 Blade `or` operatori

`resources/views/auth/passwords/reset.blade.php`:

```blade
{{ $email or old('email') }}    {{-- L5.5: isset() semantikasi; L13: "1" chiqadi --}}
{{ $email ?? old('email') }}    {{-- ✔ aynan o'sha semantika --}}
```

L13 da Blade buni `e($email or old('email'))` ga kompilyatsiya qiladi, ya'ni
mantiqiy `or` natijasi (`true`) ekranga chiqadi.

### 4.5 `IMoneyDebit::handle()` signaturasi

```php
public function handle($gasDebits = [], $dt_pay, ...)   // PHP 8: deprecated
public function handle($gasDebits, $dt_pay, ...)        // ✔
```

Majburiy parametrdan oldin standart qiymatli parametr — PHP 8.0 dan
deprecated. Barcha chaqiruvchilar `$gasDebits` ni doim beradi, shuning uchun
xatti-harakat o'zgarmadi, faqat deprecation ogohlantirishi yo'qoldi.

### 4.6 Kontroller-satr marshrutlari

```php
Route::get('/home', 'HomeController@index')->name('home');        // L8 dan yo'q
Route::get('/home', [HomeController::class, 'index'])->name('home');   // ✔
```

URL ham, route nomi ham o'zgarmadi.

---

## 5. Skelet fayllar — L13 ekvivalentiga o'tkazildi

| egaz-indexator (L5.5) | egaz-index13 (L13) |
|---|---|
| `app/Http/Kernel.php` | `bootstrap/app.php` → `withMiddleware()` |
| `app/Console/Kernel.php` | `bootstrap/app.php` (`withCommands()` avtomatik) |
| `app/Exceptions/Handler.php` | `bootstrap/app.php` → `withExceptions()` |
| `app/Providers/RouteServiceProvider.php` | `bootstrap/app.php` → `withRouting()` |
| `app/Http/Middleware/VerifyCsrfToken.php` | `validateCsrfTokens(except: [...])` |
| `app/Http/Middleware/TrimStrings.php` | `trimStrings(except: [...])` |
| `app/Http/Middleware/EncryptCookies.php` | (bo'sh edi — framework standarti) |
| `app/Http/Middleware/TrustProxies.php` | `fideloper/proxy` olib tashlandi; `$proxies = null` edi, ya'ni amalda no-op — L13 ning o'z `TrustProxies` i xuddi shunday |
| `config/app.php` → `providers[]` | `bootstrap/providers.php` |
| `database/seeds/` | `database/seeders/` (`Database\Seeders` fazosi) |
| `$factory->define(...)` | `Database\Factories\UserFactory` klassi |
| Migratsiya klasslari | anonim `return new class extends Migration` |

**Middleware guruhlari tekshirildi:**

```
web: EncryptCookies, AddQueuedCookiesToResponse, StartSession,
     ShareErrorsFromSession, PreventRequestForgery, SubstituteBindings
api: throttle:60,1, bindings          <- asl Kernel dagi bilan aynan bir xil
alias: guest => App\Http\Middleware\RedirectIfAuthenticated   (/home ga yuboradi)
```

`App\User` **`App\Models\User` ga KO'CHIRILMADI** — `RegisterController`,
`config/auth.php`, `config/services.php` dagi havolalar asl holicha qoldi.

`withExceptions()` da skeletdagi
`shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))` **olib tashlandi**:
integratsiya manzillari `api/` prefiksisiz va `Accept: application/json`
bilan keladi, shuning uchun L5.5 dagi `expectsJson()` xulqi saqlanishi kerak.

---

## 6. Config: eski env nomlari ham ishlaydi

Laravel 11+ ba'zi kalitlarni qayta nomlagan. Serverdagi mavjud `.env` faylni
**tahrirsiz** ko'chirish mumkin bo'lishi uchun config fayllarda ikkalasi ham
o'qiladi:

| Eski (L5.5) | Yangi (L13) | Fayl |
|---|---|---|
| `QUEUE_DRIVER` | `QUEUE_CONNECTION` | `config/queue.php` |
| `CACHE_DRIVER` | `CACHE_STORE` | `config/cache.php` |
| `BROADCAST_DRIVER` | `BROADCAST_CONNECTION` | `config/broadcasting.php` |
| `MAIL_DRIVER` | `MAIL_MAILER` | `config/mail.php` |
| `FILESYSTEM_DRIVER` | `FILESYSTEM_DISK` | `config/filesystems.php` |
| `APP_LOG_LEVEL` | `LOG_LEVEL` | `config/logging.php` |

Standart qiymatlar ham asl loyihanikiga tenglashtirildi:

* `app.timezone` = `Asia/Tashkent`
* `session.driver` = `file`, cookie nomi `laravel_session` (`_` bilan)
* `cache.default` = `file`, prefiks `laravel_cache`
* `queue.default` = `sync`
* `queue.failed.driver` = **`database`** (L13 standarti `database-uuids`;
  `failed_jobs` jadvalida `uuid` ustuni yo'q)
* `auth.passwords.users.table` = `password_resets`
* `filesystems.disks.local.root` = `storage_path('app')`
* `session.same_site` = `null`

---

## 7. `env()` va config keshi — DIQQAT

Servislar (`Scales`, `GnpCamera`, `FactorySignature`, `Levelmeters`, ...) va
`ClickHouseServiceProvider` login/parollarni **to'g'ridan-to'g'ri `env()`**
orqali o'qiydi — asl loyihadagidek qoldirildi.

> ⛔ **`php artisan config:cache` (yoki `optimize`) ni ISHLATMANG.**
> Config keshlangach `env()` `null` qaytaradi va HAMMA integratsiya
> autentifikatsiyasi buziladi. Bu cheklov asl `egaz-indexator` da ham bor edi.

`.env` da parolni **bo'sh qoldirmang** (`FACTORYSIGNATURE_PSW=`): `env()`
faqat `getenv() === false` bo'lgandagina koddagi default'ga tushadi, bo'sh
satr `''` bo'lib o'tadi.

---

## 8. Ataylab ko'chirilmagan / o'zgargan mayda narsalar

* **`resources/lang/en/*`** (4 ta fayl) — **ataylab ko'chirilmadi**; L13 ning
  to'liq ichki inglizcha tarjimalari ishlatiladi. Sabab: L5.5 dagi
  `validation.php` 2017 yilgi holat, undan keyin qo'shilgan validatsiya
  qoidalari uchun xabari yo'q (`passwords.php` da esa `throttled` kaliti yo'q) —
  ya'ni eski fayllarni ko'chirish xabar YO'QOLISHIGA olib kelardi.
  Farqlar faqat so'z tanlashda va faqat login/register ekranlarida ko'rinadi:

  | Kalit | L5.5 | L13 |
  |---|---|---|
  | `auth.failed` | These credentials do not match our records. | *(aynan bir xil)* |
  | `auth.throttle` | Too many login attempts... | *(aynan bir xil)* |
  | `pagination.previous/next` | `&laquo; Previous` / `Next &raquo;` | *(aynan bir xil)* |
  | `passwords.reset` | Your password has been reset**!** | Your password has been reset**.** |
  | `passwords.sent` | We have **e-mailed**... link**!** | We have **emailed**... link**.** |
  | `passwords.user` | ...that **e-mail** address. | ...that **email** address. |

  Asl matnlar kerak bo'lsa: `php artisan lang:publish` va shundan keyin
  `lang/en/passwords.php` ni qo'lda tahrirlang.
* **Front-end**: `webpack.mix.js`, `package.json` va `resources/assets/js/*`
  asl holicha ko'chirildi (Vite skeleti olib tashlandi). `public/css/app.css`
  va `public/js/app.js` — tayyor build natijalari — aynan ko'chirildi, chunki
  `layouts/app.blade.php` ularga `asset()` bilan murojaat qiladi.
  ⚠ `laravel-mix ^1.0` zamonaviy npm da o'rnatilmaydi — bu holat asl loyihada
  ham shunday, deploy da build bosqichi yo'q.
* **`routes/channels.php`** ko'chirildi, lekin asl loyihadagidek **yuklanmaydi**
  (u yerda `BroadcastServiceProvider` `config/app.php` da izohga olingan edi).
* **`public/uploads/`** va **`public/uploads/gnp-camera/`** papkalari yaratildi.
  `Scales::saveBase64ToFile()` / `GnpCamera::saveBase64ToFile()` nisbiy yo'lga
  yozadi; papka bo'lmasa `file_put_contents` `false` qaytaradi va rasm
  jimgina yo'qoladi.
* `.github/`, `CHANGELOG.md`, `.styleci.yml` — L13 skeletidan qoldi
  (yondosh `egaz-push` loyihasida ham shunday).
* **`database/sql/tb_factory_signature_logs.sql`** — bayt-ma-bayt ko'chirildi
  (jadval QO'LDA yaratiladi, migration yo'q — fayl sarlavhasidagi izohga qarang).
* **`tests/`** — L13 skeletining o'z testlari (mazmunan asl bilan bir xil:
  `GET / → 200` va `assertTrue`); `CreatesApplication` traiti L13 da kerak emas.
* **`.gitattributes` / `.gitignore` / `phpunit.xml` / `artisan` /
  `public/index.php`** — L13 skeletiniki (freymvork talabi; asl L5.5
  variantlari L13 bilan ishlamaydi yoki ma'nosiz).

---

## 9. Asl loyihadagi ma'lum nosozliklar — ATAYLAB tuzatilmadi

Ko'chirish "aynan nusxa" bo'lishi kerak edi, shuning uchun quyidagilar
**o'zgartirilmadi**. Ular `egaz-indexator` da ham xuddi shunday.

1. **`calcTransanctions.php:101`** — `RecalculationDate()` ichida
   `$tr['supplier']` deb yozilgan, holbuki `$tr` — `stdClass`:

   ```php
   if ($tr['supplier'] == 'hgt') {          // Error: Cannot use object of type stdClass as array
   ```

   Bu satr har bir qator uchun bajariladi, ya'ni `php artisan calcTransactions <sana>`
   birinchi qatordayoq yiqiladi. PHP 7 da ham, PHP 8 da ham bir xil fatal.
   `RecalculationDate2()` (`full` / `fullw` argumentlari) da bu xato yo'q.

2. **`Scales::pullRequest()`** — `try` blokidagi `return 1;` dan keyingi
   `ClickhouseService::scales(...)` va ikkinchi `return 1;` — o'lik kod.

3. **`UzGPS::authify()` / `GnpCamera::authify()`** muvaffaqiyatda `null`
   qaytaradi (`return 0` emas) — `if ($this->authify())` uchun natija bir xil.

4. **`AbonDepositCalc.php`** dagi `use App\Services\Deposit;` — mavjud bo'lmagan
   klass. Ishlatilmagani uchun autoload qilinmaydi.

5. **`calcRealizations::RealizationsAll()`** — ichki `foreach` da ma'lumot
   topilmasa `return` qiladi (`continue` emas), ya'ni qolgan kunlar
   qayta hisoblanmaydi.

6. **`TriggerFlags::enabled()` nuqtali kalitni o'qiy olmaydi.** Bayroq nomlari
   `'cms_users.setIDkodUser'` ko'rinishida NUQTALI, `enabled()` esa ularni
   `config('db_triggers.triggers.' . $name, false)` bilan o'qiydi. `Arr::get`
   nuqta bo'yicha ichkariga kirib boradi (`triggers` → `cms_users` → ...) va
   `'cms_users.setIDkodUser'` degan yaxlit kalitni topa olmaydi → **doim
   `false`**, hattoki env orqali `true` qilinsa ham. Ikkala freymvorkda ham
   empirik tasdiqlandi (L5.5.50 va L13.24 — ikkalasida `DEFAULT` qaytadi).
   Ya'ni PHP trigger bayroqlari asl loyihada ham amalda yoqib bo'lmas edi —
   port bu xulqni aynan saqladi. Tuzatish (kerak bo'lganda, ikkala tomonni
   birga): `enabled()` ichida `config('db_triggers.triggers', [])[$name] ?? false`
   ishlatish.

Bularni tuzatish kerak bo'lsa — alohida ish sifatida, ushbu port
tugagandan keyin qiling: shunda "port sindirdimi yoki avvaldan shundaymidi"
degan savol tug'ilmaydi.

---

## 10. Ishga tushirish

```bash
composer install                 # PHP 8.4 kerak
cp .env.example .env             # yoki serverdagi mavjud .env ni ko'chiring
php artisan key:generate         # APP_KEY bo'sh bo'lsa

# Bazalar allaqachon mavjud bo'lsa migratsiya kerak emas; toza baza uchun:
php artisan migrate

php artisan route:list           # URL lar joyidami
php artisan triggers:status      # DB triggerlari ⇄ PHP bayroqlari
php artisan pg:check             # PostgreSQL (egaz-push) ulanishi
```

Web-server document root — `public/`. Ildizdagi `.htaccess` (asl loyihadan
ko'chirilgan) hamma so'rovni `public/` ga yo'naltiradi, ya'ni document root
loyiha ildizida bo'lsa ham ishlaydi.

Navbat ishchisi (agar `QUEUE_DRIVER=database` bo'lsa):

```bash
php artisan queue:work
```

`factory_signature:normalize` uchun OS cron (Laravel scheduler bu loyihada
ishlatilmaydi — `Console\Kernel::schedule()` bo'sh edi):

```
*/5 * * * * cd /var/www/egaz-index13 && php artisan factory_signature:normalize >> storage/logs/fs_norm.log 2>&1
```
