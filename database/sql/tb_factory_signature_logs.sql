-- ============================================================================
-- FACTORY SIGNATURE — XOM PAYLOAD LOGI
--
-- BAZA:       egaz_idxdb          (egaz-indexator ning DEFAULT `mysql` ulanishi)
-- ISHGA TUSHIRISH:  QO'LDA. Migration YO'Q (loyihada `artisan migrate` ishlatilmaydi).
--
-- MAQSAD: 1C dan kelgan HAR BIR POST ning XOM baytlari (request()->getContent())
--         shu yerga, hech qanday tahlilsiz, avval yoziladi. Faqat shundan keyin
--         normalizatsiya qilinib brrgz.tb_factory_signatures ga ko'chiriladi.
--         Agar brrgz ga yozish ishlamasa — ma'lumot YO'QOLMAYDI: qator norm_status=2
--         bo'lib qoladi va `php artisan factory_signature:normalize` uni qayta uradi.
--
-- NEGA integration_logs YETARLI EMAS (haqiqiy DDL, egaz_idxdb.sql:1218 dan):
--   CREATE TABLE `integration_logs` (
--     `module`     varchar(150) DEFAULT NULL,
--     `request`    text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
--     `payload`    text,                                   -- <= 64 KB, kesiladi
--     `value`      varchar(255) DEFAULT NULL,
--     `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
--     KEY `module` (`module`)                              -- PRIMARY KEY YO'Q!
--   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
--   => PK yo'q (bitta qatorni UPDATE qilib bo'lmaydi), payload TEXT (64KB).
--   Shuning uchun integration_logs "uy an'anasi" uchun qoladi, lekin ISHONCHLI MANBA EMAS.
--
-- CHARSET: egaz_idxdb uy uslubi = utf8mb3 / utf8mb3_general_ci
--          (integration_logs va tb_factory_integration aynan shunday — TEKSHIRILGAN).
--          LEKIN `payload` — ISTISNO: u LONGBLOB (pastda sababi yozilgan).
--
-- DIQQAT — SWEEPER CHEGARASI: FactorySignatureNormalize faqat
--          `norm_status=2 AND norm_attempts < 20` qatorlarni oladi. 20 tadan keyin qator
--          ABADIY tashlab ketiladi. brrgz jadvali kech yaratilgan bo'lsa (yoki uzoq
--          davom etgan avariyadan keyin) qatorlarni QO'LDA tiklash kerak:
--            UPDATE tb_factory_signature_logs
--               SET norm_attempts = 0, norm_error = NULL
--             WHERE norm_status = 2;
-- ============================================================================

-- DIQQAT: DROP QILINMAYDI. Bu jadval — audit izi, qayta ishga tushirishda
-- ma'lumot yo'qolmasligi kerak. Faqat toza dev bazada quyidagini oching:
-- DROP TABLE IF EXISTS `tb_factory_signature_logs`;

CREATE TABLE IF NOT EXISTS `tb_factory_signature_logs` (
  `id`            bigint unsigned NOT NULL AUTO_INCREMENT,

  `uid`           varchar(64)  DEFAULT NULL
                  COMMENT '1C GUID — VALIDATSIYAGACHA olingan, buzuq/uzun bo`lishi mumkin (shuning uchun 64, brrgz da 36)',
  `waybill_id`    int          DEFAULT NULL
                  COMMENT 'brrgz.tb_factory_signatures.id — FK YO`Q (boshqa baza!)',

  -- LONGBLOB — MATN EMAS, BAYT. Sabab: bu ustun 1C dan kelgan XOM baytlarni AYNAN
  -- saqlashi kerak. `mysql` ulanishi charset=utf8mb4 + strict=true (config/database.php)
  -- bo'lgani uchun utf8mb3 ustunga 4 baytli belgi (emoji) yoki UTF-8 bo'lmagan (CP1251 —
  -- 1C uchun odatiy) tana kelsa MySQL 1366 "Incorrect string value" beradi va INSERT
  -- ni RAD ETADI. Bu esa aynan shu jadval oldini olishi kerak bo'lgan holat:
  -- payload butunlay yo'qoladi, sweeper qayta uradigan qator ham qolmaydi.
  -- BLOB da charset konvertatsiyasi yo'q => har qanday bayt ketma-ketligi saqlanadi.
  -- `payload` hech qachon JOIN/ORDER BY/INDEX qilinmaydi => matn bo'lishining foydasi yo'q.
  `payload`       longblob
                  COMMENT 'XOM request()->getContent() (BAYT). LONGBLOB — charset validatsiyasi yo`q, hech narsa yo`qolmaydi',
  `remote_ip`     varchar(45)  COLLATE utf8mb3_general_ci DEFAULT NULL COMMENT '45 = IPv6 uchun yetarli',

  `norm_status`   tinyint      NOT NULL DEFAULT '0'
                  COMMENT '0-qabul qilindi (hali ko`chirilmagan), 1-brrgz ga yozildi, 2-VAQTINCHA xato (sweeper qayta uriniladi), 3-yozilmadi: brrgz da allaqachon IMZOLANGAN yoki dublikat, 4-DOIMIY xato (validatsiya/uzunlik/sxema) yoki eskirgan payload — sweeper OLMAYDI, 1C ga 422 qaytarilgan',
  `norm_error`    varchar(500) COLLATE utf8mb3_general_ci DEFAULT NULL,
  `norm_attempts` int          NOT NULL DEFAULT '0' COMMENT 'sweeper 20 tadan keyin to`xtaydi',
  `norm_at`       timestamp    NULL DEFAULT NULL,

  `created_at`    timestamp    NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_norm` (`norm_status`,`norm_attempts`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;


-- ----------------------------------------------------------------------------
-- Tekshiruv
-- ----------------------------------------------------------------------------
-- SHOW CREATE TABLE `tb_factory_signature_logs`;
--
-- Ko'chirilmay qolgan HAMMA qator (norm_attempts bo'yicha FILTRLAMAYMIZ — aks holda
-- 20 urinishdan oshgan, ya'ni ABADIY tashlab ketilgan qatorlar ko'rinmay qoladi):
-- SELECT id, uid, norm_status, norm_attempts, norm_error, created_at
--   FROM tb_factory_signature_logs
--  WHERE norm_status = 2
--  ORDER BY id;
--
-- Sweeper endi olmaydigan (norm_attempts >= 20) qatorlar — QO'LDA tiklash kerak:
-- SELECT COUNT(*) FROM tb_factory_signature_logs WHERE norm_status = 2 AND norm_attempts >= 20;
-- UPDATE tb_factory_signature_logs SET norm_attempts = 0, norm_error = NULL WHERE norm_status = 2;
--
-- DOIMIY RAD ETILGAN (norm_status = 4) — sweeper bularni HECH QACHON olmaydi, 1C ga 422
-- qaytarilgan. Bu qatorlar payloadning O'ZI xato ekanini bildiradi (uid yo'q, ustun uzun,
-- son emas, jadval/ustun yo'q ...). norm_error da sabab yozilgan:
-- SELECT id, uid, norm_error, created_at FROM tb_factory_signature_logs
--  WHERE norm_status = 4 ORDER BY id DESC;
-- (Agar sabab BIZNING tomonimizda edi — masalan brrgz ustuni yo'q edi — tuzatgandan keyin
--  qatorni qo'lda qayta navbatga qo'ying: UPDATE ... SET norm_status = 2, norm_attempts = 0 WHERE id = ?;)
