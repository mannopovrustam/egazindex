-- ============================================================================
-- I_REAL_BALLONS_MAHALLAS — POSTGRESQL NUSXASI
--
-- BAZA:       egaz_idxpost        (`pgsql` ulanishi — egaz_idxdb ning nusxasi)
-- ISHGA TUSHIRISH:  QO'LDA. Migration YO'Q:
--     psql -U <user> -d egaz_idxpost -f database/sql/i_real_ballons_mahallas.pg.sql
--
-- MySQL varianti: database/sql/i_real_ballons_mahallas.sql — ma'nosi, ustunlari
-- va indekslari AYNAN o'sha. Bu yerda faqat dialekt farqlari:
--
--   `1to30` (teskari apostrof)  →  "1to30" (qo'shtirnoq)
--       Ustun nomi RAQAM bilan boshlanadi — PostgreSQL da qo'shtirnoqsiz
--       yozib bo'lmaydi, va qo'shtirnoq ichida yozilgani uchun nomi HAR DOIM
--       kichik harfda, aynan shu ko'rinishda qoladi.
--   smallint unsigned / mediumint unsigned  →  smallint / integer
--       PG da `unsigned` yo'q; salbiy qiymat kirmasligi uchun CHECK qo'yilgan.
--   ENGINE / CHARSET  →  yo'q (baza kodlashi UTF8).
--
-- ⚙ DUAL WRITE: `real:ballons-mah` komandasi MySQL ga yozganda AYNAN shu
--   qatorlar shu jadvalga ham tushadi (config/dual_write.php). Jadval bu yerda
--   BO'LMASA — nusxa jimgina yozilmaydi (faqat logda ogohlantirish), shuning
--   uchun komandani ishga tushirishdan oldin shu faylni yurgizing va
--   `php artisan dual:status` bilan tekshiring.
--
-- `id` va `created_at` ustunlari: nusxa jadvallarda odatda qo'shiladi
-- (config/dual_write.php → id_column / created_at_column). Bu jadvalda ular
-- ATAYLAB YO'Q — MySQL varianti ham kalitsiz, va jadval har `dt` uchun
-- DELETE + INSERT bilan qayta quriladi, ya'ni surrogat kalitning vazifasi yo'q.
-- ============================================================================

-- Toza qayta yaratish kerak bo'lsa:
-- DROP TABLE IF EXISTS i_real_ballons_mahallas;

CREATE TABLE IF NOT EXISTS i_real_ballons_mahallas (
  id_region    smallint NOT NULL CHECK (id_region >= 0),
  id_org       integer  NOT NULL CHECK (id_org >= 0),
  id_mahalla   integer  NULL     CHECK (id_mahalla >= 0),
  "1to30"      integer  NOT NULL DEFAULT 0 CHECK ("1to30"     >= 0),
  "31to35"     integer  NOT NULL DEFAULT 0 CHECK ("31to35"    >= 0),
  "36to40"     integer  NOT NULL DEFAULT 0 CHECK ("36to40"    >= 0),
  "41to45"     integer  NOT NULL DEFAULT 0 CHECK ("41to45"    >= 0),
  "46to50"     integer  NOT NULL DEFAULT 0 CHECK ("46to50"    >= 0),
  "51to55"     integer  NOT NULL DEFAULT 0 CHECK ("51to55"    >= 0),
  "56to60"     integer  NOT NULL DEFAULT 0 CHECK ("56to60"    >= 0),
  "61to65"     integer  NOT NULL DEFAULT 0 CHECK ("61to65"    >= 0),
  "66to70"     integer  NOT NULL DEFAULT 0 CHECK ("66to70"    >= 0),
  "71to75"     integer  NOT NULL DEFAULT 0 CHECK ("71to75"    >= 0),
  "76to80"     integer  NOT NULL DEFAULT 0 CHECK ("76to80"    >= 0),
  "81to85"     integer  NOT NULL DEFAULT 0 CHECK ("81to85"    >= 0),
  "86to90"     integer  NOT NULL DEFAULT 0 CHECK ("86to90"    >= 0),
  "91to95"     integer  NOT NULL DEFAULT 0 CHECK ("91to95"    >= 0),
  "96to100"    integer  NOT NULL DEFAULT 0 CHECK ("96to100"   >= 0),
  "100tomore"  integer  NOT NULL DEFAULT 0 CHECK ("100tomore" >= 0),
  dt           date     NULL
);

CREATE INDEX IF NOT EXISTS real_ballons_mah_org_idx
    ON i_real_ballons_mahallas (id_region, id_org, id_mahalla);

CREATE INDEX IF NOT EXISTS real_ballons_mah_dt_idx
    ON i_real_ballons_mahallas (dt);
