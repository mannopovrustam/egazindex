# egaz_idxdb MySQL triggerlarini PHP servislarga ko'chirish

> ## ⚙ YANGILANDI: ilova PostgreSQL ga o'tdi
>
> Quyidagi hujjat MySQL davridagi holatni tasvirlaydi. Hozirgi holat:
>
> * Ulanishlar: `mysql` → **`pgsql`**, `mysql1` → **`pgsql1`** (butun `app/` bo'yicha).
> * **Barcha bayroqlar YOQILDI** (`config/db_triggers.php`), chunki triggerlar
>   egaz_idxdb ning MySQL sxemasida qolgan — PostgreSQL bazasida ular YO'Q.
>   Bayroq o'chiq bo'lsa yon ta'sirni hech kim bajarmasdi.
> * Ataylab `false` qolgan ikkitasi: `cms_users.updateKod` va
>   `i_money_details.i_money_details_orgs` — ular MySQL da ham no-op edi
>   (`config/db_triggers.php` → `noop` ro'yxati; `triggers:status` ularni
>   ogohlantirish deb hisoblamaydi).
> * **Trigger kodi IKKALA dialektda ishlaydi.** SQL ulanishning `driver` iga
>   qarab quriladi, dialektga bog'liq joy faqat `BaseTrigger` da:
>
>   | Yordamchi | MySQL | PostgreSQL |
>   |---|---|---|
>   | `upsert()` | `ON DUPLICATE KEY UPDATE c = c + ?` | `ON CONFLICT (<PK>) DO UPDATE SET c = jadval.c + ?` |
>   | `insIgnore()` | `INSERT IGNORE` | `ON CONFLICT DO NOTHING` |
>   | `toUInt()` | `CONVERT(x, UNSIGNED INTEGER)` | `CASE WHEN x ~ '^[0-9]+$' THEN x::bigint ELSE 0 END` |
>   | `qi()` | `` `x` `` | `"x"` |
>
>   `triggers:status` ham ikkala `information_schema` ko'rinishini o'qiydi.
>   Ya'ni `db_triggers.connection` ni `mysql`/`mysql1` ga qaratsangiz ham
>   trigger mantig'i o'zgarishsiz ishlayveradi.
> * `TriggerFlags::enabled()` dagi xato tuzatildi: bayroq nomida nuqta bor
>   (`cms_users.incUsers`), `config('db_triggers.triggers.' . $name)` esa uni
>   ichma-ich yo'l deb o'qib **doim `false`** qaytarardi.
>
> Ulanish jadvali (1-bo'lim) ham shunga ko'ra o'qilsin.

**Holat:** kod tayyor, **bayroqlar yoqiq** (PostgreSQL da mos DB trigger yo'q).
MySQL da ishlaganda esa hammasi o'chiq turardi — mantiqni bazadagi triggerlar
bajarardi.

**Manba:** [docs/idxdb_triggers.sql](idxdb_triggers.sql) — `egaz_idxdb.sql` dumpidan
ajratib olingan **14 ta trigger, 7 ta jadval**. Hammasi ko'chirildi.

> Bu hujjat **egaz-indexator** loyihasi uchun. Asosiy egaz bazasining (brrgz) 31 ta
> triggeri alohida ko'chirilgan: `egaz/docs/db-triggers-to-service-migration.md`.

---

## 1. Qaysi baza, qaysi ulanish

`config/database.php`:

| Ulanish | Baza (.env) | Nima |
|---|---|---|
| `mysql` (standart) | `DB_DATABASE` | **egaz_idxdb — shu yerdagi 14 ta trigger** |
| `mysql1` | `DB_DATABASE1` @ `DB_HOST1` | asosiy egaz bazasi (brrgz) — faqat **o'qish** uchun |
| `mysql_brrgz` | `DB_DATABASE1` | e'lon qilingan, lekin **ishlatilmaydi** (0 ta chaqiruv) |
| `mysql_egaz` | `DB_DATABASE2` @ `DB_HOST1` | e'lon qilingan, lekin **ishlatilmaydi** (0 ta chaqiruv) |
| `pgsql` / `pgpush` | PostgreSQL | **mirror — trigger YO'Q** |
| `clickhouse` | ClickHouse | **trigger YO'Q** |

`.env.example:15-19` da aniq yozilgan: `# EGAZ MAIN (brrgz) — 'mysql1' ulanishi`,
`DB_DATABASE1=brrgz`. Kod ham shuni tasdiqlaydi (`FactoryInvoice.php:64`,
`FactorySignature.php:208-222` brrgz ga `mysql1` orqali yozadi).

Egaz loyihasi bu bazaga o'zining `mysql_02` ulanishi orqali kiradi (`DB_HOST2`,
odatda `192.168.0.6`). Ya'ni **bitta bazaga ikki loyiha yozadi** — shuning uchun
bayroqni faqat haqiqatan yozadigan loyihada yoqing.

Barcha trigger yon ta'sirlari `config('db_triggers.connection')` ulanishida
bajariladi (standart: `null` = `mysql`). Kerak bo'lsa har bir `TriggerBus`
chaqiruvining oxirgi argumenti bilan boshqa ulanish beriladi.

---

## 2. ⚠ `cms_users` — ikkala bazada ham triggerli

`egaz_idxdb` da ham, asosiy egaz bazasida ham `cms_users` jadvalida **bir xil 5 ta
trigger** bor: `setIDkodUser`, `incUsers`, `updateKod`, `updSubscrbStatus`, `decUSers`.
Tanalari solishtirildi — **mazmunan aynan bir xil**, farq faqat izohlarda:

| Trigger | Farq |
|---|---|
| `setIDkodUser` | egaz da ELSE shoxida katta izoh bloki bor, idxdb da bo'sh — natija bir xil |
| `incUsers` | farq yo'q |
| `updateKod` | egaz da izohga olingan tana, idxdb da butunlay bo'sh — ikkalasi ham no-op |
| `updSubscrbStatus` | egaz da `cms_sync_users` satri izohda, idxdb da yo'q — natija bir xil |
| `decUSers` | egaz da izohlar bor — natija bir xil |

Shuning uchun `App\Services\DbTriggers\CmsUsersTriggers` ikkala loyihada bir xil
mantiqni bajaradi. **Bayroqni faqat o'sha bazaga yozadigan loyihada yoqing.**

egaz-indexator hozircha `cms_users` ga **yozmaydi** — u faqat `mysql1` ulanishi
orqali **o'qiydi** (masalan `activeAbonents.php:53`). Ya'ni bu 5 ta bayroq bu yerda
zaxira sifatida turibdi; keyinchalik indexator yozadigan bo'lsa tayyor.

### 2.1. Teskari yo'nalish: egaz bu bazaga nima yozadi?

egaz loyihasida `DB::connection('mysql_02')` bilan qilingan barcha yozuvlar
tekshirildi. 7 ta triggerli jadvaldan **faqat bittasiga** tegadi:

| egaz dagi joy | Jadval | Amal | Trigger yonadimi? |
|---|---|---|---|
| `AdminScalesController.php:274` | `tb_scales_logs` | UPDATE | **Yo'q** — yagona trigger `tb_scales_logs_bi` BEFORE **INSERT** |
| `AdminScalesController.php:311` | `tb_scales_logs` | UPDATE | **Yo'q** — xuddi shunday |

Qolgan 6 ta jadvalga (`i_abonent_details`, `i_deposit_details`, `i_money_details`,
`i_real_details`, `tb_factory_integration`, `cms_users`) egaz `mysql_02` orqali
**umuman yozmaydi**.

**Xulosa:** bu 14 ta triggerning yon ta'sirlari faqat egaz-indexator ichida
uyg'onadi. Shu sababli egaz loyihasiga idxdb triggerlari uchun qo'shimcha ulash
kerak emas.

---

## 3. Qayerga nima qo'yildi

| Fayl | Vazifasi |
|---|---|
| [config/db_triggers.php](../config/db_triggers.php) | 14 ta bayroq (hammasi `false`), umumiy kalit, ulanish, `dry_run`, `log`, `fail_open`, juftliklar |
| [app/Services/DbTriggers/TriggerFlags.php](../app/Services/DbTriggers/TriggerFlags.php) | Bayroqlarni o'qish; test uchun `override()` |
| [app/Services/DbTriggers/BaseTrigger.php](../app/Services/DbTriggers/BaseTrigger.php) | Umumiy asos: MySQL semantikasi (`eq`/`neq`/`truthy`/`effective`/`monthOf`), yozish yordamchilari, **ulanish tanlash**, `dry_run` va log |
| [app/Services/DbTriggers/TriggerBus.php](../app/Services/DbTriggers/TriggerBus.php) | **Kod joylari faqat shuni chaqiradi.** Jadval → hodisa → handler xaritasi |
| [app/Services/DbTriggers/CmsUsersTriggers.php](../app/Services/DbTriggers/CmsUsersTriggers.php) | `setIDkodUser`, `incUsers`, `updateKod`, `updSubscrbStatus`, `decUSers` |
| [app/Services/DbTriggers/IAbonentDetailsTriggers.php](../app/Services/DbTriggers/IAbonentDetailsTriggers.php) | `i_abonent_details_ai`, `i_abonent_details_au` |
| [app/Services/DbTriggers/IDepositDetailsTriggers.php](../app/Services/DbTriggers/IDepositDetailsTriggers.php) | `insert_i_deposit_details` |
| [app/Services/DbTriggers/IMoneyDetailsTriggers.php](../app/Services/DbTriggers/IMoneyDetailsTriggers.php) | `plus_deposit_org`, `i_money_details_orgs` |
| [app/Services/DbTriggers/IRealDetailsTriggers.php](../app/Services/DbTriggers/IRealDetailsTriggers.php) | `insert_i_real_details`, `minus_deposit_orgs` |
| [app/Services/DbTriggers/TbFactoryIntegrationTriggers.php](../app/Services/DbTriggers/TbFactoryIntegrationTriggers.php) | `tb_factory_integration_bi` |
| [app/Services/DbTriggers/TbScalesLogsTriggers.php](../app/Services/DbTriggers/TbScalesLogsTriggers.php) | `tb_scales_logs_bi` |
| [app/Console/Commands/DbTriggersStatus.php](../app/Console/Commands/DbTriggersStatus.php) | `php artisan triggers:status` |

```
php artisan triggers:status
php artisan triggers:status --conflicts
php artisan triggers:status --connection=mysql1     # boshqa bazani tekshirish
```

| Bazada | PHP | Holat | Ma'no |
|---|---|---|---|
| bor | o'chiq | `bazada (asl holat)` | ✅ hali ko'chirilmagan |
| yo'q | YOQIQ | `PHP da (ko'chirilgan)` | ✅ ko'chirish tugagan |
| bor | YOQIQ | `IKKI MARTA BAJARILADI` | ❌ hisoblagichlar/depozit ikki barobar |
| yo'q | o'chiq | `HECH QAYERDA YO'Q` | ❌ mantiq yo'qolgan |

---

## 4. Ishlatish shakli

```php
use App\Services\DbTriggers\TriggerBus;

TriggerBus::insert('i_real_details', $details);            // + AFTER INSERT (2 ta trigger)
$id = TriggerBus::insertGetId('tb_factory_integration', $data);  // + BEFORE INSERT
TriggerBus::insertMany('i_real_details', $chunk);          // bulk: 1 ta INSERT, trigger har qatorga
TriggerBus::update('i_abonent_details', ['id' => $id], $p);
TriggerBus::delete('i_money_details', ['dt' => $dt]);

// boshqa bazaga yozilsa — oxirgi argument ulanish nomi
TriggerBus::insert('cms_users', $row, 'mysql1');
```

Bayroqlar o'chiq bo'lganda bularning hammasi **aynan bitta so'rov** bajaradi —
qo'shimcha SELECT ham, sikl ham yo'q.

---

## 5. Ko'chirish tartibi (bitta trigger uchun)

1. `.env` ga `DB_TRIGGERS_DRY_RUN=true` + bayroqni yoqing → PHP versiyasi bazaga
   yozmaydi, faqat `DBTRG […] DRY …` logini chiqaradi. Baza triggeri o'z ishini
   qilaveradi, natijani solishtirasiz.
2. `SHOW CREATE TRIGGER \`nomi\`;` bilan nusxa oling, so'ng `DROP TRIGGER \`nomi\`;`
3. Bayroqni `true` qiling (config yoki `.env`).
4. `php artisan config:cache` (agar keshlangan bo'lsa).
5. `php artisan triggers:status` — `PHP da (ko'chirilgan)` bo'lishi kerak.

---

## 6. Muhim ogohlantirishlar

### 6.1. `i_real_details` — bitta INSERT, ikki trigger

`insert_i_real_details` (i_real_orgs agregati) va `minus_deposit_orgs`
(organizations.deposit −= amount) **bir xil INSERT da** yonadi. Bazadagi tartib:
avval agregat, keyin depozit. `TriggerBus` shu tartibni saqlaydi.

Bittasini PHP ga o'tkazib ikkinchisini bazada qoldirish mumkin, lekin
`triggers:status` buni "juftlik muammosi" sifatida ko'rsatib turadi
(`config/db_triggers.php` → `pairs`).

### 6.2. `i_abonent_details_au` — UPDATE da ham hisoblagich OSHADI

AFTER UPDATE triggerining tanasi AFTER INSERT bilan **aynan bir xil**: u
`i_abonent_orgs.all_users` ni kamaytirmay, yana bittaga **oshiradi**. Ya'ni har bir
UPDATE agregatni shishiradi. Bu bazadagi mavjud xatti-harakat, ataylab aynan
takrorlangan.

Hozircha `i_abonent_details` ga **UPDATE qiladigan kod topilmadi** — jadval
`activeAbonents` buyrug'ida to'liq truncate qilinib qaytadan quriladi. Shu sababli
bu trigger amalda ishlamaydi. Yoqishdan oldin UPDATE yo'li paydo bo'lmaganini
tekshiring.

### 6.3. TRUNCATE triggerlarni UYG'OTMAYDI

`activeAbonents`, `calcDebit`, `calcRealizations`, `calcOrgDebit` buyruqlari
detal va agregat jadvallarni birga `truncate()` qilib qaytadan quradi. MySQL da
`TRUNCATE` trigger yoqmaydi — shuning uchun u joylarga `TriggerBus` qo'yilmagan
(qo'yilsa ham hech narsa qilmasdi). Agregatni keyingi INSERT lar tiklaydi.

`DELETE` esa trigger yoqadi — shuning uchun `i_money_details` DELETE lari ulangan
(`i_money_details_orgs` uchun; uning tanasi bazada izohda, ya'ni hozir no-op).

### 6.4. ⛔ `i_money_details_orgs` — DOIMO o'chiq qoldiring

Bazada tanasi to'liq izohga olingan (no-op). PHP versiyasida izohdagi mantiq
(provayder bo'yicha `i_money_orgs` dan qaytarish) **to'liq yozilgan**, lekin uni
yoqish uchta muammo tug'diradi:

1. **Bu ko'chirish emas.** Bazada trigger hech narsa qilmaydi — yoqish yangi
   xatti-harakat qo'shadi.
2. **Ikki marta ayirish.** `calcDebit.php` da har bir `i_money_details` DELETE dan
   bir qator **oldin** `i_money_orgs` qo'lda o'chiriladi:
   `:35`/`:37`, `:100`/`:102`, `:118`/`:120`. Ya'ni trigger allaqachon o'chirilgan
   qatorlardan yana ayirishga urinadi.
3. **Xotira.** Bayroq yoqilsa `TriggerBus::delete()` DELETE dan oldin barcha mos
   qatorlarni o'qiydi (FOR EACH ROW uchun). `calcDebit.php:37` da shart butun sana
   **oralig'i** — millionlab qator xotiraga tushishi mumkin.
   (`max_old_rows` chegarasidan oshsa logga ogohlantirish yoziladi.)

### 6.5. `i_money_orgs` qo'lda yangilanadi

[app/Actions/IMoneyDebit.php:101](../app/Actions/IMoneyDebit.php#L101) `i_money_orgs`
ni **xom SQL** bilan (`ON DUPLICATE KEY UPDATE`) o'zi yangilaydi — bu trigger emas,
shuning uchun teginilmadi. Ya'ni `i_money_orgs` ga ikki manba yozadi: bu kod
(qo'shish) va `i_money_details_orgs` (agar yoqilsa — ayirish).


### 6.6. `organizations.deposit` — uchta yozuvchi

| Yozuvchi | Nima qiladi |
|---|---|
| `minus_deposit_orgs` (trigger) | i_real_details INSERT da **kamaytiradi** |
| `plus_deposit_org` (trigger) | i_money_details INSERT da **oshiradi** |
| [calcTransanctions.php:273](../app/Console/Commands/calcTransanctions.php#L273) | `mysql1` dan olingan SUM bilan qiymatni **butunlay qayta yozadi** |

Uchinchisi har doim g'olib chiqadi. Ko'chirishni tekshirayotganda
`calcTransactions deposit` ni ishlatmang — u depozit farqlarini yuvib yuboradi.

Yana bir joy: [app/Jobs/Transaction.php:108](../app/Jobs/Transaction.php#L108) da
`UPDATE organizations SET deposit = deposit + $amount` bor, lekin u **izohga
olingan**. Agar kimdir uni tiklasa va `plus_deposit_org` yoqilgan bo'lsa — har bir
to'lov depozitga **ikki marta** qo'shiladi.

### 6.7. Qayta hisoblash depozitni tiklamaydi (mavjud nomutanosiblik)

`minus_deposit_orgs` va `plus_deposit_org` — faqat INSERT da ishlaydi, teskarisi yo'q.
Qayta hisoblash buyruqlari esa detal va agregat jadvallarni tozalab qaytadan quradi,
lekin `organizations.deposit` ni tiklamaydi:

`calcRealizations.php:55/56`, `:82/83`, `:88/89` · `calcDebit.php:35/37`, `:81`, `:100/102`, `:118/120`

Ya'ni har bir qayta indekslash o'sha kun/oraliqni depozitga **yana bir marta**
qo'llaydi. Bu bazadagi hozirgi xatti-harakat — PHP versiyasi uni ataylab aynan
takrorlaydi, "tuzatmaydi".

**Amaliy maslahat:** tarixiy qayta qurishlarni (`realDetails full`, `debit:make full`)
bayroqlar **O'CHIQ** holda, baza triggerlari hali turganda bajaring. Bayroqlarni
faqat kunlik/inkremental yo'l uchun yoqing.

### 6.8. Ishlash (unumdorlik) haqida

Ikkala `i_real_details` bayrog'i yoqilsa har bir sotuv qatoriga **2 ta qo'shimcha
so'rov** qo'shiladi (i_real_orgs upsert + organizations kamaytirish). `IReal.php`
har bir qatorni alohida tranzaksiyada yozadi, ya'ni `realDetails full` da yozuv
hajmi ~3 barobar oshadi.

`activeAbonents` da `i_abonent_details_ai` yoqilsa har bir abonentga bitta
`INSERT ... ON DUPLICATE KEY UPDATE` qo'shiladi (yuz minglab qator).

⚠ Agar kelajakda bu sikllar tezlik uchun ommaviy (bulk) insertga o'tkazilsa —
`DB::table()->insert($chunk)` EMAS, `TriggerBus::insertMany()` ishlatilishi shart:
u bitta bulk INSERT bajaradi, lekin triggerlarni MySQL dagidek har bir qatorga
chaqiradi.

---

## 9. Ishga tushirishdan oldin serverda tekshiriladigan narsalar

Repozitoriyda `.env` yo'q (faqat `.env.example`), shuning uchun quyidagilarni
serverdan tasdiqlash kerak:

1. **`DB_DATABASE` haqiqatan `egaz_idxdb` mi?** Agar u brrgz bo'lsa, trigger
   servislari butunlay noto'g'ri sxemada ishlaydi. Tekshirish:
   `php artisan triggers:status` — 14 ta trigger "bazada bor" deb ko'rsatilsa,
   ulanish to'g'ri.
2. **`DB_HOST` va `DB_HOST1` bir xilmi?** Agar bir xil server bo'lsa, `mysql` va
   `mysql1` — bitta serverdagi ikki sxema. Trigger ichidagi
   `(SELECT id_region FROM organizations …)` so'rovi sxema nomisiz yozilgan, ya'ni
   **ulanishning o'z sxemasiga** tushadi. PHP versiyasi ham shunday ishlaydi
   (standart ulanish) — bu to'g'ri, lekin bilib turish kerak.
3. **egaz dagi `DB_DATABASE2`** (`mysql_02`, host `DB_HOST2`, odatda `192.168.0.6`)
   aynan shu `egaz_idxdb` mi? §2.1 dagi xulosa shu taxminga asoslangan.

Yana ikkita eslatma:

- `mysql_brrgz` va `mysql_egaz` ulanishlari `config/database.php` da e'lon qilingan,
  lekin butun loyihada **bironta ham** `connection('mysql_brrgz')` /
  `connection('mysql_egaz')` chaqiruvi yo'q — ular ishlatilmayotgan sozlama.
  Asosiy bazaga real murojaat `mysql1` orqali ketadi.
- `AdminScalesController` (egaz loyihasida) `$this->table = "tb_scales_logs"`
  qilib qo'yilgan, lekin `$extra_connection` berilmagan. CRUDBooster ning generik
  add/edit/delete tugmalari o'chiq bo'lgani uchun hozir zararsiz — lekin kimdir
  ularni yoqsa, yozuv `mysql` (brrgz) ga tushadi, `mysql_02` (idxdb) ga emas.

---

## 7. Kod qayerlariga ulandi

| Fayl:qator | Jadval / amal | Trigger |
|---|---|---|
| [app/Actions/IReal.php:85](../app/Actions/IReal.php#L85) | i_real_details INSERT | `insert_i_real_details` + `minus_deposit_orgs` |
| [app/Actions/IMoneyDebit.php:97](../app/Actions/IMoneyDebit.php#L97) | i_money_details INSERT | `plus_deposit_org` |
| [app/Jobs/OrgDebit.php:66](../app/Jobs/OrgDebit.php#L66) | i_deposit_details INSERT | `insert_i_deposit_details` |
| [app/Console/Commands/activeAbonents.php:65](../app/Console/Commands/activeAbonents.php#L65) | i_abonent_details INSERT | `i_abonent_details_ai` |
| [app/Console/Commands/calcDebit.php:37,102,120](../app/Console/Commands/calcDebit.php#L37) | i_money_details DELETE | `i_money_details_orgs` |
| [app/Services/FactoryInvoice.php:43](../app/Services/FactoryInvoice.php#L43) | tb_factory_integration INSERT | `tb_factory_integration_bi` |
| [app/Services/Scales.php:49](../app/Services/Scales.php#L49) | tb_scales_logs INSERT | `tb_scales_logs_bi` |

### 7.1. Ataylab ULANMAGAN joylar

| Joy | Sabab |
|---|---|
| `activeAbonents.php:42-43`, `calcDebit.php:81-82`, `calcRealizations.php:89`, `calcOrgDebit.php:74-75` | `TRUNCATE` — MySQL da trigger uyg'otmaydi (§6.3) |
| `calcRealizations.php:55,83`, `calcOrgDebit.php:103-104` | `i_real_details` / `i_deposit_details` da AFTER DELETE trigger YO'Q |
| `app/Console/Commands/PgSyncTable.php` (barchasi) | PostgreSQL mirror — trigger yo'q |
| `Clickhouse*` / `*2Clickhouse` buyruqlari | ClickHouse — trigger yo'q |
| `app/Console/Commands/calcBalance.php:79,89` | `i_balance` — **egaz_idxdb dagi** i_balance da trigger YO'Q (`i_balance_bi` faqat asosiy egaz bazasida) |
| `app/Services/GnpCamera.php:56` | Butunlay izohga olingan (o'lik kod) |
| `mysql1` ulanishidagi `cms_users` / `tb_gas_debit` / `tb_requests_ballons` o'qishlari | Faqat SELECT — yozuv emas |

### 7.2. ⚠ Chetlab o'tuvchi yo'l — `public/adminer_aka_reg.php`

Loyihaning **veb-ildizida** to'liq Adminer 4.2.4 (2015-yilgi versiya) yotibdi
(`public/adminer_aka_reg.php`, 413 KB). U o'zining mysqli ulanishini ochadi,
`config/database.php` ni umuman chetlab o'tadi va operator yozgan istalgan SQL ni
bajaradi — ya'ni bu jadvallarga TriggerBus'siz yozish mumkin.

Ko'chirish nuqtai nazaridan: u orqali qilingan qo'lda o'zgartirishlarda PHP
triggerlari **ishlamaydi**. Bundan tashqari bu eski Adminer versiyasi HTTP orqali
ochiq turgani xavfsizlik masalasi — alohida ko'rib chiqish tavsiya etiladi.

---

## 8. Tekshirilgan holatlar

Servis qatlami 21 ta tekshiruvdan o'tdi (bayroqlar o'chiq / yoqilgan / umumiy kalit
o'chiq):

- bayroqlar o'chiq → BEFORE metodlari qatorni **o'zgarishsiz** qaytaradi,
  AFTER metodlari hech narsa qilmaydi, `snapshot()` `null`;
- yoqilgan → `tb_factory_integration_bi` (`000000003`→274, `000000006`→290),
  ELSE shoxi (noma'lum kod — tegilmaydi), `tb_scales_logs_bi` (1→13, 239→350,
  77→o'zgarmaydi), `setIDkodUser` (kod bor → tegmaydi; abonent emas → `kod=NULL`);
- `i_real_details` da 2 ta handler va ularning **tartibi** bazadagidek;
- umumiy kalit hamma bayroqni bekor qiladi.

Barcha o'zgartirilgan fayllar `php -l` dan xatosiz o'tdi (PHP 7.4 — bu loyihaning
`vendor/` i PHP ≥ 7.3 talab qiladi).
