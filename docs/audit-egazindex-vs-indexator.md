# AUDIT: egaz-indexator (L5.5) ↔ egazindex (L13) — to'liq solishtiruv va dual-write tekshiruvi

Sana: 2026-08-28. Qamrov: **barcha** app/routes/config fayllari (fayl-ma-fayl diff),
DualWrite qatlami to'liq o'qildi, `dual:status` real muhitda ishga tushirildi,
lokal MySQL/PostgreSQL/Apache/PHP muhiti tekshirildi.

---

## 1. Qisqa xulosa (TL;DR)

| Savol | Javob |
|---|---|
| URL lar bir xilmi? | ✅ Ha, 100% — barcha 14 POST endpoint va CSRF-istisnolar aynan |
| Job lar bir xilmi? | ✅ Ha (Transaction, Realizations, OrgDebit) — faqat manba o'qish `mysql1`→`pgsql1` |
| Service lar bir xilmi? | ✅ Ha — 2 ta ataylab yaxshilash bor (Scales, FactorySignature), xulq buzilmagan |
| Repository/DB qatlami? | ✅ Yozish o'zgarmagan (MySQL asosiy), o'qish manbasi `pgsql1` ga o'tgan |
| MySQL dan keyin PG ga yozadimi? | ✅ Ha — har bir INSERT/UPDATE/DELETE/TRUNCATE/UPSERT/INCREMENT avtomatik nusxalanadi |
| PG ga yoza olmasa nima bo'ladi? | MySQL amali **buzilmaydi** (fail_open), xato logga (`DUALW`), 20 ketma-ket xatodan keyin shu process uchun nusxa to'xtaydi; keyin `pg:sync` bilan to'ldiriladi |
| Hozir ishga tushsa bo'ladimi? | ⛔ **Yo'q** — lokal `.env` dagi 3 ta ulanish ishlamayapti (P-1) va CLI PHP 8.2 (P-2). Tuzatish 10 daqiqalik ish. |

---

## 2. URL (marshrut) pariteti — ✅ to'liq mos

`routes/web.php`, `routes/api.php` — funksional jihatdan **bayt-ma-bayt bir xil**
(farqlar faqat izohlar va `use` satrlari). Tashqi tizimlar ishlatadigan barcha manzillar:

```
POST /datatransactions      POST /datarealizations      POST /uzgps/{ver}
POST /scales/info           POST /scales/photo          POST /gnp-camera
POST /factory-invoice       POST /factory-signature     POST /dispensers
POST /engraving             POST /social-sphere         POST /levelmeters
GET  /  /home  /test-bid/{id}  + Auth::routes()
```

- CSRF-istisnolar ro'yxati (`VerifyCsrfToken::$except` ↔ `bootstrap/app.php
  validateCsrfTokens`) — **aynan bir xil** (o'zim solishtirdim).
- `api` guruhi (`throttle:60,1` + `bindings`), `guest` alias, TrimStrings
  istisnolari — L5.5 dagining ekvivalenti sifatida `bootstrap/app.php` da qayta tiklangan.
- `health: /up` va `channels:` **ataylab** qo'shilmagan (eski loyihada yo'q edi) — to'g'ri qaror.

## 3. Job pariteti — ✅ mos

| Job | Holat |
|---|---|
| `Realizations` | Bayt-ma-bayt bir xil |
| `Transaction` | Faol kod bir xil (`IMoneyDebit::handle`); farqlar faqat **izohga olingan o'lik kodda** (u yerda `ON DUPLICATE KEY` → `ON CONFLICT` ga ko'chirilgan, lekin bu kod ikkala loyihada ham ishlamaydi) |
| `OrgDebit` | Bir xil mantiq; `DB::select(DB::raw(...))` → parametrli xom satr (L13 talabi), `month()/year()` PHP da hisoblanadi — natija ayni |

Uchchala jobda `env('JOBS_DISABLE')` va `QUEUE=sync` (inline bajarilish) saqlangan.

## 4. Service / integratsiya pariteti — ✅ mos (2 ta ongli yaxshilash bilan)

Bayt-ma-bayt bir xil: `Engraving`, `GasDispenser`, `GnpCamera`, `Levelmeters`,
`UzGPS`, `SocialSphere`(mantiq), `FactoryInvoice`(mantiq).

Farqli, lekin xulqi saqlangan:

- **FactorySignature** — yozish oqimi aynan (asosiy yozish `mysql1`=brrgz da qoldi).
  Qo'shimcha: dublikat endi PG SQLSTATE `23505` ni ham taniydi (`isDuplicate()`),
  driver-xatosi (`errno=0`) endi "vaqtincha" deb to'g'ri tasniflanadi. ✔ yaxshilash.
- **Scales** — foto saqlashda papka yaratish/yozish huquqi tekshiruvi va xato logi
  qo'shilgan (git: "fix scales"). ✔ yaxshilash, javob formati o'zgarmagan.
- Barcha servislar parollarni eski loyihadagidek `env()` dan o'qiydi (P-3 ga qarang).

## 5. Repository/DB qatlami — asosiy ongli farq

Bu **portning maqsadli o'zgarishi** (siz brrgz ni PG ga ko'chirganingiz uchun):

| | egaz-indexator | egazindex |
|---|---|---|
| O'QISH (cms_users, tb_gas_debit, tb_requests…) | `mysql1` (brrgz MySQL) | **`pgsql1`** (brrgz_post PG) |
| YOZISH (i_*, idx_*, integration_logs…) | `mysql` (egaz_idxdb) | `mysql` (egaz_idxdb) — o'zgarmagan |
| YOZISH (tb_factory_signatures, tb_fc_invoices, tb_social_sphere) | `mysql1` (brrgz) | `mysql1` (brrgz) — o'zgarmagan |
| SQL dialekti o'qishda | MySQL (`IFNULL`, `JSON_OBJECT`, `if()`, `DATE()`) | PG (`COALESCE`, `json_build_object`, `CASE`, `::date`, `EXTRACT`) — barcha 15+ joyda to'g'ri tarjima qilinganini tekshirdim |

`calcBalance`, `calcRequests`, `AbonInfos`, `calcHourRealize`, `ClickhouseService`
dagi katta so'rovlar PG ga to'g'ri o'girilgan (GROUP BY qoidalari, alias to'qnashuvi
`r`→`rg` ham tuzatilgan). Indexator'dagi **eng oxirgi commit'lar ham ko'chirilgan**:
`fix pg sync table #2` (28-avg), `fix i_money_failed`, `fix trigger`, `real:ballons-mah` — hammasi egazindex da bor.

## 6. Dual-write: MySQL dan keyin PG ga yozish — QANDAY ishlaydi

**Ha, yozadi.** Mexanizm (kod bo'yicha tekshirilgan):

```
DB::table('i_real_details')->insert($row)
  └─ App\Database\DualWrite\Builder (mysql drayverining har bir ulanishi shuni oladi)
       1. MySQL ga yozadi (asosiy amal)
       2. FAQAT muvaffaqiyatda → Mirror ga uzatadi
            ├─ tranzaksiya ichida bo'lsa → COMMIT dan KEYIN (rollback'da umuman yozilmaydi)
            ├─ PG jadval ustunlariga moslanadi (yo'q ustun tashlanadi, id/created_at qo'shiladi)
            ├─ id = MySQL AUTO_INCREMENT qiymati (ikki bazada AYNAN bir xil id)
            └─ PG tomonda PHP triggerlari ishlaydi (i_*_orgs, organizations.deposit)
```

- Juftliklar: `mysql`(egaz_idxdb) → `pgsql`(egaz_idxpost); `mysql1`(brrgz) → `pgsql1`(brrgz_post).
- Qamrov: `insert`, `insertGetId`, `insertOrIgnore`, `update`, `upsert`,
  `increment/decrement`, `delete`, `truncate` — hammasi ushlanadi.
- Xom SQL (`DB::unprepared`/`DB::insert`) ushlanMAYDI — ilovadagi 3 shunday joy
  (`IMoneyDebit`→i_money_orgs, `calcTransanctions`→idx_dayli_by_orgs ×2)
  `CounterUpsert` ga o'tkazilgan: u MySQL uchun `ON DUPLICATE KEY`, PG uchun
  `ON CONFLICT` ni alohida quradi va nusxani o'zi yozadi. Boshqa xom yozuv qolmagan
  (skanerlab chiqdim; `AbonDepositCalc` dagi `tmp_db.*` — diagnostika, nusxa shart emas).
- Trigger dublikati yo'q: MySQL tomonda bazaning O'Z triggerlari, PG tomonda PHP
  versiyalari (`php_connections = [pgsql, pgsql1]`) — har bazada aynan bir marta.

## 7. PG ga YOZA OLMASA nima bo'ladi? (kod bo'yicha aniq javob)

1. **MySQL amali buziladimi? — YO'Q.** `fail_open=true` (standart): PG xatosi
   yutiladi, chaqiruvchi kod hech narsani sezmaydi, HTTP javoblar o'zgarmaydi.
2. **Xato qayd etiladimi? — HA.** `storage/logs/laravel.log` ga:
   `DUALW [jadval] insert nusxasi YOZILMADI: SQLSTATE...` (ERROR darajada, log
   bayrog'idan qat'i nazar doim yoziladi).
3. **20 ta KETMA-KET xato** (`max_failures=20`) → shu PHP process uchun nusxa olish
   butunlay **to'xtaydi** (log to'lmasligi va har yozish 30s timeout kutmasligi uchun).
   Bitta muvaffaqiyat hisoblagichni 0 ga qaytaradi. Web da har so'rov yangi process —
   ya'ni amalda har so'rov qayta urinadi; **uzun cron komandada esa** (masalan
   `calc:debit` to'liq qayta hisob) qolgan qismi PG ga yozilmay qoladi (P-6).
4. **Jadval PG da bo'lmasa** — xato emas: bir marta WARNING, nusxa shu jadval uchun o'tkazib yuboriladi.
5. **Ustun PG da bo'lmasa** — ustun jimgina tashlanadi (bir marta WARNING).
6. **Natija — ikki baza orasida farq**: MySQL da bor, PG da yo'q qatorlar.
   Tiklash yo'li: `php artisan pg:sync` (increment/sana/to'liq rejimlari) +
   `php artisan dual:status --fix-sequences`.
7. `fail_open=false` qilinsa xato yuqoriga otiladi, LEKIN MySQL yozuvi baribir
   qolgan bo'ladi (u allaqachon commit) — atomiklik baribir yo'q. Standart `true` to'g'ri tanlov.

**Xulosa:** arxitektura "MySQL — haqiqat manbai, PG — best-effort nusxa + pg:sync
bilan to'ldirish" deb to'g'ri qurilgan va kod hujjatiga (docs/dual-write.md) mos.

---

## 8. TOPILGAN MUAMMOLAR va YECHIMLARI

### 🔴 P-1. (BLOKER) Lokal muhitda 4 ulanishdan 4 tasi ishlamayapti

`php artisan dual:status` real natijasi (2026-08-28):

| Ulanish | Xato | Sabab | Yechim |
|---|---|---|---|
| `mysql` (egaz_idxdb) | `1045 Access denied root@localhost` | `.env` dagi `DB_PASSWORD=82esfds_WbI564654` — prod paroli, lokal MySQL niki emas | `.env` ga lokal root parolni yozing |
| `mysql1` (brrgz, 192.168.0.5) | `2002 timeout` | Prod server, lokal tarmoqdan ko'rinmaydi | Lokalda `DB_HOST1=localhost` (lokal brrgz nusxasi) yoki VPN |
| `pgsql` (egaz_idxpost) | parol xatosi (`postgres` scram) | `PGIDX_PASSWORD=post_WbI564654A` lokal PG 17 paroliga mos emas | Lokal postgres parolini yozing |
| `pgsql1` (brrgz_post) | **`server does not support SSL, but SSL was required`** | `.env` da `PGPUSH_SSLMODE=require`, lokal PG 17 da `ssl=off` (postgresql.conf da tekshirdim) | Lokal uchun `PGPUSH_SSLMODE=prefer` (prod'da require qolsin) |

> Diqqat: `dual:status` chiqishida `pgsql` xatosi **ko'rinmay qolgan edi** — PG
> xato matni kirillcha (Windows-1251) kelib, konsol satrni yutgan. Ulanishni
> alohida PDO bilan tekshirganda parol xatosi tasdiqlandi. Xatolarga ishonchli
> diagnostika uchun PG serverda `lc_messages='C'` yoki probe xabarini kodlashda
> tozalash foydali (kichik yaxshilash).

### 🔴 P-2. (BLOKER) CLI PHP 8.2, loyiha esa PHP ≥ 8.4.1 talab qiladi

`php artisan ...` hozir `Composer detected issues... You are running 8.2.29` bilan yiqiladi.
Apache **allaqachon PHP 8.4.15 da** (httpd.conf tekshirildi) — web qismga ta'sir yo'q.

**Yechim:** cron/qo'lda ishga tushirishda to'liq yo'l:
`C:\wamp64\bin\php\php8.4.15\php artisan ...` (yoki PATH da 8.4 ni birinchi qiling).
Barcha scheduled komandalar (calc:*, pg:sync, *2clickhouse) shu bilan ishga tushirilsin.

### 🔴 P-3. (BLOKER-siz, lekin jiddiy) `config:cache` / `optimize` TAQIQLANGAN

Barcha integratsiya servislari parolni bevosita `env()` dan o'qiydi
(`FACTORYSIGNATURE_PSW`, `CAMERA_PSW`, `SCALES_PSW`… va `JOBS_DISABLE`).
`php artisan config:cache` qilinsa `env()` **null** qaytaradi → **hamma integratsiya
authi yiqiladi** (1C, tarozi, kamera 401/1 oladi). Bu eski loyihadan meros va
docs/port-l55-to-l13.md da qayd etilgan.

**Yechim:** prod deploy skriptida `config:cache`/`optimize` ishlatmang. (Istasangiz
keyinroq env() larni `config/services.php` ga ko'chirish — alohida vazifa.)

### 🟠 P-4. PG da UNIQUE cheklovlar bo'lmasa `ON CONFLICT` ISHLAMAYDI

`CounterUpsert` va `Builder::upsert` PG tomonda `ON CONFLICT (dt, yy, id_org)`
quradi. PostgreSQL bunda **aynan shu ustunlarda unique indeks/PK bo'lishini talab
qiladi**, aks holda `there is no unique or exclusion constraint matching the ON
CONFLICT specification` xatosi bilan nusxa yozilmaydi (fail_open uni yutadi —
ya'ni **jimgina** PG agregatlari yurmay qoladi).

MySQL→PG portida **17 ta UNIQUE cheklov yo'qolgani** avval aniqlangan edi
(jumladan `tb_gas_debit(sys_bid,dt_pay)` — to'lov idempotentligi!). Tayyor fayllar bor:
`egaz-main/egaz-push/database/sql/egaz_idxpost_indexes.sql` va `brrgz_post_indexes.sql`.

**Yechim (majburiy, ishga tushirishdan oldin):**
1. Dublikat-tekshiruv bo'limini bajarib, so'ng indeks fayllarini qo'llang
   (`CREATE INDEX CONCURRENTLY`, tranzaksiyasiz).
2. Ayniqsa tekshiring: `i_money_orgs (dt,yy,id_mah,id_org)`,
   `idx_dayli_by_orgs (dt,yy,id_org)`, `i_abonent_orgs (id_org,id_mah)`,
   `i_deposit_orgs (mm,yy,id_org,id_mah)`, `i_real_orgs (dt,yy,id_org,id_mah)` — PK/unique bo'lsin.
3. Sinov: bitta test to'lov yuborib `laravel.log` da `DUALW ... YOZILMADI` yo'qligini ko'ring.

### 🟠 P-5. `.env` da integratsiya parollari yo'q — koddagi standart parollar kuchda

egazindex `.env` da `FACTORYSIGNATURE_PSW`, `CAMERA_PSW`, `SCALES_PSW`,
`DISPENSER_PSW`… **yo'q** → kodga yozilgan default parollar ishlaydi (ular git
tarixida ochiq!). Prod'dagi eski indexator `.env` ida override bo'lsa, yangi
serverga **albatta ko'chiring**, aks holda tashqi tizimlar eski parol bilan kira
olmaydi (yoki aksincha — hamma default parolni biladi).

**Yechim:** prod `.env` dagi barcha `*_LOGIN`/`*_PSW` kalitlarni egazindex `.env`
ga ko'chirish; imkoni bo'lsa parollarni yangilash.

### 🟡 P-6. Uzun cron komandada 20-xato halt → PG nusxasi chala qoladi

PG qisqa muddat o'chib qolsa, ishlab turgan `calc:debit --full` kabi komandaning
qolgan qatorlari PG ga yozilmaydi (process qayta ishga tushguncha halt saqlanadi)
va bu faqat logdan ko'rinadi.

**Yechim:** katta qayta-hisoblardan keyin odat sifatida `dual:status` ni tekshirish;
kerak bo'lsa o'sha kun uchun `pg:sync <jadval> --days=1`. Monitoring bo'lsa,
`DUALW` ERROR satrlariga alert qo'ying. (Xohlasangiz `DUAL_WRITE_MAX_FAILURES=0`
— cheksiz urinish, lekin PG uzoq o'chsa har yozish 30s timeout kutadi — tavsiya etilmaydi.)

### 🟡 P-7. Ko'p qatorli (bulk) INSERT larda id pariteti yo'q

`bulk_ids=false` (to'g'ri qaror — MySQL bulk'da id ketma-ketligini kafolatlamaydi):
`calcHourRealize`, `RealBallonsMahalla` kabi joylarda PG o'z serial id sini oladi.
Bu jadvallar id bo'yicha o'zaro bog'lanmaydi — amaliy xavf past, lekin "id lar
aynan bir xil" degan kutish **faqat bitta-qatorli** insertlarga tegishli ekanini yodda tuting.
Shu sababdan `pg:sync` dan keyin `dual:status --fix-sequences` majburiy (sequence orqada qolmasin).

### 🟡 P-8. Apache vhost yo'q

`httpd-vhosts.conf` da egazindex uchun vhost yo'q; mavjud `egaz.local` esa
mavjud bo'lmagan `egaz-main/egazl13/public` ga qaratilgan (eskirgan yozuv).

**Yechim:** yangi vhost: `DocumentRoot "${INSTALL_DIR}/www/egaz-main/egazindex/public"`
(masalan `egazindex.local`), so'ng tashqi tizimlar prod'da eski indexator URL iga
qaragan host nomini shu serverga ko'chirganda URL lar aynan ishlaydi.

### ⚪ P-9. Mayda nomuvofiqliklar (xavfsiz, bilib qo'yish uchun)

1. `config/database.php` dagi izoh "pg:sync standart `pgsql1` ga yozadi" deydi,
   kod esa standart `--target=pgsql` (egaz_idxpost). **Kod to'g'ri**, izoh eskirgan.
2. `FactorySignature` catch-log matni "pgsql1 write" deydi, aslida xato `mysql1`
   (asosiy) yozuvdan — kosmetik chalg'itish.
3. `pg:sync` standart `--chunk` 5000→500 ga tushirilgan (xotira uchun xavfsizroq, sekinroq) — ongli farq.
4. `calcRealizations` da `$data = collect()` initsializatsiyasi qo'shilgan —
   eski koddagi potensial "undefined variable" tuzatilgan. ✔
5. Scheduler ikkala loyihada ham bo'sh — komandalar tashqi cron dan chaqiriladi.
   Cron ro'yxatini ko'chirishda PHP 8.4 yo'lini yozishni unutmang (P-2).

---

## 9. Ishga tushirish tartibi (tavsiya)

```bash
# 0) .env tuzatish (P-1, P-5): DB parollari, PGPUSH_SSLMODE=prefer (lokal),
#    integratsiya *_LOGIN/*_PSW lari prod .env dan

# 1) PG cheklov/indekslarni qo'llash (P-4)
#    egaz-push/database/sql/egaz_idxpost_indexes.sql  (+ brrgz_post_indexes.sql)

# 2) Tekshiruv (hammasi PHP 8.4 bilan!)
C:\wamp64\bin\php\php8.4.15\php artisan dual:status          # har jadval "nusxada bor"
C:\wamp64\bin\php\php8.4.15\php artisan triggers:status --all # 4 ulanishda "Muammo topilmadi"
C:\wamp64\bin\php\php8.4.15\php artisan pg:check

# 3) Eski ma'lumot to'ldirilgach
C:\wamp64\bin\php\php8.4.15\php artisan dual:status --fix-sequences

# 4) Sinov: bitta POST /datatransactions yuborib laravel.log dagi DUALW
#    satrlarini va PG dagi qatorni ko'rish. Prod'da DUAL_WRITE_LOG=false qiling.

# 5) Avariya rejasi: DUAL_WRITE=false → ilova AYNAN egaz-indexator kabi
#    faqat MySQL bilan ishlaydi (bir kalit bilan orqaga qaytish bor).
```

## 10. Yakuniy baho

Port **juda sifatli bajarilgan**: 139 fayldan funksional farqlar faqat (a) L13
majburiy sintaksisi, (b) `mysql1`→`pgsql1` o'qish manbasi va dialekt tarjimasi,
(c) DualWrite qatlami. Indexator'ning bugungi (28-avg) commit'larigacha ko'chirilgan.
Dual-write dizayni to'g'ri (afterCommit, rekursiya qalqoni, trigger dublikatsiz,
fail-open + pg:sync tiklash). Ishga tushirishga to'siq — kod emas, **muhit**:
P-1 (.env ulanishlari), P-2 (PHP 8.4 CLI), P-4 (PG UNIQUE lar) hal qilinishi shart.
