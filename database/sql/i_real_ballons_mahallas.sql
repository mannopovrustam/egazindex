-- ============================================================================
-- I_REAL_BALLONS_MAHALLAS — MAHALLA KESIMIDA "NECHA KUN OLDIN G/B OLGAN" TAQSIMOTI
--
-- BAZA:       egaz_idxdb          (egaz-indexator ning DEFAULT `mysql` ulanishi)
-- ISHGA TUSHIRISH:  QO'LDA. Migration YO'Q (loyihada `artisan migrate` ishlatilmaydi):
--     mysql -u <user> -p egaz_idxdb < database/sql/i_real_ballons_mahallas.sql
--
-- MAQSAD: `i_real_ballons_orgs` ning MAHALLA kesimidagi va MAYDAROQ oraliqli
--         ko'rinishi. Har (id_region, id_org, id_mahalla) uchun oxirgi ballon
--         olgan sanadan (brrgz.cms_users.rdt) hisoblangan kunlar soni bo'yicha
--         ABONENTLAR SONI yoziladi.
--
-- FARQI i_real_ballons_orgs dan:
--   1) `id_mahalla` qo'shilgan — taqsimot mahalla darajasida;
--   2) 71 kundan keyingi qism 5 kunlik qadamlarga bo'lingan:
--      71to75 ... 96to100, undan keyingisi 100tomore;
--   3) `noneget` (rdt bo'sh) va `is_need` (ehtiyoji yo'q) ustunlari YO'Q —
--      bu jadval faqat g/b OLGAN abonentlar taqsimotini saqlaydi.
--
-- USTUNLAR MA'NOSI (d = DATEDIFF(dt, cms_users.rdt)):
--   1to30      — d 0..30      (oxirgi 30 kun ichida olgan)
--   31to35     — d 31..35
--   ...        — har biri 5 kunlik oraliq
--   96to100    — d 96..100
--   100tomore  — d >= 101     (100 kundan ko'p vaqt oldin olgan)
--   dt         — hisob-kitob sanasi (kunlar shu sanadan orqaga sanaladi)
--
-- HISOBGA OLINADIGAN ABONENTLAR (`real:ballons-mah` komandasi bilan bir xil):
--   cms_users.id_cms_privileges = 4, status = 'Active', tp <> 'GG',
--   is_need = 1, rdt IS NOT NULL.
--
-- CHARSET: egaz_idxdb uy uslubi = utf8mb3 (i_real_ballons_orgs bilan bir xil).
--
-- DIQQAT: ustun nomlari raqam bilan boshlanadi (`1to30`) — SQL da HAR DOIM
--         teskari apostrof ichida yoziladi. Bu i_real_ballons_orgs dan
--         meros qolgan uslub, ataylab saqlangan.
-- ============================================================================

-- Toza qayta yaratish kerak bo'lsa:
-- DROP TABLE IF EXISTS `i_real_ballons_mahallas`;

CREATE TABLE IF NOT EXISTS `i_real_ballons_mahallas` (
  `id_region` smallint unsigned NOT NULL,
  `id_org` int unsigned NOT NULL,
  `id_mahalla` int unsigned DEFAULT NULL,
  `1to30` mediumint unsigned NOT NULL DEFAULT '0',
  `31to35` mediumint unsigned NOT NULL DEFAULT '0',
  `36to40` mediumint unsigned NOT NULL DEFAULT '0',
  `41to45` mediumint unsigned NOT NULL DEFAULT '0',
  `46to50` mediumint unsigned NOT NULL DEFAULT '0',
  `51to55` mediumint unsigned NOT NULL DEFAULT '0',
  `56to60` mediumint unsigned NOT NULL DEFAULT '0',
  `61to65` mediumint unsigned NOT NULL DEFAULT '0',
  `66to70` mediumint unsigned NOT NULL DEFAULT '0',
  `71to75` mediumint unsigned NOT NULL DEFAULT '0',
  `76to80` mediumint unsigned NOT NULL DEFAULT '0',
  `81to85` mediumint unsigned NOT NULL DEFAULT '0',
  `86to90` mediumint unsigned NOT NULL DEFAULT '0',
  `91to95` mediumint unsigned NOT NULL DEFAULT '0',
  `96to100` mediumint unsigned NOT NULL DEFAULT '0',
  `100tomore` mediumint unsigned NOT NULL DEFAULT '0',
  `dt` date DEFAULT NULL,
  KEY `real_ballons_mah_org_idx` (`id_region`,`id_org`,`id_mahalla`),
  KEY `real_ballons_mah_dt_idx` (`dt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
