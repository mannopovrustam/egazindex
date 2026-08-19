# egaz-index13

**Egaz Indexator** — integratsiya, indeksatsiya va analitika xizmatining
Laravel 13 dagi versiyasi. `egaz-indexator` (Laravel 5.5) loyihasining
tashqi xulq-atvori o'zgarmagan porti.

* Loyiha mazmuni: [`docs/project_info.md`](docs/project_info.md)
* **Ko'chirish hisoboti (nima o'zgardi, nima o'zgarmadi):**
  [`docs/port-l55-to-l13.md`](docs/port-l55-to-l13.md)
* DB triggerlarini PHP ga o'tkazish:
  [`docs/db-triggers-to-service-migration.md`](docs/db-triggers-to-service-migration.md)
* **Ikki bazaga yozish (MySQL → PostgreSQL nusxa):**
  [`docs/dual-write.md`](docs/dual-write.md)

## Muhim

* **URL lar, artisan komandalar, DB ulanish nomlari va javob formatlari
  `egaz-indexator` dagi bilan AYNAN bir xil.** Tashqi tizimlar (1C, tarozi,
  GNP kamera, dispenserlar, UzGPS, ijtimoiy soha, sath o'lchagichlar) hech
  narsani o'zgartirmasdan ulanaveradi.
* ⛔ **`php artisan config:cache` / `optimize` ni ISHLATMANG.** Servislar
  login/parollarni bevosita `env()` dan o'qiydi; config keshlangach ular
  `null` bo'lib qoladi va integratsiya autentifikatsiyasi buziladi.
* Yangi POST manzil qo'shsangiz, uni `bootstrap/app.php` dagi
  `validateCsrfTokens(except: [...])` ro'yxatiga ham qo'shing.
* **Ilova IKKI BAZAGA yozadi:** asosiysi MySQL (`egaz_idxdb` / `brrgz` —
  `egaz-indexator` dagidek), keyin aynan shu qator PostgreSQL nusxasiga
  (`egaz_idxpost` / `egaz_push`) `id` va `created_at` bilan yoziladi.
  Manba jadvallar (cms_users, organizations, tb_gas_debit) `pgsql1` dan
  o'qiladi. Holatni `php artisan dual:status` ko'rsatadi.
  Xom SQL bilan yozish nusxalanmaydi — `App\Services\DualWrite\CounterUpsert`
  yoki so'rov quruvchisidan foydalaning ([`docs/dual-write.md`](docs/dual-write.md)).

## Talablar

* PHP **8.4+** (`pdo_mysql`, `pdo_pgsql`, `mbstring`, `curl`)
* MySQL (egaz_idxdb + brrgz), PostgreSQL (egaz-push), ClickHouse (ixtiyoriy)

## O'rnatish

```bash
composer install
cp .env.example .env      # yoki serverdagi mavjud .env ni ko'chiring
php artisan key:generate  # APP_KEY bo'sh bo'lsa
php artisan migrate       # faqat toza bazada
```

Web-server document root — `public/`.

## Foydali komandalar

```bash
php artisan route:list       # barcha URL lar
php artisan triggers:status  # DB triggerlari ⇄ PHP bayroqlari holati
php artisan pg:check         # egaz-push PostgreSQL ulanishini tekshirish
php artisan pg:sync --dry-run
```
