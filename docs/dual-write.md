# Ikki bazaga yozish (dual write): MySQL → PostgreSQL

**Maqsad:** ilova `egaz-indexator` dagidek **MySQL ga yozadi**, va darhol
keyin **aynan shu ma'lumotni PostgreSQL nusxasiga** ko'chiradi — nusxaga
`id` va `created_at` ham qo'shiladi.

```
                       ┌──────────────────────────────┐
   POST / artisan  ──► │  ilova (egaz-index13)        │
                       └───────┬──────────────┬───────┘
                               │ 1. YOZISH    │ 2. NUSXA (yozishdan keyin)
                               ▼              ▼
                       mysql  (egaz_idxdb)   pgsql  (egaz_idxpost)
                       mysql1 (brrgz)        pgsql1 (egaz_push)
```

MySQL — **birlamchi manba (source of truth)**. PostgreSQL — nusxa.

**O'qish o'zgarmadi:** manba jadvallar (cms_users, organizations,
tb_gas_debit, tb_requests\*, tb_amending …) oldingidek `pgsql1` dan
o'qiladi. Loyihaning o'z jadvallari (`i_*`, `idx_*`, `tb_*`) esa standart
ulanishdan, ya'ni MySQL dan — chunki yozish ham o'sha yerga ketadi.

Sozlamalar: [`config/dual_write.php`](../config/dual_write.php)

---

## 1. Qanday ulangan — chaqiruv joylari tegilmagan

`App\Providers\DualWriteServiceProvider` `mysql` **drayveri** uchun ulanish
klassini almashtiradi:

```
Connection::resolverFor('mysql', …)
    └── App\Database\DualWrite\MySqlConnection
            └── query()  →  App\Database\DualWrite\Builder
                                └── insert / insertGetId / insertOrIgnore
                                    update / upsert / incrementEach
                                    delete / truncate
                                        └── App\Services\DualWrite\Mirror
```

Ya'ni ilovadagi oddiy

```php
DB::table('i_real_details')->insert($row);
```

hech qanday o'zgarishsiz ikkala bazaga yozadi. `app/` ichidagi 40+ yozish
joyi **o'zgartirilmadi**.

Nusxa amali **asosiy amaldan keyin** va **faqat u muvaffaqiyatli bo'lganda**
bajariladi. O'qish (`select`) teginilmaydi.

### Nusxaga nima qo'shiladi

| Ustun | Qiymat |
|---|---|
| `id` | MySQL bergan AUTO_INCREMENT (ya'ni **ikki bazada id AYNAN bir xil**). MySQL da `id` bo'lmasa — PG dagi serial o'zi beradi; u ham bo'lmasa `MAX(id)+1`. |
| `created_at` | nusxa yozilgan payt (`Y-m-d H:i:s`), agar qatorda allaqachon bo'lmasa |

Nusxa jadvalda **bo'lmagan ustunlar tashlab yuboriladi** (log: `DUALW … nusxada
bunday ustun(lar) yo'q`), ya'ni sxemalar 1:1 bo'lishi shart emas.

---

## 2. Tranzaksiya

Nusxa boshqa bazada, ya'ni asosiy tranzaksiyaga kira olmaydi. Shuning uchun:

* asosiy amal **tranzaksiya ichida** bo'lsa — nusxa **COMMIT dan keyin**
  yoziladi (`Connection::afterCommit`);
* **ROLLBACK** bo'lsa — nusxa **umuman yozilmaydi**.

Ya'ni PostgreSQL da MySQL da yo'q qator paydo bo'lmaydi. (Sinovda
tekshirilgan: `beginTransaction` → `insert` → `rollBack` da PG bo'sh qoladi.)

---

## 3. Triggerlar — har bir bazada AYNAN bir marta

`egaz_idxdb` (MySQL) da DB triggerlari **bor**, PostgreSQL nusxasida **yo'q**.
Shuning uchun:

| Ulanish | Trigger mantig'ini kim bajaradi |
|---|---|
| `mysql`, `mysql1` | **bazaning o'z triggerlari** (PHP tomoni o'chiq) |
| `pgsql`, `pgsql1` | **PHP versiyalari** (`App\Services\DbTriggers`) |

Buni `config/db_triggers.php` → **`php_connections`** belgilaydi. Bayroqlar
(`triggers` ro'yxati) o'zgarmadi — ular yoqiq, lekin faqat ro'yxatdagi
ulanishlarda ishlaydi.

Nusxa yozilayotganda tartib MySQL dagi `FOR EACH ROW` bilan bir xil:

```
BEFORE INSERT (PHP, pgsql) → INSERT → AFTER INSERT (PHP, pgsql)
```

Natijada PG dagi agregatlar (`i_abonent_orgs`, `i_deposit_orgs`,
`i_real_orgs`, `organizations.deposit` …) MySQL dagi DB triggerlari qilgan
ishni takrorlaydi.

Tekshirish:

```bash
php artisan triggers:status --all
```

Har bir ulanish uchun "Muammo topilmadi" bo'lishi kerak. Kutilgan manzara:

```
mysql  → "bazada (asl holat)"      (PHP o'chiq)
pgsql  → "PHP da (ko'chirilgan)"   (bazada trigger yo'q)
```

> ⚠ PostgreSQL da shu nomdagi trigger YARATILSA — o'sha ulanishni
> `php_connections` dan chiqaring, aks holda amal ikki marta bajariladi.

---

## 4. Xom SQL — `CounterUpsert`

`DB::unprepared()` / `DB::insert()` **so'rov quruvchisidan o'tmaydi**, ya'ni
avtomatik nusxalanmaydi. Ilovada xom SQL bilan yozadigan joylar faqat
hisoblagichli upsert lar edi; ular
`App\Services\DualWrite\CounterUpsert` ga o'tkazildi:

| Joy | Jadval |
|---|---|
| `app/Actions/IMoneyDebit.php` | `i_money_orgs` |
| `app/Console/Commands/calcTransanctions.php` (2 joy) | `idx_dayli_by_orgs` |

```php
CounterUpsert::run('idx_dayli_by_orgs', [
    'dt' => $dt, 'yy' => $yy, 'mm' => $mm,
    'id_region' => $org->id_region, 'id_org' => $org->id,
    "amount_$p" => $amount, "qty_$p" => 1,
], ['dt', 'yy', 'id_org'], ["amount_$p", "qty_$p"]);
//   ↑ konflikt ustunlari (PG)      ↑ konfliktda O'SADIGAN ustunlar
```

SQL har bir dialekt uchun alohida quriladi:

```sql
-- MySQL
insert into idx_dayli_by_orgs (…) values (…)
on duplicate key update amount_click = idx_dayli_by_orgs.amount_click + ?, …

-- PostgreSQL
insert into idx_dayli_by_orgs (…) values (…)
on conflict (dt, yy, id_org) do update set amount_click = idx_dayli_by_orgs.amount_click + ?, …
```

Qiymatlar endi **bog'lanadi** (oldin satrga qo'shilardi).

> Yangi xom SQL yozish kerak bo'lsa — yoki `CounterUpsert` dan foydalaning,
> yoki so'rov quruvchisi bilan yozing. Aks holda nusxa olinmaydi.

**Nusxalanmaydigan boshqa amallar:** `INSERT … SELECT` (`insertUsing`) —
tanlov asosiy bazada bajariladi, nusxada natija boshqa bo'lishi mumkin;
kerak bo'lsa `php artisan pg:sync` ishlatiladi.

---

## 5. Holatni tekshirish

```bash
php artisan dual:status                  # juftliklar, ulanishlar, jadvallar
php artisan dual:status --issues         # faqat muammolilar
php artisan dual:status --fix-sequences  # PG serial larni MAX(id) ga tenglash
```

Chiqishdagi ustunlar:

| Ustun | Ma'nosi |
|---|---|
| `Asosiyda` | jadval MySQL da bormi |
| `Nusxa ustunlari` | PG dagi ustunlar soni (`-` → jadval yo'q, nusxa olinmaydi) |
| `id` | `serial` / `bor` / `MAX()+1` (qiymatni ilova hisoblaydi) |
| `created_at` | PG da bu ustun bormi |

### Nega `--fix-sequences` kerak

Nusxaga `id` MySQL dan ko'chiriladi, ya'ni PG dagi **sequence o'smaydi**.
Agar shu jadvalga boshqa joydan (qo'lda, `pg:sync`) `id` siz yozilsa,
sequence eski qiymatdan boshlab **dublikat id** berishi mumkin. Komanda
sequence ni `MAX(id)` ga keltirib qo'yadi.

---

## 6. Xatolar va log

* **`fail_open` (standart `true`)** — nusxa yozishdagi xato **yutiladi**
  (`Log::error`), asosiy (MySQL) amal buzilmaydi. PostgreSQL o'chib qolsa
  ilova ishlashda davom etadi.
* **`max_failures` (standart 20)** — ketma-ket shuncha xatodan keyin nusxa
  olish **shu process uchun to'xtatiladi** (log to'lib ketmasligi va har bir
  yozish sekinlashmasligi uchun).
* **`log`** (standart `true`) — har bir nusxa amali `DUALW` prefiksi bilan
  `storage/logs/laravel.log` ga yoziladi:

```
[…] local.DEBUG: DUALW [i_real_details] INSERT nusxa {dt=2026-08-17, id=91, created_at=…}
[…] local.WARNING: DUALW [i_money_details] nusxada bunday ustun(lar) yo`q, tashlab ketildi: payer_branch
[…] local.ERROR: DUALW [i_real_orgs] insert nusxasi YOZILMADI: SQLSTATE[…]
```

> Yuklama katta bo'lsa `DUAL_WRITE_LOG=false` qiling — xato loglari (`ERROR`)
> baribir yoziladi.

---

## 7. Bayroqlar (`.env`)

| Kalit | Standart | Ma'nosi |
|---|---|---|
| `DUAL_WRITE` | `true` | `false` → **faqat MySQL** (egaz-indexator xatti-harakati) |
| `DUAL_WRITE_MIRROR` | `pgsql` | `mysql` ulanishining nusxasi |
| `DUAL_WRITE_MIRROR1` | `pgsql1` | `mysql1` ulanishining nusxasi |
| `DUAL_WRITE_DRY_RUN` | `false` | nusxa bazaga yozmaydi, faqat logga chiqadi |
| `DUAL_WRITE_FAIL_OPEN` | `true` | nusxa xatosi asosiy amalni buzmaydi |
| `DUAL_WRITE_TRIGGERS` | `true` | nusxada PHP triggerlari ishlaydi |
| `DUAL_WRITE_COPY_ID` | `true` | MySQL bergan id nusxaga ko'chiriladi |
| `DUAL_WRITE_BULK_IDS` | `false` | bulk INSERT da id ko'chirilmaydi (MySQL ketma-ketlikni kafolatlamaydi) |
| `DUAL_WRITE_GENERATE_ID` | `true` | PG da `id` NOT NULL va serial emas bo'lsa `MAX(id)+1` |
| `DUAL_WRITE_MAX_FAILURES` | `20` | ketma-ket xatolar chegarasi |

`only_tables` / `skip_tables` — jadval filtri (`config/dual_write.php`).
`skip_tables` da framework jadvallari (`migrations`, `jobs`, `sessions`, …).

---

## 8. Prod'ga chiqarishdan oldin

1. **`.env`**
   * `DB_CONNECTION=mysql`, `DB_*` → `egaz_idxdb`, `DB_*1` → `brrgz`
   * `PGIDX_*` → **`egaz_idxpost`** (prod'da `192.168.0.6`), `PGPUSH_*` → `egaz_push`
2. **`php artisan dual:status`** — har bir jadval nusxa bazada bo'lishi kerak.
   "nusxada YO'Q" degan jadval bo'lsa, u jadval PG da yaratilmagan (yoki
   `PGIDX_DATABASE` xato bazaga qaratilgan) — o'sha jadvalga nusxa olinmaydi.
3. **`php artisan triggers:status --all`** — to'rt ulanishda ham "Muammo
   topilmadi".
4. **Sinov yozuvi** — bitta integratsiya so'rovi yuborib, `laravel.log` dagi
   `DUALW` satrlarini va PG dagi qatorni tekshiring.
5. `pg:sync` bilan **eski ma'lumotni** ko'chirib bo'lgach,
   `dual:status --fix-sequences` ni bir marta yugurtirib qo'ying.

---

## 9. Ma'lum cheklovlar

* **Atomiklik yo'q.** Ikki baza, ikki tranzaksiya: MySQL commit bo'lib,
  PG yozilmasligi mumkin (masalan PG o'chgan). Bunda `DUALW … YOZILMADI`
  logi qoladi va o'sha qator PG da yo'q bo'ladi — keyin `pg:sync` bilan
  to'ldiriladi. `fail_open=false` qilsangiz xato yuqoriga otiladi, lekin
  MySQL dagi yozuv **baribir qolgan** bo'ladi (u allaqachon commit bo'lgan).
* **`MAX(id)+1` poygaga moyil.** PG tomonda `id` ni serial/identity qilish
  tavsiya etiladi (yoki MySQL da AUTO_INCREMENT bo'lsin — u holda id
  ko'chiriladi).
* **Bulk INSERT da id ko'chirilmaydi** (`DUAL_WRITE_BULK_IDS=false`).
  Ilovadagi yozishlar amalda bir qatorli, shuning uchun bu kamdan-kam tegadi.
* **Xom SQL** (`DB::unprepared`, `DB::insert`, `DB::statement`) ushlanmaydi —
  §4 ga qarang.
* `mariadb` drayveri ro'yxatga olinmagan (loyihada MySQL ishlatiladi).

---

## 10. Sinov qanday o'tkazilgan

Vaqtinchalik `zz_dual_test` jadvali ikkala bazada yaratilib, quyidagilar
tekshirildi (hammasi ✔):

`INSERT` (id + created_at nusxaga qo'shilishi, nusxada yo'q ustun tashlanishi),
`insertGetId`, `UPDATE` (shart nusxada ham aynan qo'llanishi), `INCREMENT` /
`DECREMENT` (xom ifoda dialektga mos qurilishi), tranzaksiya **rollback**
(nusxaga yozilmasligi) va **commit** (commit dan keyin yozilishi), `DELETE`,
`CounterUpsert` (ikki dialektda hisoblagich o'sishi), `TRUNCATE`,
`DUAL_WRITE=false` (nusxa olinmasligi).

Alohida: `tb_scales_logs` ga `org_id=1` yozilganda MySQL da **DB triggeri**,
PG nusxasida **PHP triggeri** uni 13 ga o'girdi, id ikkalasida bir xil bo'ldi.
