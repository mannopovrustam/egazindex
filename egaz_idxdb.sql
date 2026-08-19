-- Adminer 4.8.1 MySQL 8.0.42-0ubuntu0.20.04.1 dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DELIMITER ;;

DROP PROCEDURE IF EXISTS `accept_invoices_GNS`;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `accept_invoices_GNS`()
BEGIN

update  tb_fc_invoices as f 
    set f.accepted_at = f.created_at, f.qty_accepted = f.qty_output,
    f.id_rzvr = (select r.id from tb_rezervuars as r where r.id_org=f.id_to limit 1),
    f.	accepted_by = (select u.id from cms_users as u where u.id_org=f.id_to and u.id_cms_privileges in (10,13) limit 1)
  where f.id_rzvr is null;
END;;

DROP PROCEDURE IF EXISTS `clearAndDeleteDublicates`;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `clearAndDeleteDublicates`()
BEGIN
truncate table tmp_ids;
insert into tmp_ids (idt, kod) select max(id) as id, kod from dublicate_abonents group by kod;

delete from cms_users where id in (select idt from tmp_ids);
END;;

DROP PROCEDURE IF EXISTS `deleteDublStatistics`;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `deleteDublStatistics`()
BEGIN

with cte as (
SELECT id,ROW_NUMBER() OVER (PARTITION BY id_region, id_org,name ORDER BY id_region) AS row_num 
FROM tb_statistics) delete from tb_statistics where id in (select id from cte where row_num > 1);

END;;

DROP PROCEDURE IF EXISTS `FixGNS_ORGS`;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `FixGNS_ORGS`()
BEGIN

update tb_requests as r 
join cms_users as u on u.id = r.created_by set id_gns = u.id_org 
where r.id_gns != u.id_org;

END;;

DELIMITER ;

DROP TABLE IF EXISTS `a_user_relations`;
CREATE TABLE `a_user_relations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `mip_id` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `id_abonent` int NOT NULL,
  `pinfl` varchar(14) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `name` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `dbirth` date DEFAULT NULL,
  `sex` int DEFAULT NULL,
  `reg_date` date DEFAULT NULL,
  `status` int DEFAULT NULL,
  `photo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `valid_date` date DEFAULT NULL,
  `entry_by` int unsigned DEFAULT NULL,
  `id_region` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_abonent_pinfl` (`id_abonent`,`pinfl`),
  KEY `id_abonent` (`id_abonent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;


DROP TABLE IF EXISTS `a_users`;
CREATE TABLE `a_users` (
  `id` int unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'NO NAME',
  `status` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `id_org` int unsigned DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '',
  `kod` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `psp` varchar(160) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `deposit` decimal(12,2) DEFAULT '0.00',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `id_mahalla` int unsigned DEFAULT NULL,
  `id_bln_hst` int unsigned DEFAULT NULL,
  `gb_allowed_qty` smallint unsigned NOT NULL DEFAULT '1',
  `dt_creation` date DEFAULT NULL,
  `last_paid` date DEFAULT NULL,
  `last_amount` decimal(12,2) DEFAULT '0.00',
  `phone` varchar(9) DEFAULT NULL,
  `code` varchar(6) DEFAULT NULL,
  `tp` enum('AB','GG','SGB','MV','MTM','QD','ORM','OX','BOJ','MAK','SOC','OTH') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'AB',
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `kod_idxx` (`kod`),
  KEY `id_org` (`id_org`),
  KEY `id_mahalla` (`id_mahalla`),
  CONSTRAINT `a_users_ibfk_1` FOREIGN KEY (`id_mahalla`) REFERENCES `mahallas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC COMMENT='CURRENT_TIMESTAMP';


DROP TABLE IF EXISTS `citizens`;
CREATE TABLE `citizens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ru` varchar(200) NOT NULL,
  `en` varchar(200) NOT NULL,
  `kod` varchar(4) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `SP_IDN` (`kod`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `cms_users`;
CREATE TABLE `cms_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `password_dt` date DEFAULT NULL,
  `id_cms_privileges` int unsigned DEFAULT NULL,
  `mobile` varchar(60) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `dtbirth` date DEFAULT NULL,
  `lastlogin` timestamp NULL DEFAULT NULL,
  `sex` char(1) DEFAULT '1',
  `id_org` int unsigned DEFAULT NULL,
  `id_region` int unsigned NOT NULL,
  `id_district` int unsigned DEFAULT NULL,
  `id_mahalla` int unsigned DEFAULT NULL,
  `contract_num` varchar(20) DEFAULT '',
  `contract_dt` date DEFAULT NULL,
  `contract_filled_by` int unsigned DEFAULT NULL,
  `is_yurid` tinyint unsigned NOT NULL DEFAULT '2' COMMENT '1-yurid, 2-phys',
  `level` smallint DEFAULT '1' COMMENT '1-physical;100-Local;200-Regional;300-Republican;400-Operator Kabus;9000-Developer',
  `mahalla` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT '',
  `kadastr` varchar(255) DEFAULT NULL,
  `kadastr_owner` varchar(255) DEFAULT NULL,
  `kod` varchar(11) NOT NULL COMMENT 'RR-DDD-LC',
  `psp` varchar(60) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `pinfl` varchar(60) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '',
  `qty_family` smallint unsigned NOT NULL DEFAULT '1',
  `qty_lives` smallint unsigned NOT NULL DEFAULT '1',
  `home_type` enum('1','2','3') NOT NULL DEFAULT '3',
  `entry_by` int unsigned DEFAULT NULL,
  `deposit` decimal(15,2) DEFAULT '0.00',
  `trgflag` enum('1','2') DEFAULT '1',
  `rdt` date DEFAULT NULL,
  `next_rdt` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `sync_st` enum('0','1') DEFAULT '1',
  `insp` varchar(220) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL COMMENT 'last realized inspector name',
  `avai_qty` int unsigned NOT NULL DEFAULT '1',
  `tp` enum('AB','GG','SGB','MV','MTM','QD','ORM','OX','BOJ','MAK','SOC','OTH') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'AB',
  `last_req` int unsigned DEFAULT NULL,
  `is_need` smallint DEFAULT '1',
  `verified` tinyint(1) DEFAULT '0',
  `verify_kod` varchar(255) DEFAULT NULL,
  `is_nfc` enum('1','2') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '2',
  `auth_token` varchar(60) DEFAULT NULL,
  `is_banned` int DEFAULT NULL,
  `eimzo_key` varchar(255) DEFAULT NULL,
  `pg_type` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`,`id_region`,`kod`),
  KEY `ms_privileges` (`id_cms_privileges`) USING BTREE,
  KEY `kod` (`kod`) USING BTREE,
  KEY `id_org` (`id_org`) USING BTREE,
  KEY `email` (`email`),
  KEY `rdt` (`rdt`),
  KEY `id_mahalla` (`id_mahalla`),
  KEY `pinfl` (`pinfl`),
  KEY `idx_auth_token` (`auth_token`),
  KEY `idx_name_pinfl` (`name`,`pinfl`),
  KEY `kadastr_idx` (`kadastr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC
/*!50100 PARTITION BY LIST (`id_region`)
(PARTITION p0 VALUES IN (1) ENGINE = InnoDB,
 PARTITION p1 VALUES IN (2) ENGINE = InnoDB,
 PARTITION p10 VALUES IN (11) ENGINE = InnoDB,
 PARTITION p11 VALUES IN (12) ENGINE = InnoDB,
 PARTITION p12 VALUES IN (13) ENGINE = InnoDB,
 PARTITION p13 VALUES IN (14) ENGINE = InnoDB,
 PARTITION p14 VALUES IN (15) ENGINE = InnoDB,
 PARTITION p2 VALUES IN (3) ENGINE = InnoDB,
 PARTITION p3 VALUES IN (4) ENGINE = InnoDB,
 PARTITION p4 VALUES IN (5) ENGINE = InnoDB,
 PARTITION p5 VALUES IN (6) ENGINE = InnoDB,
 PARTITION p6 VALUES IN (7) ENGINE = InnoDB,
 PARTITION p7 VALUES IN (8) ENGINE = InnoDB,
 PARTITION p8 VALUES IN (9) ENGINE = InnoDB,
 PARTITION p9 VALUES IN (10) ENGINE = InnoDB) */;


DELIMITER ;;

CREATE TRIGGER `setIDkodUser` BEFORE INSERT ON `cms_users` FOR EACH ROW
BEGIN

	DECLARE user_id BIGINT UNSIGNED DEFAULT NULL;
	DECLARE kodd VARCHAR(11);
        IF NEW.kod is null OR NEW.kod = '' THEN 
         BEGIN    
       
	IF NEW.id_cms_privileges = 4  THEN
		BEGIN 
			SET NEW.sync_st = '1';
			SET user_id := (SELECT MAX(CONVERT(kod, UNSIGNED INTEGER))+1 FROM `cms_users` WHERE id_region=NEW.id_region AND id_cms_privileges=4);
			IF user_id IS NULL THEN 
					SET kodd := CONCAT(LPAD(NEW.id_region,2,'0'),LPAD(1,9,'0')); 
				ELSE
					SET kodd := LPAD(CAST(user_id AS CHAR(11)),11,'0');
			END IF;
				SET NEW.email = CONCAT(kodd,'@egaz.uz');

		END;
	ELSE
		BEGIN
				
                
		END;		
	END IF;
	SET NEW.kod = kodd;
       END;
       END IF;

END;;

CREATE TRIGGER `incUsers` AFTER INSERT ON `cms_users` FOR EACH ROW
BEGIN 
	IF NEW.id_cms_privileges = 4 THEN
		insert cms_sync_users (kod,flag) values (NEW.kod,'C');
                insert ignore into a_users (id,name,status,id_org,address,kod,psp,deposit,id_mahalla,dt_creation)
                   values(NEW.id, NEW.name, NEW.status,NEW.id_org, NEW.address,NEW.kod, NEW.psp, NEW.deposit, NEW.id_mahalla, CURRENT_DATE);
                      
	END IF;


UPDATE tb_statistics SET qty = qty+1 WHERE `name`='users' and id_region = 15;

IF NEW.id_cms_privileges = 4 THEN
	UPDATE tb_statistics SET qty = qty+1 WHERE `name`='subscribers' and id_region = 15;
	IF NEW.status = 'Active' THEN
		UPDATE tb_statistics SET qty = qty+1 WHERE `name`='subscribers_active' and id_region = 15;
	ELSE
		UPDATE tb_statistics SET qty = qty+1 WHERE `name`='subscribers_non_active' and id_region = 15;
	END IF;
END IF;
IF (NEW.id_region != 15) THEN
	UPDATE tb_statistics SET qty = qty+1 WHERE `name`='users' and id_region = NEW.id_region;
	IF NEW.id_cms_privileges = 4 THEN
		UPDATE tb_statistics SET qty = qty+1 WHERE `name`='subscribers' and id_region = NEW.id_region and id_org = NEW.id_org;
		IF NEW.status = 'Active' THEN
			UPDATE tb_statistics SET qty = qty+1 WHERE `name`='subscribers_active' and id_region = NEW.id_region and id_org = NEW.id_org;
		ELSE
			UPDATE tb_statistics SET qty = qty+1 WHERE `name`='subscribers_non_active' and id_region = NEW.id_region and id_org = NEW.id_org;
		END IF;
	END IF;
END IF;

END;;

CREATE TRIGGER `updateKod` BEFORE UPDATE ON `cms_users` FOR EACH ROW
BEGIN

END;;

CREATE TRIGGER `updSubscrbStatus` AFTER UPDATE ON `cms_users` FOR EACH ROW
BEGIN

DECLARE org INT;

IF NEW.id_org IS NOT NULL THEN 
	SET org := NEW.id_org;
ELSE
	SET org := OLD.id_org;
END IF;

IF NEW.status != OLD.status THEN 
	IF NEW.status = 'Active' AND OLD.status != 'Active' THEN
		UPDATE tb_statistics SET qty = qty+1 WHERE `name`='subscribers_active' and id_org = org;
		UPDATE tb_statistics SET qty = qty-1 WHERE `name`='subscribers_non_active' and id_org = org;
		UPDATE tb_statistics SET qty = qty+1 WHERE `name`='subscribers_active' and id_region = 15;
		UPDATE tb_statistics SET qty = qty-1 WHERE `name`='subscribers_non_active' and id_region = 15;

	ELSE
		UPDATE tb_statistics SET qty = qty-1 WHERE `name`='subscribers_active' and id_org = org;
		UPDATE tb_statistics SET qty = qty+1 WHERE `name`='subscribers_non_active' and id_org = org;
		UPDATE tb_statistics SET qty = qty-1 WHERE `name`='subscribers_active' and id_region = 15;
		UPDATE tb_statistics SET qty = qty+1 WHERE `name`='subscribers_non_active' and id_region = 15;
	END IF;
END IF;

IF IFNULL(NEW.id_cms_privileges,OLD.id_cms_privileges) = 4 THEN
	
        update a_users SET name=IFNULL(NEW.name,OLD.name) ,status=IFNULL(NEW.status,OLD.status),id_org=IFNULL(NEW.id_org,OLD.id_org),
              address=IFNULL(NEW.address,OLD.address),psp=IFNULL(NEW.psp,OLD.psp),deposit=IFNULL(NEW.deposit,OLD.deposit),
              id_mahalla=IFNULL(NEW.id_mahalla,OLD.id_mahalla) where id = IFNULL(NEW.id,OLD.id);
END IF;

END;;

CREATE TRIGGER `decUSers` AFTER DELETE ON `cms_users` FOR EACH ROW
BEGIN 

	


UPDATE tb_statistics SET qty = qty-1 WHERE `name`='users' and id_region = 15;

IF OLD.id_cms_privileges = 4 THEN
	 UPDATE tb_statistics SET qty = qty-1 WHERE `name`='subscribers' and id_region = 15;
		IF OLD.status = 'Active' THEN
			UPDATE tb_statistics SET qty = qty-1 WHERE `name`='subscribers_active' and id_region = 15;
		ELSE
			UPDATE tb_statistics SET qty = qty-1 WHERE `name`='subscribers_non_active' and id_region = 15;
		END IF;
END IF;

IF (OLD.id_region != 15) THEN
	UPDATE tb_statistics SET qty = qty-1 WHERE `name`='users' and id_region = OLD.id_region;
	IF OLD.id_cms_privileges = 4 THEN
		UPDATE tb_statistics SET qty = qty-1 WHERE `name`='subscribers' and id_region = OLD.id_region AND id_org = OLD.id_org;
		IF OLD.status = 'Active' THEN
			UPDATE tb_statistics SET qty = qty-1 WHERE `name`='subscribers_active' AND id_region = OLD.id_region AND id_org = OLD.id_org;
		ELSE
			UPDATE tb_statistics SET qty = qty-1 WHERE `name`='subscribers_non_active' AND id_region = OLD.id_region AND id_org = OLD.id_org;
		END IF;
	END IF;
END IF;

END;;

DELIMITER ;

DROP TABLE IF EXISTS `districts`;
CREATE TABLE `districts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_region` smallint unsigned NOT NULL,
  `name_en` varchar(30) NOT NULL,
  `name_uz` varchar(30) NOT NULL,
  `name_ru` varchar(30) DEFAULT NULL,
  `location_lat` varchar(30) DEFAULT NULL,
  `location_lon` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


SET NAMES utf8mb4;

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `forecast_mahallas`;
CREATE TABLE `forecast_mahallas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dt` date NOT NULL,
  `id_region` int unsigned NOT NULL,
  `id_org` int unsigned NOT NULL,
  `id_mahalla` int unsigned DEFAULT NULL,
  `abon_qty` int unsigned DEFAULT NULL,
  `got_last30` int unsigned DEFAULT NULL,
  `not_got` int unsigned DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `forecast_orgs`;
CREATE TABLE `forecast_orgs` (
  `dt` date NOT NULL,
  `id_region` int unsigned NOT NULL,
  `id_org` int unsigned NOT NULL,
  `abon_qty` int unsigned DEFAULT NULL,
  `got_last30` int unsigned DEFAULT NULL,
  `not_got` int unsigned DEFAULT '0',
  PRIMARY KEY (`dt`,`id_region`,`id_org`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `i_abonent_details`;
CREATE TABLE `i_abonent_details` (
  `id_org` smallint DEFAULT NULL,
  `id_mah` int DEFAULT NULL,
  `abonent_kod` varchar(11) DEFAULT NULL,
  `abonent_name` varchar(255) DEFAULT NULL,
  `dt_creation` date DEFAULT NULL,
  `status` tinyint(1) DEFAULT '1',
  KEY `idx_mah` (`id_mah`),
  KEY `idx_org` (`id_org`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DELIMITER ;;

CREATE TRIGGER `i_abonent_details_ai` AFTER INSERT ON `i_abonent_details` FOR EACH ROW
if new.status = true then
        insert into i_abonent_orgs (id_reg, id_org, id_mah, all_users, active_users)
        values ((SELECT id_region FROM organizations o where o.id = new.id_org), new.id_org, new.id_mah, 1, 1)
        on duplicate key update all_users = all_users + 1, active_users = active_users + 1;
    else
        insert into i_abonent_orgs (id_reg, id_org, id_mah, all_users, active_users)
        values ((SELECT id_region FROM organizations o where o.id = new.id_org), new.id_org, new.id_mah, 1, 0)
        on duplicate key update all_users = all_users + 1;
    end if;;

CREATE TRIGGER `i_abonent_details_au` AFTER UPDATE ON `i_abonent_details` FOR EACH ROW
if new.status = true then
        insert into i_abonent_orgs (id_reg, id_org, id_mah, all_users, active_users)
        values ((SELECT id_region FROM organizations o where o.id = NEW.id_org), new.id_org, new.id_mah, 1, 1)
        on duplicate key update all_users = all_users + 1, active_users = active_users + 1;
    else
        insert into i_abonent_orgs (id_reg, id_org, id_mah, all_users, active_users)
        values ((SELECT id_region FROM organizations o where o.id = NEW.id_org), new.id_org, new.id_mah, 1, 0)
        on duplicate key update all_users = all_users + 1;
    end if;;

DELIMITER ;

DROP TABLE IF EXISTS `i_abonent_orgs`;
CREATE TABLE `i_abonent_orgs` (
  `id_reg` smallint DEFAULT NULL,
  `id_org` smallint NOT NULL,
  `id_mah` int NOT NULL,
  `all_users` int DEFAULT NULL,
  `active_users` int DEFAULT NULL,
  PRIMARY KEY (`id_org`,`id_mah`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `i_balance`;
CREATE TABLE `i_balance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_reg` int DEFAULT NULL,
  `region` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `id_org` int DEFAULT NULL,
  `id_orgtype` int DEFAULT NULL,
  `hgt` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `zavod_qabul` decimal(10,2) DEFAULT NULL,
  `transit_chiqdi` decimal(10,2) DEFAULT NULL,
  `transit_qabul` decimal(10,2) DEFAULT NULL,
  `rgs_chiqdi` decimal(10,2) DEFAULT NULL,
  `yoqotish_rgs` decimal(10,2) DEFAULT '0.00',
  `rgs_oldi` decimal(10,2) DEFAULT NULL,
  `aholiga` decimal(10,2) DEFAULT NULL,
  `qoldiq` decimal(10,2) DEFAULT NULL,
  `dt` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_org_dt` (`id_org`,`dt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;


DROP TABLE IF EXISTS `i_deposit_details`;
CREATE TABLE `i_deposit_details` (
  `mm` smallint unsigned NOT NULL,
  `yy` smallint unsigned NOT NULL,
  `id_org` smallint unsigned DEFAULT NULL,
  `id_mah` int unsigned DEFAULT NULL,
  `abonent_kod` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `abonent_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `deposit` decimal(12,2) DEFAULT '0.00',
  `kg` decimal(12,2) unsigned DEFAULT '20.00' COMMENT '1kg = 1120',
  `amount` decimal(15,2) DEFAULT '0.00',
  `real_amount` decimal(15,2) unsigned DEFAULT '0.00' COMMENT 'kg * 1120',
  PRIMARY KEY (`mm`,`yy`,`abonent_kod`) USING BTREE,
  KEY `maxidx` (`id_mah`) USING BTREE,
  KEY `yymm` (`yy`,`mm`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC
/*!50100 PARTITION BY RANGE (`yy`)
(PARTITION p_deposit2020 VALUES LESS THAN (2020) ENGINE = InnoDB,
 PARTITION p_deposit2021 VALUES LESS THAN (2021) ENGINE = InnoDB,
 PARTITION p_deposit2022 VALUES LESS THAN (2022) ENGINE = InnoDB,
 PARTITION p_deposit2023 VALUES LESS THAN (2023) ENGINE = InnoDB,
 PARTITION p_deposit2024 VALUES LESS THAN (2024) ENGINE = InnoDB,
 PARTITION p_deposit2025 VALUES LESS THAN (2025) ENGINE = InnoDB,
 PARTITION p_deposit2026 VALUES LESS THAN (2026) ENGINE = InnoDB,
 PARTITION p_deposit2027 VALUES LESS THAN (2027) ENGINE = InnoDB,
 PARTITION p_deposit2028 VALUES LESS THAN (2028) ENGINE = InnoDB,
 PARTITION p_deposit2029 VALUES LESS THAN (2029) ENGINE = InnoDB,
 PARTITION p_deposit2030 VALUES LESS THAN (2030) ENGINE = InnoDB,
 PARTITION p_deposit203MAX VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;


DELIMITER ;;

CREATE TRIGGER `insert_i_deposit_details` AFTER INSERT ON `i_deposit_details` FOR EACH ROW
BEGIN
    insert into i_deposit_orgs (mm, yy, id_org, id_mah, id_reg, deposit, kg, amount, real_amount) values (NEW.mm, NEW.yy, NEW.id_org, NEW.id_mah, (SELECT id_region FROM organizations o where o.id = NEW.id_org), NEW.deposit,NEW.kg, NEW.amount, NEW.real_amount) ON DUPLICATE KEY UPDATE deposit=deposit + NEW.deposit, kg=kg + NEW.kg, amount=amount + NEW.amount, real_amount=real_amount + NEW.real_amount;
END;;

DELIMITER ;

DROP TABLE IF EXISTS `i_deposit_orgs`;
CREATE TABLE `i_deposit_orgs` (
  `mm` smallint unsigned NOT NULL,
  `yy` smallint unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `id_mah` int unsigned NOT NULL,
  `id_reg` smallint NOT NULL,
  `deposit` decimal(12,2) DEFAULT '0.00',
  `kg` decimal(12,2) unsigned DEFAULT '20.00' COMMENT '1kg = 1120',
  `amount` decimal(15,2) DEFAULT '0.00',
  `real_amount` decimal(15,2) unsigned DEFAULT '0.00' COMMENT 'kg * 1120',
  PRIMARY KEY (`mm`,`yy`,`id_org`,`id_mah`) USING BTREE,
  KEY `orgs` (`id_org`) USING BTREE,
  KEY `region` (`id_reg`) USING BTREE,
  KEY `yymm` (`yy`,`mm`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC
/*!50100 PARTITION BY RANGE (`yy`)
(PARTITION p_deposit2020 VALUES LESS THAN (2020) ENGINE = InnoDB,
 PARTITION p_deposit2021 VALUES LESS THAN (2021) ENGINE = InnoDB,
 PARTITION p_deposit2022 VALUES LESS THAN (2022) ENGINE = InnoDB,
 PARTITION p_deposit2023 VALUES LESS THAN (2023) ENGINE = InnoDB,
 PARTITION p_deposit2024 VALUES LESS THAN (2024) ENGINE = InnoDB,
 PARTITION p_deposit2025 VALUES LESS THAN (2025) ENGINE = InnoDB,
 PARTITION p_deposit2026 VALUES LESS THAN (2026) ENGINE = InnoDB,
 PARTITION p_deposit2027 VALUES LESS THAN (2027) ENGINE = InnoDB,
 PARTITION p_deposit2028 VALUES LESS THAN (2028) ENGINE = InnoDB,
 PARTITION p_deposit2029 VALUES LESS THAN (2029) ENGINE = InnoDB,
 PARTITION p_deposit2030 VALUES LESS THAN (2030) ENGINE = InnoDB,
 PARTITION p_deposit203MAX VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;


DROP TABLE IF EXISTS `i_face_id_detail`;
CREATE TABLE `i_face_id_detail` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `id_region` int DEFAULT NULL,
  `id_district` int DEFAULT NULL,
  `id_org` int DEFAULT NULL,
  `id_mahalla` int DEFAULT NULL,
  `id_request` int DEFAULT NULL,
  `id_request_dt` date DEFAULT NULL,
  `id_request_ballon` int DEFAULT NULL,
  `id_request_passed` int DEFAULT NULL,
  `id_abonent` int DEFAULT NULL,
  `abonent_kod` varchar(11) DEFAULT NULL,
  `result` varchar(80) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
  `psp` varchar(9) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `dbirth` varchar(50) DEFAULT NULL,
  `pinfl` char(14) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `cadastre` varchar(100) DEFAULT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `photo` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
  `photo_file` varchar(150) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `created_dt` date DEFAULT (curdate()),
  PRIMARY KEY (`id`),
  KEY `i_face_id_detail_id_request_ballon_index` (`id_request_ballon`),
  KEY `id_abonent` (`id_abonent`),
  KEY `pinfl` (`pinfl`),
  KEY `created_dt` (`created_dt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `i_face_id_payload`;
CREATE TABLE `i_face_id_payload` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_request_ballon` int DEFAULT NULL,
  `request` longtext,
  `payload` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `i_face_id_recipients`;
CREATE TABLE `i_face_id_recipients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_detail` int DEFAULT NULL,
  `id_abonent` int DEFAULT NULL,
  `kod` varchar(11) DEFAULT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `kadastr` varchar(255) DEFAULT NULL,
  `kadastr_owner` varchar(255) DEFAULT NULL,
  `pinfl` varchar(14) DEFAULT NULL,
  `passport` varchar(25) DEFAULT NULL,
  `dbirth` date DEFAULT NULL,
  `district_coato_id` int DEFAULT NULL,
  `kadastr_coato_id` int DEFAULT NULL,
  `is_synch` tinyint(1) DEFAULT NULL,
  `sync_tabiiy` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_abonent` (`id_abonent`),
  KEY `pinfl` (`pinfl`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `i_face_id_relations`;
CREATE TABLE `i_face_id_relations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `mip_id` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `id_detail` int NOT NULL,
  `id_abonent` int NOT NULL,
  `kadastr` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `pinfl` varchar(14) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `name` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `dbirth` date DEFAULT NULL,
  `sex` int DEFAULT NULL,
  `reg_date` date DEFAULT NULL,
  `status` int DEFAULT NULL,
  `photo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `valid_date` date DEFAULT NULL,
  `entry_by` int unsigned DEFAULT NULL,
  `is_synch` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_abonent_pinfl` (`id_abonent`,`pinfl`),
  KEY `id_abonent` (`id_abonent`),
  KEY `pinfl` (`pinfl`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;


DROP TABLE IF EXISTS `i_hour_realize`;
CREATE TABLE `i_hour_realize` (
  `id_region` int NOT NULL,
  `real_date` date NOT NULL,
  `org_qty` int DEFAULT '0',
  `req_qty` int DEFAULT '0',
  `qty_6` int DEFAULT '0',
  `price_6` decimal(16,2) DEFAULT '0.00',
  `qty_7` int DEFAULT '0',
  `price_7` decimal(16,2) DEFAULT '0.00',
  `qty_8` int DEFAULT '0',
  `price_8` decimal(16,2) DEFAULT '0.00',
  `qty_9` int DEFAULT '0',
  `price_9` decimal(16,2) DEFAULT '0.00',
  `qty_10` int DEFAULT '0',
  `price_10` decimal(16,2) DEFAULT '0.00',
  `qty_11` int DEFAULT '0',
  `price_11` decimal(16,2) DEFAULT '0.00',
  `qty_12` int DEFAULT '0',
  `price_12` decimal(16,2) DEFAULT '0.00',
  `qty_13` int DEFAULT '0',
  `price_13` decimal(16,2) DEFAULT '0.00',
  `qty_14` int DEFAULT '0',
  `price_14` decimal(16,2) DEFAULT '0.00',
  `qty_15` int DEFAULT '0',
  `price_15` decimal(16,2) DEFAULT '0.00',
  `qty_16` int DEFAULT '0',
  `price_16` decimal(16,2) DEFAULT '0.00',
  `qty_17` int DEFAULT '0',
  `price_17` decimal(16,2) DEFAULT '0.00',
  `qty_18` int DEFAULT '0',
  `price_18` decimal(16,2) DEFAULT '0.00',
  `qty_19` int DEFAULT '0',
  `price_19` decimal(16,2) DEFAULT '0.00',
  `qty_20` int DEFAULT '0',
  `price_20` decimal(16,2) DEFAULT '0.00',
  `qty_21` int DEFAULT '0',
  `price_21` decimal(16,2) DEFAULT '0.00',
  `qty_22` int DEFAULT '0',
  `price_22` decimal(16,2) DEFAULT '0.00',
  `pass_qty` int DEFAULT '0',
  `pass_price` decimal(16,2) DEFAULT '0.00',
  PRIMARY KEY (`id_region`,`real_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `i_hour_realize_detail`;
CREATE TABLE `i_hour_realize_detail` (
  `id_region` int NOT NULL,
  `id_org` int NOT NULL,
  `org_name` varchar(255) NOT NULL,
  `inspector_id` varchar(11) NOT NULL DEFAULT '0',
  `inspector_name` varchar(255) DEFAULT '0',
  `real_date` date NOT NULL,
  `req_qty` int DEFAULT '0',
  `qty_6` int DEFAULT '0',
  `price_6` decimal(16,2) DEFAULT NULL,
  `qty_7` int DEFAULT '0',
  `price_7` decimal(16,2) DEFAULT NULL,
  `qty_8` int DEFAULT '0',
  `price_8` decimal(16,2) DEFAULT NULL,
  `qty_9` int DEFAULT '0',
  `price_9` decimal(16,2) DEFAULT NULL,
  `qty_10` int DEFAULT '0',
  `price_10` decimal(16,2) DEFAULT NULL,
  `qty_11` int DEFAULT '0',
  `price_11` decimal(16,2) DEFAULT NULL,
  `qty_12` int DEFAULT '0',
  `price_12` decimal(16,2) DEFAULT NULL,
  `qty_13` int DEFAULT '0',
  `price_13` decimal(16,2) DEFAULT NULL,
  `qty_14` int DEFAULT '0',
  `price_14` decimal(16,2) DEFAULT NULL,
  `qty_15` int DEFAULT '0',
  `price_15` decimal(16,2) DEFAULT NULL,
  `qty_16` int DEFAULT '0',
  `price_16` decimal(16,2) DEFAULT NULL,
  `qty_17` int DEFAULT '0',
  `price_17` decimal(16,2) DEFAULT NULL,
  `qty_18` int DEFAULT '0',
  `price_18` decimal(16,2) DEFAULT NULL,
  `qty_19` int DEFAULT '0',
  `price_19` decimal(16,2) DEFAULT NULL,
  `qty_20` int DEFAULT '0',
  `price_20` decimal(16,2) DEFAULT NULL,
  `qty_21` int DEFAULT '0',
  `price_21` decimal(16,2) DEFAULT NULL,
  `qty_22` int DEFAULT '0',
  `price_22` decimal(16,2) DEFAULT NULL,
  PRIMARY KEY (`inspector_id`,`real_date`),
  KEY `i_hour_realize_detail_id_org_real_date_index` (`id_org`,`real_date`),
  KEY `i_hour_realize_detail_id_region_index` (`id_region`,`real_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `i_money_cancelled`;
CREATE TABLE `i_money_cancelled` (
  `sys_bid` varchar(20) NOT NULL,
  `provider` enum('PAYNET','PAYME','CLICK','MUNIS','HGT','APELSIN') NOT NULL,
  `dt` date NOT NULL,
  `id_org` int unsigned NOT NULL,
  `dtm` datetime NOT NULL,
  `reason` text NOT NULL,
  `abon_kod` varchar(11) NOT NULL,
  `attach` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `entry_by` int unsigned DEFAULT NULL,
  KEY `org_idx` (`dt`,`id_org`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `i_money_details`;
CREATE TABLE `i_money_details` (
  `dt` date NOT NULL,
  `yy` int unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `provider` enum('PAYNET','PAYME','CLICK','MUNIS','HGT','APELSIN') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `paid_at` timestamp NOT NULL,
  `sys_bid` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `kod` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `psp` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `id_mah` int unsigned DEFAULT NULL,
  `mobile` varchar(120) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '0',
  `address` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '0',
  `deposit` decimal(12,2) DEFAULT '0.00',
  `payer_branch` varchar(5) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `payer_account` varchar(24) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `payer_name` varchar(500) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `payer_inn` varchar(14) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  PRIMARY KEY (`dt`,`yy`,`sys_bid`) USING BTREE,
  KEY `idx_provider` (`provider`) USING BTREE,
  KEY `id_org_id_mah` (`id_org`,`id_mah`),
  KEY `dt` (`dt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC
/*!50100 PARTITION BY RANGE (`yy`)
(PARTITION payments2019 VALUES LESS THAN (2019) ENGINE = InnoDB,
 PARTITION payments2020 VALUES LESS THAN (2020) ENGINE = InnoDB,
 PARTITION payments2021 VALUES LESS THAN (2021) ENGINE = InnoDB,
 PARTITION payments2023 VALUES LESS THAN (2023) ENGINE = InnoDB,
 PARTITION payments2024 VALUES LESS THAN (2024) ENGINE = InnoDB,
 PARTITION payments2025 VALUES LESS THAN (2025) ENGINE = InnoDB,
 PARTITION payments2026 VALUES LESS THAN (2026) ENGINE = InnoDB,
 PARTITION payments2027 VALUES LESS THAN (2027) ENGINE = InnoDB,
 PARTITION payments2028 VALUES LESS THAN (2028) ENGINE = InnoDB,
 PARTITION payments2029 VALUES LESS THAN (2029) ENGINE = InnoDB,
 PARTITION payments2030 VALUES LESS THAN (2030) ENGINE = InnoDB,
 PARTITION pMax VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;


DELIMITER ;;

CREATE TRIGGER `plus_deposit_org` AFTER INSERT ON `i_money_details` FOR EACH ROW
BEGIN
 UPDATE organizations set deposit = deposit + NEW.amount where id = NEW.id_org;
END;;

CREATE TRIGGER `i_money_details_orgs` AFTER DELETE ON `i_money_details` FOR EACH ROW
BEGIN
/*
    IF OLD.provider = 'PAYNET' THEN
      UPDATE i_money_orgs SET amount_paynet=amount_paynet-OLD.amount, deposit = deposit - OLD.deposit, qty_paynet=qty_paynet-1 WHERE dt=OLD.dt AND yy=OLD.yy AND id_mah=OLD.id_mah AND id_org=OLD.id_org;
    END IF;
    IF OLD.provider = 'PAYME' THEN
      UPDATE i_money_orgs SET amount_payme=amount_payme-OLD.amount, deposit = deposit - OLD.deposit, qty_payme=qty_payme-1 WHERE dt=OLD.dt AND yy=OLD.yy AND id_mah=OLD.id_mah AND id_org=OLD.id_org;
    END IF;
    IF OLD.provider = 'CLICK' THEN
      UPDATE i_money_orgs SET amount_click=amount_click-OLD.amount, deposit = deposit - OLD.deposit, qty_click=qty_click-1 WHERE dt=OLD.dt AND yy=OLD.yy AND id_mah=OLD.id_mah AND id_org=OLD.id_org;
    END IF;
    IF OLD.provider = 'MUNIS' THEN
      UPDATE i_money_orgs SET amount_munis=amount_munis-OLD.amount, deposit = deposit - OLD.deposit, qty_munis=qty_munis-1 WHERE dt=OLD.dt AND yy=OLD.yy AND id_mah=OLD.id_mah AND id_org=OLD.id_org;
    END IF;
    IF OLD.provider = 'APELSIN' THEN
      UPDATE i_money_orgs SET amount_apelsin=amount_apelsin-OLD.amount, deposit = deposit - OLD.deposit, qty_apelsin=qty_apelsin-1 WHERE dt=OLD.dt AND yy=OLD.yy AND id_mah=OLD.id_mah AND id_org=OLD.id_org;
    END IF;
    IF OLD.provider = 'HGT' THEN
      UPDATE i_money_orgs SET amount_hgt=amount_hgt-OLD.amount, deposit = deposit - OLD.deposit, qty_hgt=qty_hgt-1 WHERE dt=OLD.dt AND yy=OLD.yy AND id_mah=OLD.id_mah AND id_org=OLD.id_org;
    END IF;
*/
  END;;

DELIMITER ;

DROP TABLE IF EXISTS `i_money_failed`;
CREATE TABLE `i_money_failed` (
  `id_tran` bigint unsigned NOT NULL,
  `id_abonent` int unsigned DEFAULT NULL,
  `kod` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `id_org` int unsigned DEFAULT NULL,
  `err` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
  PRIMARY KEY (`id_tran`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `i_money_orgs`;
CREATE TABLE `i_money_orgs` (
  `dt` date NOT NULL,
  `yy` int unsigned NOT NULL,
  `mm` smallint unsigned DEFAULT NULL,
  `id_reg` smallint unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `provider` enum('PAYNET','PAYME','CLICK','MUNIS','HGT','APELSIN') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `id_mah` int unsigned NOT NULL DEFAULT '8740',
  `deposit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `amount_paynet` decimal(15,2) NOT NULL DEFAULT '0.00',
  `amount_click` decimal(15,2) NOT NULL DEFAULT '0.00',
  `amount_payme` decimal(15,2) NOT NULL DEFAULT '0.00',
  `amount_munis` decimal(15,2) NOT NULL DEFAULT '0.00',
  `amount_apelsin` decimal(15,2) NOT NULL DEFAULT '0.00',
  `amount_hgt` decimal(15,2) NOT NULL DEFAULT '0.00',
  `qty_paynet` int unsigned NOT NULL DEFAULT '0',
  `qty_click` int unsigned NOT NULL DEFAULT '0',
  `qty_munis` int unsigned NOT NULL DEFAULT '0',
  `qty_payme` int unsigned NOT NULL DEFAULT '0',
  `qty_apelsin` int unsigned NOT NULL DEFAULT '0',
  `qty_hgt` int unsigned NOT NULL DEFAULT '0',
  `amount_all` decimal(16,2) GENERATED ALWAYS AS ((((((`amount_click` + `amount_paynet`) + `amount_payme`) + `amount_munis`) + `amount_apelsin`) + `amount_hgt`)) VIRTUAL,
  PRIMARY KEY (`dt`,`yy`,`id_mah`,`id_org`) USING BTREE,
  KEY `id_org_idx` (`id_org`) USING BTREE,
  KEY `rgn_index` (`id_reg`) USING BTREE,
  KEY `mah_index` (`id_mah`) USING BTREE,
  KEY `yy_mm` (`yy`,`mm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC
/*!50100 PARTITION BY RANGE (`yy`)
(PARTITION payments2019 VALUES LESS THAN (2019) ENGINE = InnoDB,
 PARTITION payments2020 VALUES LESS THAN (2020) ENGINE = InnoDB,
 PARTITION payments2021 VALUES LESS THAN (2021) ENGINE = InnoDB,
 PARTITION payments2023 VALUES LESS THAN (2023) ENGINE = InnoDB,
 PARTITION payments2024 VALUES LESS THAN (2024) ENGINE = InnoDB,
 PARTITION payments2025 VALUES LESS THAN (2025) ENGINE = InnoDB,
 PARTITION payments2026 VALUES LESS THAN (2026) ENGINE = InnoDB,
 PARTITION payments2027 VALUES LESS THAN (2027) ENGINE = InnoDB,
 PARTITION payments2028 VALUES LESS THAN (2028) ENGINE = InnoDB,
 PARTITION payments2029 VALUES LESS THAN (2029) ENGINE = InnoDB,
 PARTITION payments2030 VALUES LESS THAN (2030) ENGINE = InnoDB,
 PARTITION paymentsMax VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;


DROP TABLE IF EXISTS `i_real_ballons_details`;
CREATE TABLE `i_real_ballons_details` (
  `id_org` smallint unsigned NOT NULL,
  `id_mah` int unsigned DEFAULT NULL,
  `kod` varchar(255) NOT NULL,
  `abonent_name` varchar(255) NOT NULL,
  `1to30` date DEFAULT NULL,
  `31to35` date DEFAULT NULL,
  `36to40` date DEFAULT NULL,
  `41to45` date DEFAULT NULL,
  `46to50` date DEFAULT NULL,
  `51to55` date DEFAULT NULL,
  `56to60` date DEFAULT NULL,
  `61to65` date DEFAULT NULL,
  `66to70` date DEFAULT NULL,
  `71tomore` date DEFAULT NULL,
  `noneget` tinyint(1) DEFAULT NULL,
  `is_need` smallint DEFAULT '1',
  `dt` date DEFAULT NULL,
  KEY `real_ballons_details_id_org_idxx` (`id_org`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `i_real_ballons_orgs`;
CREATE TABLE `i_real_ballons_orgs` (
  `id_region` smallint unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `1to30` mediumint unsigned NOT NULL DEFAULT '0',
  `31to35` smallint unsigned NOT NULL DEFAULT '0',
  `36to40` smallint unsigned NOT NULL DEFAULT '0',
  `41to45` smallint unsigned NOT NULL DEFAULT '0',
  `46to50` smallint unsigned NOT NULL DEFAULT '0',
  `51to55` smallint unsigned NOT NULL DEFAULT '0',
  `56to60` smallint unsigned NOT NULL DEFAULT '0',
  `61to65` smallint unsigned NOT NULL DEFAULT '0',
  `66to70` smallint unsigned NOT NULL DEFAULT '0',
  `71tomore` smallint unsigned NOT NULL DEFAULT '0',
  `noneget` smallint unsigned NOT NULL DEFAULT '0',
  `is_need` smallint unsigned NOT NULL,
  `dt` date DEFAULT NULL,
  KEY `real_ballons_id_org_idxx` (`id_region`,`id_org`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `i_real_details`;
CREATE TABLE `i_real_details` (
  `dt` date NOT NULL,
  `yy` smallint unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `id_mah` int unsigned DEFAULT NULL,
  `ballon_kod` bigint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `real_at` timestamp NOT NULL,
  `inspector_kod` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `abonent_kod` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `inspector_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `abonent_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `abonent_address` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `abonent_phone` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `numb` int unsigned NOT NULL,
  `location_lon` varchar(25) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `location_lat` varchar(25) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  PRIMARY KEY (`yy`,`dt`,`ballon_kod`,`abonent_kod`) USING BTREE,
  KEY `real_dt` (`dt`) USING BTREE,
  KEY `real_id_org_idx` (`id_org`,`id_mah`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC
/*!50100 PARTITION BY RANGE (`yy`)
(PARTITION realdetails2019 VALUES LESS THAN (2019) ENGINE = InnoDB,
 PARTITION realdetails2020 VALUES LESS THAN (2020) ENGINE = InnoDB,
 PARTITION realdetails2021 VALUES LESS THAN (2021) ENGINE = InnoDB,
 PARTITION realdetails2022 VALUES LESS THAN (2022) ENGINE = InnoDB,
 PARTITION realdetails2023 VALUES LESS THAN (2023) ENGINE = InnoDB,
 PARTITION realdetails2024 VALUES LESS THAN (2024) ENGINE = InnoDB,
 PARTITION realdetails2025 VALUES LESS THAN (2025) ENGINE = InnoDB,
 PARTITION realdetails2026 VALUES LESS THAN (2026) ENGINE = InnoDB,
 PARTITION realdetails2027 VALUES LESS THAN (2027) ENGINE = InnoDB,
 PARTITION realdetailsMax VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;


DELIMITER ;;

CREATE TRIGGER `insert_i_real_details` AFTER INSERT ON `i_real_details` FOR EACH ROW
BEGIN INSERT INTO i_real_orgs (dt, yy, mm, id_region, id_org, id_mah, amount_total, total_qty) VALUES (NEW.dt, NEW.yy, MONTH(NEW.dt), (SELECT id_region FROM organizations o where o.id = NEW.id_org limit 1), NEW.id_org, NEW.id_mah, NEW.amount, 1) ON DUPLICATE KEY UPDATE amount_total = amount_total + NEW.amount, total_qty = total_qty + 1; END;;

CREATE TRIGGER `minus_deposit_orgs` AFTER INSERT ON `i_real_details` FOR EACH ROW
BEGIN
 UPDATE organizations set deposit = deposit - NEW.amount where id = NEW.id_org;
END;;

DELIMITER ;

DROP TABLE IF EXISTS `i_real_failed`;
CREATE TABLE `i_real_failed` (
  `id_tran` bigint unsigned NOT NULL,
  `id_abonent` int unsigned DEFAULT NULL,
  `id_ballon` int unsigned DEFAULT NULL,
  `id_org` int unsigned DEFAULT NULL,
  `err` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
  PRIMARY KEY (`id_tran`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `i_real_orgs`;
CREATE TABLE `i_real_orgs` (
  `dt` date NOT NULL,
  `yy` smallint unsigned NOT NULL,
  `mm` smallint unsigned NOT NULL,
  `id_region` smallint unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `id_mah` int unsigned NOT NULL,
  `amount_total` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_qty` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`dt`,`yy`,`id_org`,`id_mah`) USING BTREE,
  KEY `real_id_org_idxx` (`id_region`,`id_org`) USING BTREE,
  KEY `real_mmyy_idx` (`yy`,`mm`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `i_rekvizits`;
CREATE TABLE `i_rekvizits` (
  `id_region` int unsigned DEFAULT NULL,
  `id_district` int unsigned DEFAULT NULL,
  `all_qty` int unsigned DEFAULT NULL,
  `is_active` int DEFAULT NULL,
  `is_deactive` int DEFAULT NULL,
  `with_pinfl` int unsigned DEFAULT NULL,
  `change_pinfl` int unsigned DEFAULT NULL,
  `with_psp` int unsigned DEFAULT NULL,
  `change_psp` int unsigned DEFAULT NULL,
  `with_mobile` int unsigned DEFAULT NULL,
  `change_mobile` int unsigned DEFAULT NULL,
  `with_kadastr` int unsigned DEFAULT NULL,
  `change_kadastr` int unsigned DEFAULT NULL,
  `with_nfc` int unsigned DEFAULT NULL,
  `change_nfc` int unsigned DEFAULT NULL,
  `dt` date DEFAULT NULL,
  KEY `i_rekvizits_id_district_index` (`id_district`),
  KEY `i_rekvizits_id_region_index` (`id_region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `idx_dayli_by_orgs`;
CREATE TABLE `idx_dayli_by_orgs` (
  `dt` date NOT NULL,
  `yy` smallint unsigned NOT NULL,
  `mm` smallint unsigned NOT NULL,
  `id_region` smallint unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `amount_paynet` decimal(16,2) NOT NULL DEFAULT '0.00',
  `amount_click` decimal(16,2) NOT NULL DEFAULT '0.00',
  `amount_payme` decimal(16,2) NOT NULL DEFAULT '0.00',
  `amount_munis` decimal(16,2) NOT NULL DEFAULT '0.00',
  `amount_apelsin` decimal(16,2) NOT NULL DEFAULT '0.00',
  `amount_hgt` decimal(16,2) NOT NULL DEFAULT '0.00',
  `amount_all` decimal(16,2) GENERATED ALWAYS AS ((((((`amount_click` + `amount_paynet`) + `amount_payme`) + `amount_munis`) + `amount_apelsin`) + `amount_hgt`)) VIRTUAL,
  `qty_all` int GENERATED ALWAYS AS ((((((`qty_click` + `qty_paynet`) + `qty_payme`) + `qty_munis`) + `qty_apelsin`) + `qty_hgt`)) VIRTUAL,
  `qty_paynet` int NOT NULL DEFAULT '0',
  `qty_click` int NOT NULL DEFAULT '0',
  `qty_munis` int NOT NULL DEFAULT '0',
  `qty_payme` int NOT NULL DEFAULT '0',
  `qty_apelsin` int NOT NULL DEFAULT '0',
  `qty_hgt` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`dt`,`yy`,`id_org`) USING BTREE,
  KEY `id_org_idxx` (`id_region`,`id_org`) USING BTREE,
  KEY `mmyy_idx` (`yy`,`mm`) USING BTREE,
  KEY `yy_mm_id_region` (`yy`,`mm`,`id_region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC
/*!50100 PARTITION BY RANGE (`yy`)
(PARTITION p_debit_2019 VALUES LESS THAN (2019) ENGINE = InnoDB,
 PARTITION p_debit_2020 VALUES LESS THAN (2020) ENGINE = InnoDB,
 PARTITION p_debit_2021 VALUES LESS THAN (2021) ENGINE = InnoDB,
 PARTITION p_debit_2022 VALUES LESS THAN (2022) ENGINE = InnoDB,
 PARTITION p_debit_2023 VALUES LESS THAN (2023) ENGINE = InnoDB,
 PARTITION p_debit_2024 VALUES LESS THAN (2024) ENGINE = InnoDB,
 PARTITION p_debit_2025 VALUES LESS THAN (2025) ENGINE = InnoDB,
 PARTITION p_debit_2026 VALUES LESS THAN (2026) ENGINE = InnoDB,
 PARTITION p_debit_2027 VALUES LESS THAN (2027) ENGINE = InnoDB,
 PARTITION p_debit_2028 VALUES LESS THAN (2028) ENGINE = InnoDB,
 PARTITION p_debit_2029 VALUES LESS THAN (2029) ENGINE = InnoDB,
 PARTITION p_debit_2030 VALUES LESS THAN (2030) ENGINE = InnoDB,
 PARTITION p_debit_203x VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;


DROP TABLE IF EXISTS `idx_dayli_by_orgs_details`;
CREATE TABLE `idx_dayli_by_orgs_details` (
  `dt` date NOT NULL,
  `yy` smallint unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `provider` enum('PAYNET','PAYME','CLICK','MUNIS','APELSIN','HGT') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `paid_at` timestamp NOT NULL,
  `sys_bid` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `kod` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `psp` varchar(120) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `mahalla` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '0',
  `id_mahalla` int unsigned DEFAULT NULL,
  `mobile` varchar(130) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '0',
  `address` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '0',
  `deposit` decimal(15,2) DEFAULT '0.00',
  PRIMARY KEY (`yy`,`sys_bid`),
  KEY `idx_provider` (`provider`) USING BTREE,
  KEY `id_org_dt` (`id_org`,`dt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC
/*!50100 PARTITION BY RANGE (`yy`)
(PARTITION payments2019 VALUES LESS THAN (2019) ENGINE = InnoDB,
 PARTITION payments2020 VALUES LESS THAN (2020) ENGINE = InnoDB,
 PARTITION payments2021 VALUES LESS THAN (2021) ENGINE = InnoDB,
 PARTITION payments2022 VALUES LESS THAN (2022) ENGINE = InnoDB,
 PARTITION payments2023 VALUES LESS THAN (2023) ENGINE = InnoDB,
 PARTITION payments2024 VALUES LESS THAN (2024) ENGINE = InnoDB,
 PARTITION payments2025 VALUES LESS THAN (2025) ENGINE = InnoDB,
 PARTITION payments2026 VALUES LESS THAN (2026) ENGINE = InnoDB,
 PARTITION payments2027 VALUES LESS THAN (2027) ENGINE = InnoDB,
 PARTITION payments2028 VALUES LESS THAN (2028) ENGINE = InnoDB,
 PARTITION paymentsMax VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;


DROP TABLE IF EXISTS `idx_gb_abon_times`;
CREATE TABLE `idx_gb_abon_times` (
  `id_reg` smallint unsigned NOT NULL,
  `id_org` int unsigned NOT NULL,
  `id_mah` int unsigned DEFAULT NULL,
  `yy` smallint unsigned DEFAULT NULL,
  `t1` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t2` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t3` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t4` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t5` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t6` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t7` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t8` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t9` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t10` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t11` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t12` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `t13` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `tnone` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  KEY `yy` (`yy`),
  KEY `id_reg` (`id_reg`),
  KEY `id_org` (`id_org`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `idx_gb_abon_times_orgs`;
CREATE TABLE `idx_gb_abon_times_orgs` (
  `id_reg` smallint unsigned NOT NULL,
  `id_org` int unsigned NOT NULL,
  `id_mah` int unsigned DEFAULT NULL,
  `yy` smallint unsigned DEFAULT NULL,
  `t1` int unsigned DEFAULT NULL,
  `t2` int unsigned DEFAULT NULL,
  `t3` int unsigned DEFAULT NULL,
  `t4` int unsigned DEFAULT NULL,
  `t5` int unsigned DEFAULT NULL,
  `t6` int unsigned DEFAULT NULL,
  `t7` int unsigned DEFAULT NULL,
  `t8` int unsigned DEFAULT NULL,
  `t9` int unsigned DEFAULT NULL,
  `t10` int unsigned DEFAULT NULL,
  `t11` int unsigned DEFAULT NULL,
  `t12` int unsigned DEFAULT NULL,
  `t13` int unsigned DEFAULT NULL,
  `tnone` int unsigned DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `idx_real_dayli_by_orgs`;
CREATE TABLE `idx_real_dayli_by_orgs` (
  `dt` date NOT NULL,
  `yy` smallint unsigned NOT NULL,
  `mm` smallint unsigned NOT NULL,
  `id_region` smallint unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `amount_total` decimal(18,2) NOT NULL DEFAULT '0.00',
  `amount_real` decimal(18,2) NOT NULL DEFAULT '0.00',
  `amount_back` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_qty` int NOT NULL DEFAULT '0',
  `real_qty` int NOT NULL DEFAULT '0',
  `back_qty` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`dt`,`yy`,`id_org`) USING BTREE,
  KEY `real_id_org_idxx` (`id_region`,`id_org`) USING BTREE,
  KEY `real_mmyy_idx` (`yy`,`mm`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `idx_real_dayli_by_orgs_details`;
CREATE TABLE `idx_real_dayli_by_orgs_details` (
  `dt` date NOT NULL,
  `yy` smallint unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `org_name` varchar(180) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `ballon_kod` bigint unsigned NOT NULL,
  `real_at` timestamp NOT NULL,
  `inspector_kod` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `abonent_kod` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `inspector_name` varchar(200) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `abonent_name` varchar(200) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `abonent_address` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `abonent_mahalla` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `abonent_phone` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `numb` int unsigned NOT NULL,
  `location_lon` varchar(25) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `location_lat` varchar(25) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  PRIMARY KEY (`yy`,`ballon_kod`,`real_at`,`abonent_kod`) USING BTREE,
  KEY `real_id_org_idx` (`id_org`) USING BTREE,
  KEY `real_dt` (`dt`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC
/*!50100 PARTITION BY RANGE (`yy`)
(PARTITION realdetails2019 VALUES LESS THAN (2019) ENGINE = InnoDB,
 PARTITION realdetails2020 VALUES LESS THAN (2020) ENGINE = InnoDB,
 PARTITION realdetails2021 VALUES LESS THAN (2021) ENGINE = InnoDB,
 PARTITION realdetails2022 VALUES LESS THAN (2022) ENGINE = InnoDB,
 PARTITION realdetails2023 VALUES LESS THAN (2023) ENGINE = InnoDB,
 PARTITION payments2024 VALUES LESS THAN (2024) ENGINE = InnoDB,
 PARTITION payments2025 VALUES LESS THAN (2025) ENGINE = InnoDB,
 PARTITION payments2026 VALUES LESS THAN (2026) ENGINE = InnoDB,
 PARTITION payments2027 VALUES LESS THAN (2027) ENGINE = InnoDB,
 PARTITION paymentsMax VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;


DROP TABLE IF EXISTS `idx_real_dayli_by_orgs_fact`;
CREATE TABLE `idx_real_dayli_by_orgs_fact` (
  `dt` date NOT NULL,
  `yy` smallint unsigned NOT NULL,
  `mm` smallint unsigned NOT NULL,
  `id_region` smallint unsigned NOT NULL,
  `id_org` smallint unsigned NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `qty` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`dt`,`yy`,`id_org`) USING BTREE,
  KEY `fact_id_org_idxx` (`id_region`,`id_org`) USING BTREE,
  KEY `fact_mmyy_idx` (`yy`,`mm`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC
/*!50100 PARTITION BY RANGE (`yy`)
(PARTITION p2017 VALUES LESS THAN (2017) ENGINE = InnoDB,
 PARTITION p2018 VALUES LESS THAN (2018) ENGINE = InnoDB,
 PARTITION p2019 VALUES LESS THAN (2019) ENGINE = InnoDB,
 PARTITION p2020 VALUES LESS THAN (2020) ENGINE = InnoDB,
 PARTITION p2021 VALUES LESS THAN (2021) ENGINE = InnoDB,
 PARTITION p2022 VALUES LESS THAN (2022) ENGINE = InnoDB,
 PARTITION p2023 VALUES LESS THAN (2023) ENGINE = InnoDB,
 PARTITION p2024 VALUES LESS THAN (2024) ENGINE = InnoDB,
 PARTITION p2025 VALUES LESS THAN (2025) ENGINE = InnoDB,
 PARTITION p2026 VALUES LESS THAN (2026) ENGINE = InnoDB,
 PARTITION p2027 VALUES LESS THAN (2027) ENGINE = InnoDB,
 PARTITION p2028 VALUES LESS THAN (2028) ENGINE = InnoDB,
 PARTITION p2029 VALUES LESS THAN (2029) ENGINE = InnoDB,
 PARTITION p2030 VALUES LESS THAN (2030) ENGINE = InnoDB,
 PARTITION p20MAX VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;


DROP TABLE IF EXISTS `integration_logs`;
CREATE TABLE `integration_logs` (
  `module` varchar(150) DEFAULT NULL,
  `request` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
  `payload` text,
  `value` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  `borned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `jobs_queue_index` (`queue`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `mahallas`;
CREATE TABLE `mahallas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_district` int unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `id_region` int unsigned NOT NULL,
  `aholi_qty` int unsigned DEFAULT '0',
  `oila_qty` int unsigned DEFAULT '0',
  `xonadon_qty` int unsigned DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `yk_id` int DEFAULT NULL,
  `yk_name` varchar(255) DEFAULT NULL,
  `yk_coato` int DEFAULT NULL,
  `yk_inn` int DEFAULT NULL,
  `yk_kod` int DEFAULT NULL,
  `yk_sektor` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `organ_old`;
CREATE TABLE `organ_old` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `orgtype_id` int unsigned NOT NULL,
  `id_region` int unsigned NOT NULL,
  `id_district` int unsigned DEFAULT NULL,
  `name` varchar(300) NOT NULL,
  `tags` varchar(100) DEFAULT NULL,
  `bname` varchar(80) DEFAULT NULL,
  `account` varchar(20) DEFAULT NULL,
  `inn` varchar(9) DEFAULT NULL,
  `branch` varchar(5) DEFAULT NULL,
  `description` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `id_region` (`id_region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `organizations`;
CREATE TABLE `organizations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `orgtype_id` int unsigned NOT NULL,
  `id_region` int unsigned NOT NULL,
  `id_district` int unsigned DEFAULT NULL,
  `name` varchar(300) NOT NULL,
  `tags` varchar(100) DEFAULT NULL,
  `bname` varchar(80) DEFAULT NULL,
  `account` varchar(20) DEFAULT NULL,
  `inn` varchar(9) DEFAULT NULL,
  `branch` varchar(5) DEFAULT NULL,
  `deposit` decimal(20,2) DEFAULT '0.00',
  `description` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `status` enum('Active','Deactive') NOT NULL DEFAULT 'Active',
  `id_gps` int DEFAULT NULL,
  `entry_by` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `id_region` (`id_region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `orgtypes`;
CREATE TABLE `orgtypes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(300) DEFAULT NULL,
  `description` mediumtext,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_resets_email_index` (`email`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `regions`;
CREATE TABLE `regions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name_ru` varchar(50) NOT NULL COMMENT 'ru',
  `name_uz` varchar(50) DEFAULT NULL,
  `name_en` varchar(50) DEFAULT NULL,
  `code` varchar(5) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `tabiiy_users`;
CREATE TABLE `tabiiy_users` (
  `row_id` int NOT NULL AUTO_INCREMENT,
  `id` int NOT NULL,
  `nomi` varchar(255) DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  `old_code` varchar(255) DEFAULT NULL,
  `viloyat` varchar(255) DEFAULT NULL,
  `tuman` varchar(255) DEFAULT NULL,
  `manzil` varchar(255) DEFAULT NULL,
  `jshshir` varchar(255) DEFAULT NULL,
  `passport_seriyasi_va_raqami` varchar(255) DEFAULT NULL,
  `kadastr_raqami` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_synch` int DEFAULT NULL,
  PRIMARY KEY (`row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `tb_factory_integration`;
CREATE TABLE `tb_factory_integration` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `numb` varchar(120) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `numb_plomb` varchar(120) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `factory` varchar(30) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `factory_egaz` int unsigned DEFAULT NULL,
  `hgt_filial` varchar(30) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `hgt_filial_egaz` int unsigned NOT NULL,
  `out_tp` enum('auto','train') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `vagon_drv_name` varchar(200) DEFAULT NULL,
  `numb_auto` varchar(50) DEFAULT NULL,
  `numb_pricep` varchar(50) DEFAULT NULL,
  `brutto` decimal(16,2) DEFAULT NULL,
  `netto` decimal(16,2) DEFAULT NULL,
  `qty_output` decimal(10,3) unsigned NOT NULL DEFAULT '0.000',
  `dt` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numb_dt` (`numb`,`dt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DELIMITER ;;

CREATE TRIGGER `tb_factory_integration_bi` BEFORE INSERT ON `tb_factory_integration` FOR EACH ROW
begin
    set new.hgt_filial_egaz = case new.hgt_filial
                             when '000000001' then 274
                             when '000000002' then 275
                             when '000000003' then 284
                             when '000000004' then 276
                             when '000000005' then 280
                             when '000000006' then 279
                             when '000000007' then 277
                             when '000000008' then 278
                             when '000000009' then 281
                             when '000000010' then 282
                             when '000000011' then 283
                             when '000000012' then 273
                             when '000000013' then 285
                             else new.hgt_filial_egaz
        end;
   set new.factory_egaz = case new.factory
                              when '000000002' then 292
                              when '000000004' then 295
                              when '000000006' then 290
                              when '000000009' then 293
                              when '000000010' then 304
                              else new.factory_egaz
        end;
end;;

DELIMITER ;

DROP TABLE IF EXISTS `tb_gas_dispensers`;
CREATE TABLE `tb_gas_dispensers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `org_id` int NOT NULL,
  `vaqt` datetime NOT NULL,
  `kg` decimal(10,3) NOT NULL,
  `device` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `id_balon` int DEFAULT NULL,
  `kod_balon` bigint unsigned DEFAULT NULL,
  `comment` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `tb_gnp_camera_logs`;
CREATE TABLE `tb_gnp_camera_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `org_id` int DEFAULT NULL,
  `car_number` varchar(60) DEFAULT NULL,
  `event_date` datetime DEFAULT NULL,
  `photo` varchar(150) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `org_id` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `tb_levelmeters`;
CREATE TABLE `tb_levelmeters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_org` int DEFAULT NULL,
  `device_address` int DEFAULT NULL,
  `param_id` varchar(20) DEFAULT NULL,
  `value` float DEFAULT NULL,
  `ts` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `tb_realize_location`;
CREATE TABLE `tb_realize_location` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_request` int NOT NULL,
  `id_org` int DEFAULT NULL,
  `request_numb` varchar(255) DEFAULT NULL,
  `id_request_ballon` int NOT NULL,
  `id_abonent` int NOT NULL,
  `abonent_name` varchar(255) DEFAULT NULL,
  `abonent_kod` varchar(15) DEFAULT NULL,
  `passed_lat` varchar(255) NOT NULL,
  `passed_lon` varchar(255) NOT NULL,
  `passed_at` datetime NOT NULL,
  `passed_kod` varchar(11) DEFAULT NULL,
  `passed_dt` date NOT NULL,
  `passed_by` int NOT NULL,
  `passed_name` varchar(255) DEFAULT NULL,
  `id_vehicle` int DEFAULT NULL,
  `vehicle_numb` varchar(255) DEFAULT NULL,
  `vehicle_lat` varchar(255) DEFAULT NULL,
  `vehicle_lon` varchar(255) DEFAULT NULL,
  `distance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `passed_dt` (`passed_dt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `tb_regional_accounts`;
CREATE TABLE `tb_regional_accounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_region` int unsigned NOT NULL,
  `account` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '' COMMENT 'r/s',
  `inn` varchar(9) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `branch` varchar(5) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '' COMMENT 'mfo',
  `name` varchar(80) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '' COMMENT 'bankname',
  `deposit` decimal(22,2) DEFAULT NULL,
  `oked` varchar(6) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `tb_rezervuars`;
CREATE TABLE `tb_rezervuars` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_org` int unsigned NOT NULL,
  `name` varchar(200) NOT NULL,
  `vol_t` decimal(15,3) unsigned NOT NULL DEFAULT '0.000',
  `vol_k` decimal(15,3) unsigned NOT NULL DEFAULT '0.000',
  `dayly_power` decimal(15,3) unsigned NOT NULL DEFAULT '0.000',
  `monthly_power` decimal(15,3) unsigned NOT NULL DEFAULT '0.000',
  `ejd_qty` smallint unsigned DEFAULT '0',
  `libra_qty` smallint unsigned DEFAULT '1',
  `descr` varchar(255) DEFAULT NULL,
  `qty` decimal(18,3) DEFAULT '0.000',
  `active` tinyint unsigned DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;


DROP TABLE IF EXISTS `tb_scales_logs`;
CREATE TABLE `tb_scales_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `org_id` int DEFAULT NULL,
  `car_number` varchar(60) DEFAULT NULL,
  `weight` decimal(18,2) DEFAULT NULL,
  `event_date` datetime DEFAULT NULL,
  `photo` varchar(150) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `entrance` int DEFAULT NULL COMMENT '1-kirish, 2-chiqish ',
  `comment` text,
  `invoice_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `org_id` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DELIMITER ;;

CREATE TRIGGER `tb_scales_logs_bi` BEFORE INSERT ON `tb_scales_logs` FOR EACH ROW
begin
    set new.org_id = case new.org_id
                             when 1 then 13
                             when 239 then 350
                             else new.org_id
    end;
end;;

DELIMITER ;

DROP TABLE IF EXISTS `tb_social_sphere`;
CREATE TABLE `tb_social_sphere` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` varchar(50) NOT NULL,
  `region_id` int NOT NULL,
  `district_id` int DEFAULT NULL,
  `organization` varchar(50) NOT NULL COMMENT 'UUID организации',
  `inn` varchar(20) NOT NULL,
  `realize_dt` datetime DEFAULT NULL,
  `accept_dt` datetime DEFAULT NULL,
  `realize_kg` decimal(16,2) NOT NULL,
  `accept_kg` decimal(16,2) NOT NULL,
  `realize_sum` decimal(20,2) NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `items` json DEFAULT NULL,
  `contract` varchar(100) NOT NULL,
  `invoice` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_id` (`request_id`),
  KEY `idx_region` (`region_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `tmp_index_coming_orgs`;
CREATE TABLE `tmp_index_coming_orgs` (
  `dt` date NOT NULL,
  `yy` int NOT NULL,
  `mm` int NOT NULL,
  `id_region` int NOT NULL,
  `id_org` int NOT NULL,
  `paynet_sum` decimal(18,2) NOT NULL DEFAULT '0.00',
  `paynet_qty` int NOT NULL DEFAULT '0',
  `apelsin_sum` decimal(18,2) NOT NULL DEFAULT '0.00',
  `apelsin_qty` int NOT NULL DEFAULT '0',
  `click_sum` decimal(18,2) NOT NULL DEFAULT '0.00',
  `click_qty` int NOT NULL DEFAULT '0',
  `payme_sum` decimal(18,2) NOT NULL DEFAULT '0.00',
  `payme_qty` int NOT NULL DEFAULT '0',
  `munis_sum` decimal(18,2) NOT NULL DEFAULT '0.00',
  `munis_qty` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;


DROP TABLE IF EXISTS `tmp_rrr`;
CREATE TABLE `tmp_rrr` (
  `id_request_dt` date DEFAULT NULL,
  `id_request_passed` int DEFAULT NULL,
  `id_request_ballon` int DEFAULT NULL,
  KEY `id_request_ballon` (`id_request_ballon`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `users_email_unique` (`email`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;


DROP VIEW IF EXISTS `vw_balance_realized_regions_month`;
CREATE TABLE `vw_balance_realized_regions_month` (`id_region` int unsigned, `real_qty` decimal(43,6));


DROP VIEW IF EXISTS `vw_coming_last2years`;
CREATE TABLE `vw_coming_last2years` (`id_region` smallint unsigned, `yy` bigint, `m1` decimal(65,6), `m2` decimal(65,6), `m3` decimal(65,6), `m4` decimal(65,6), `m5` decimal(65,6), `m6` decimal(65,6), `m7` decimal(65,6), `m8` decimal(65,6), `m9` decimal(65,6), `m10` decimal(65,6), `m11` decimal(65,6), `m12` decimal(65,6));


DROP VIEW IF EXISTS `vw_face_id_otmaganlar`;
CREATE TABLE `vw_face_id_otmaganlar` (`viloyat` varchar(50), `raygaz` varchar(300), `abonent_kod` varchar(12), `abonent` varchar(255), `ohirgi_realizatsiya` date);


DROP VIEW IF EXISTS `vw_fin_real_by_regions`;
CREATE TABLE `vw_fin_real_by_regions` (`id_region` int unsigned, `prs` decimal(50,6), `rlz` decimal(50,6));


DROP VIEW IF EXISTS `vw_finance_current`;
CREATE TABLE `vw_finance_current` (`id_region` int unsigned, `paynet_sum` decimal(47,6), `paynet_qty` decimal(32,0), `payme_sum` decimal(47,6), `payme_qty` decimal(32,0), `click_sum` decimal(47,6), `click_qty` decimal(32,0), `munis_sum` decimal(47,6), `munis_qty` decimal(32,0), `apelsin_sum` decimal(47,6), `apelsin_qty` decimal(32,0));


DROP VIEW IF EXISTS `vw_finance_month`;
CREATE TABLE `vw_finance_month` (`id_region` int unsigned, `paynet_sum` decimal(47,6), `paynet_qty` decimal(32,0), `payme_sum` decimal(47,6), `payme_qty` decimal(32,0), `click_sum` decimal(47,6), `click_qty` decimal(32,0), `munis_sum` decimal(47,6), `munis_qty` decimal(32,0), `apelsin_sum` decimal(47,6), `apelsin_qty` decimal(32,0));


DROP VIEW IF EXISTS `vw_finance_real_current`;
CREATE TABLE `vw_finance_real_current` (`id_region` int unsigned, `real_sum` decimal(49,6), `real_qty` decimal(32,0));


DROP VIEW IF EXISTS `vw_finance_real_month`;
CREATE TABLE `vw_finance_real_month` (`id_region` int unsigned, `real_sum` decimal(50,6), `real_qty` decimal(32,0));


DROP VIEW IF EXISTS `vw_finance_real_year`;
CREATE TABLE `vw_finance_real_year` (`id_region` int unsigned, `real_sum` decimal(50,6), `real_qty` decimal(32,0));


DROP VIEW IF EXISTS `vw_finance_year`;
CREATE TABLE `vw_finance_year` (`id_region` int unsigned, `paynet_sum` decimal(47,6), `paynet_qty` decimal(32,0), `payme_sum` decimal(47,6), `payme_qty` decimal(32,0), `click_sum` decimal(47,6), `click_qty` decimal(32,0), `munis_sum` decimal(47,6), `munis_qty` decimal(32,0), `apelsin_sum` decimal(47,6), `apelsin_qty` decimal(32,0));


DROP VIEW IF EXISTS `vw_got_max_real_date`;
CREATE TABLE `vw_got_max_real_date` (`dt` date);


DROP VIEW IF EXISTS `vw_more_35_realize`;
CREATE TABLE `vw_more_35_realize` (`region` varchar(50), `district` varchar(30), `mahalla` varchar(255), `kod` varchar(255), `abonent` varchar(255), `ohirgi_realizatsiya` date);


DROP VIEW IF EXISTS `vw_new_faceid_data`;
CREATE TABLE `vw_new_faceid_data` (`region` varchar(50), `raygas` varchar(300), `abonent_kod` varchar(11), `realize` date, `recipient` varchar(255), `address` varchar(255), `kadastr` varchar(255), `kadastr_owner` varchar(255), `pinfl` varchar(14), `tuman` varchar(8));


DROP VIEW IF EXISTS `vw_real_ballons_more45`;
CREATE TABLE `vw_real_ballons_more45` (`region` varchar(50), `organization` varchar(300), `kod` varchar(255), `abonent_name` varchar(255), `is_need` smallint, `more45` varchar(10), `days` int);


DROP VIEW IF EXISTS `vw_real_widgets_today_only`;
CREATE TABLE `vw_real_widgets_today_only` (`id_region` int unsigned, `real_sum` decimal(50,6), `total_sum` decimal(50,6), `real_qty` decimal(32,0), `total_qty` decimal(32,0));


DROP VIEW IF EXISTS `vw_region_deposits`;
CREATE TABLE `vw_region_deposits` (`id_region` int unsigned, `deposit` decimal(53,6));


DROP VIEW IF EXISTS `vw_reindexation_payments`;
CREATE TABLE `vw_reindexation_payments` (`dt` date, `qty_all` decimal(32,0), `qty` bigint);


DROP VIEW IF EXISTS `vw_reindexation_reals`;
CREATE TABLE `vw_reindexation_reals` (`dt` date, `qty_all` bigint, `real_qty` bigint);


DROP VIEW IF EXISTS `vw_report_2_1`;
CREATE TABLE `vw_report_2_1` (`yil` smallint unsigned, `sana` date, `HGT_bulimi` varchar(300), `mahalla` varchar(255), `ballon_kod` varchar(21), `summa` decimal(12,2), `berilgan_vaqt` timestamp, `inspector_kod` varchar(12), `abonent_kod` varchar(12), `inspector_name` varchar(255), `abonent_name` varchar(255), `abonent_address` varchar(255), `abonent_phone` varchar(255), `zayavka_raqami` int unsigned);


DROP TABLE IF EXISTS `vw_balance_realized_regions_month`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_balance_realized_regions_month` AS select `i_real_orgs`.`id_region` AS `id_region`,(sum((`i_real_orgs`.`total_qty` * 20.00)) / 1000.000) AS `real_qty` from `i_real_orgs` where (`i_real_orgs`.`dt` >= (curdate() - interval (dayofmonth(curdate()) - 1) day)) group by `i_real_orgs`.`id_region` with rollup order by `id_region`;

DROP TABLE IF EXISTS `vw_coming_last2years`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_coming_last2years` AS with `cte` as (select `i_money_orgs`.`id_reg` AS `id_region`,`i_money_orgs`.`yy` AS `yy`,`i_money_orgs`.`mm` AS `mm`,(sum(`i_money_orgs`.`amount_all`) / 1000000.000000) AS `a` from `i_money_orgs` where (`i_money_orgs`.`yy` >= (year(curdate()) - 1)) group by `i_money_orgs`.`yy`,`i_money_orgs`.`mm`,`i_money_orgs`.`id_reg`) select `cte`.`id_region` AS `id_region`,(year(curdate()) - 1) AS `yy`,sum(if((`cte`.`mm` = 1),`cte`.`a`,0.000)) AS `m1`,sum(if((`cte`.`mm` = 2),`cte`.`a`,0.000)) AS `m2`,sum(if((`cte`.`mm` = 3),`cte`.`a`,0.000)) AS `m3`,sum(if((`cte`.`mm` = 4),`cte`.`a`,0.000)) AS `m4`,sum(if((`cte`.`mm` = 5),`cte`.`a`,0.000)) AS `m5`,sum(if((`cte`.`mm` = 6),`cte`.`a`,0.000)) AS `m6`,sum(if((`cte`.`mm` = 7),`cte`.`a`,0.000)) AS `m7`,sum(if((`cte`.`mm` = 8),`cte`.`a`,0.000)) AS `m8`,sum(if((`cte`.`mm` = 9),`cte`.`a`,0.000)) AS `m9`,sum(if((`cte`.`mm` = 10),`cte`.`a`,0.000)) AS `m10`,sum(if((`cte`.`mm` = 11),`cte`.`a`,0.000)) AS `m11`,sum(if((`cte`.`mm` = 12),`cte`.`a`,0.000)) AS `m12` from `cte` where (`cte`.`yy` = (year(curdate()) - 1)) group by `cte`.`id_region` with rollup union all select `cte`.`id_region` AS `id_region`,year(curdate()) AS `(year(curdate()))`,sum(if((`cte`.`mm` = 1),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 1,a,0.000))`,sum(if((`cte`.`mm` = 2),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 2,a,0.000))`,sum(if((`cte`.`mm` = 3),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 3,a,0.000))`,sum(if((`cte`.`mm` = 4),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 4,a,0.000))`,sum(if((`cte`.`mm` = 5),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 5,a,0.000))`,sum(if((`cte`.`mm` = 6),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 6,a,0.000))`,sum(if((`cte`.`mm` = 7),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 7,a,0.000))`,sum(if((`cte`.`mm` = 8),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 8,a,0.000))`,sum(if((`cte`.`mm` = 9),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 9,a,0.000))`,sum(if((`cte`.`mm` = 10),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 10,a,0.000))`,sum(if((`cte`.`mm` = 11),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 11,a,0.000))`,sum(if((`cte`.`mm` = 12),`cte`.`a`,0.000)) AS `SUM(if(``mm`` = 12,a,0.000))` from `cte` where (`cte`.`yy` = year(curdate())) group by `cte`.`id_region` with rollup;

DROP TABLE IF EXISTS `vw_face_id_otmaganlar`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `vw_face_id_otmaganlar` AS select `r`.`name_uz` AS `viloyat`,`o`.`name` AS `raygaz`,concat('\'',`u`.`kod`) AS `abonent_kod`,`u`.`name` AS `abonent`,`u`.`rdt` AS `ohirgi_realizatsiya` from (((`cms_users` `u` join `regions` `r` on((`r`.`id` = `u`.`id_region`))) join `organizations` `o` on((`o`.`id` = `u`.`id_org`))) left join `i_face_id_detail` `i` on((`i`.`id_abonent` = `u`.`id`))) where ((`u`.`status` = 'Active') and (`u`.`id_cms_privileges` = 4) and (`i`.`id_abonent` is null));

DROP TABLE IF EXISTS `vw_fin_real_by_regions`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_fin_real_by_regions` AS select `o`.`id_region` AS `id_region`,(sum(`i`.`amount_total`) / 1000000.000000) AS `prs`,(sum(`i`.`amount_total`) / 1000000.000000) AS `rlz` from (`i_real_orgs` `i` join `organizations` `o` on((`i`.`id_org` = `o`.`id`))) where (`i`.`yy` = year(curdate())) group by `o`.`id_region` order by `o`.`id_region`;

DROP TABLE IF EXISTS `vw_finance_current`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_finance_current` AS select `i_money_orgs`.`id_reg` AS `id_region`,round((sum(`i_money_orgs`.`amount_paynet`) / 1000000.000000),6) AS `paynet_sum`,sum(`i_money_orgs`.`qty_paynet`) AS `paynet_qty`,round((sum(`i_money_orgs`.`amount_payme`) / 1000000.000000),6) AS `payme_sum`,sum(`i_money_orgs`.`qty_payme`) AS `payme_qty`,round((sum(`i_money_orgs`.`amount_click`) / 1000000.000000),6) AS `click_sum`,sum(`i_money_orgs`.`qty_click`) AS `click_qty`,round((sum(`i_money_orgs`.`amount_munis`) / 1000000.000000),6) AS `munis_sum`,sum(`i_money_orgs`.`qty_munis`) AS `munis_qty`,round((sum(`i_money_orgs`.`amount_apelsin`) / 1000000.000000),6) AS `apelsin_sum`,sum(`i_money_orgs`.`qty_apelsin`) AS `apelsin_qty` from `i_money_orgs` where (`i_money_orgs`.`dt` = curdate()) group by `i_money_orgs`.`id_reg` with rollup order by `i_money_orgs`.`id_reg`;

DROP TABLE IF EXISTS `vw_finance_month`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_finance_month` AS select `i_money_orgs`.`id_reg` AS `id_region`,round((sum(`i_money_orgs`.`amount_paynet`) / 1000000.000000),6) AS `paynet_sum`,sum(`i_money_orgs`.`qty_paynet`) AS `paynet_qty`,round((sum(`i_money_orgs`.`amount_payme`) / 1000000.000000),6) AS `payme_sum`,sum(`i_money_orgs`.`qty_payme`) AS `payme_qty`,round((sum(`i_money_orgs`.`amount_click`) / 1000000.000000),6) AS `click_sum`,sum(`i_money_orgs`.`qty_click`) AS `click_qty`,round((sum(`i_money_orgs`.`amount_munis`) / 1000000.000000),6) AS `munis_sum`,sum(`i_money_orgs`.`qty_munis`) AS `munis_qty`,round((sum(`i_money_orgs`.`amount_apelsin`) / 1000000.000000),6) AS `apelsin_sum`,sum(`i_money_orgs`.`qty_apelsin`) AS `apelsin_qty` from `i_money_orgs` where (`i_money_orgs`.`dt` >= (curdate() - interval (dayofmonth(curdate()) - 1) day)) group by `i_money_orgs`.`id_reg` with rollup order by `i_money_orgs`.`id_reg`;

DROP TABLE IF EXISTS `vw_finance_real_current`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_finance_real_current` AS select `i_real_orgs`.`id_region` AS `id_region`,(sum(`i_real_orgs`.`amount_total`) / 1000000.00000) AS `real_sum`,sum(`i_real_orgs`.`total_qty`) AS `real_qty` from `i_real_orgs` where (`i_real_orgs`.`dt` = curdate()) group by `i_real_orgs`.`id_region` with rollup order by `id_region`;

DROP TABLE IF EXISTS `vw_finance_real_month`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_finance_real_month` AS select `i_real_orgs`.`id_region` AS `id_region`,(sum(`i_real_orgs`.`amount_total`) / 1000000.000000) AS `real_sum`,sum(`i_real_orgs`.`total_qty`) AS `real_qty` from `i_real_orgs` where (`i_real_orgs`.`dt` >= (curdate() - interval (dayofmonth(curdate()) - 1) day)) group by `i_real_orgs`.`id_region` with rollup order by `id_region`;

DROP TABLE IF EXISTS `vw_finance_real_year`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_finance_real_year` AS select `i_real_orgs`.`id_region` AS `id_region`,(sum(`i_real_orgs`.`amount_total`) / 1000000.000000) AS `real_sum`,sum(`i_real_orgs`.`total_qty`) AS `real_qty` from `i_real_orgs` where (`i_real_orgs`.`yy` = year(curdate())) group by `i_real_orgs`.`id_region` with rollup order by `id_region`;

DROP TABLE IF EXISTS `vw_finance_year`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_finance_year` AS select `i_money_orgs`.`id_reg` AS `id_region`,round((sum(`i_money_orgs`.`amount_paynet`) / 1000000.000000),6) AS `paynet_sum`,sum(`i_money_orgs`.`qty_paynet`) AS `paynet_qty`,round((sum(`i_money_orgs`.`amount_payme`) / 1000000.000000),6) AS `payme_sum`,sum(`i_money_orgs`.`qty_payme`) AS `payme_qty`,round((sum(`i_money_orgs`.`amount_click`) / 1000000.000000),6) AS `click_sum`,sum(`i_money_orgs`.`qty_click`) AS `click_qty`,round((sum(`i_money_orgs`.`amount_munis`) / 1000000.000000),6) AS `munis_sum`,sum(`i_money_orgs`.`qty_munis`) AS `munis_qty`,round((sum(`i_money_orgs`.`amount_apelsin`) / 1000000.000000),6) AS `apelsin_sum`,sum(`i_money_orgs`.`qty_apelsin`) AS `apelsin_qty` from `i_money_orgs` where (`i_money_orgs`.`yy` = year(curdate())) group by `i_money_orgs`.`id_reg` with rollup order by `i_money_orgs`.`id_reg`;

DROP TABLE IF EXISTS `vw_got_max_real_date`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_got_max_real_date` AS select max(`i_real_details`.`dt`) AS `dt` from `i_real_details`;

DROP TABLE IF EXISTS `vw_more_35_realize`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_more_35_realize` AS select `r`.`name_uz` AS `region`,`d`.`name_uz` AS `district`,`m`.`name` AS `mahalla`,`i`.`kod` AS `kod`,`i`.`abonent_name` AS `abonent`,ifnull(`i`.`36to40`,ifnull(`i`.`41to45`,ifnull(`i`.`46to50`,ifnull(`i`.`51to55`,ifnull(`i`.`56to60`,ifnull(`i`.`61to65`,ifnull(`i`.`66to70`,`i`.`71tomore`))))))) AS `ohirgi_realizatsiya` from ((((`i_real_ballons_details` `i` join `organizations` `o` on((`i`.`id_org` = `o`.`id`))) join `mahallas` `m` on((`i`.`id_mah` = `m`.`id`))) join `districts` `d` on((`o`.`id_district` = `d`.`id`))) join `regions` `r` on((`o`.`id_region` = `r`.`id`))) where ((`i`.`1to30` is null) and (`i`.`31to35` is null) and (`i`.`noneget` = '0') and (`i`.`is_need` = '1'));

DROP TABLE IF EXISTS `vw_new_faceid_data`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `vw_new_faceid_data` AS select `r`.`name_uz` AS `region`,`o`.`name` AS `raygas`,`d`.`abonent_kod` AS `abonent_kod`,`d`.`created_dt` AS `realize`,`f`.`recipient` AS `recipient`,`f`.`address` AS `address`,`f`.`kadastr` AS `kadastr`,`f`.`kadastr_owner` AS `kadastr_owner`,`f`.`pinfl` AS `pinfl`,if((`f`.`district_coato_id` = `f`.`kadastr_coato_id`),'mos','mos emas') AS `tuman` from (((`i_face_id_detail` `d` join `regions` `r` on((`r`.`id` = `d`.`id_region`))) join `organizations` `o` on((`o`.`id` = `d`.`id_org`))) join `i_face_id_recipients` `f` on((`f`.`id_detail` = `d`.`id`))) order by `d`.`id`;

DROP TABLE IF EXISTS `vw_real_ballons_more45`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `vw_real_ballons_more45` AS select `r`.`name_uz` AS `region`,`o`.`name` AS `organization`,`i`.`kod` AS `kod`,`i`.`abonent_name` AS `abonent_name`,`i`.`is_need` AS `is_need`,ifnull(`i`.`46to50`,ifnull(`i`.`51to55`,ifnull(`i`.`56to60`,ifnull(`i`.`61to65`,ifnull(`i`.`66to70`,ifnull(`i`.`71tomore`,ifnull((`i`.`noneget` = '1'),''))))))) AS `more45`,(to_days(curdate()) - to_days(ifnull(`i`.`46to50`,ifnull(`i`.`51to55`,ifnull(`i`.`56to60`,ifnull(`i`.`61to65`,ifnull(`i`.`66to70`,ifnull(`i`.`71tomore`,ifnull((`i`.`noneget` = '1'),''))))))))) AS `days` from (((`i_real_ballons_details` `i` join `organizations` `o` on((`o`.`id` = `i`.`id_org`))) join `regions` `r` on((`r`.`id` = `o`.`id_region`))) left join `mahallas` `m` on((`m`.`id` = `i`.`id_mah`))) where ((`i`.`1to30` is null) and (`i`.`31to35` is null) and (`i`.`36to40` is null) and (`i`.`41to45` is null) and (`i`.`is_need` = 2));

DROP TABLE IF EXISTS `vw_real_widgets_today_only`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_real_widgets_today_only` AS select `idx_real_dayli_by_orgs`.`id_region` AS `id_region`,round((sum(`idx_real_dayli_by_orgs`.`amount_real`) / 1000000.000000),6) AS `real_sum`,round((sum(`idx_real_dayli_by_orgs`.`amount_total`) / 1000000.000000),6) AS `total_sum`,sum(`idx_real_dayli_by_orgs`.`real_qty`) AS `real_qty`,sum(`idx_real_dayli_by_orgs`.`total_qty`) AS `total_qty` from `idx_real_dayli_by_orgs` where (`idx_real_dayli_by_orgs`.`dt` = curdate()) group by `idx_real_dayli_by_orgs`.`id_region` with rollup order by `id_region`;

DROP TABLE IF EXISTS `vw_region_deposits`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_region_deposits` AS select `r`.`id_region` AS `id_region`,round((sum(ifnull(`r`.`deposit`,0.0000)) / 1000000.000000),6) AS `deposit` from `organizations` `r` where (`r`.`orgtype_id` = '4') group by `r`.`id_region`;

DROP TABLE IF EXISTS `vw_reindexation_payments`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_reindexation_payments` AS with `cte` as (select `idx_dayli_by_orgs`.`dt` AS `dt`,sum(`idx_dayli_by_orgs`.`qty_all`) AS `qty_all`,sum(`idx_dayli_by_orgs`.`amount_all`) AS `amount_all` from `idx_dayli_by_orgs` where (`idx_dayli_by_orgs`.`dt` < '2022-08-28') group by `idx_dayli_by_orgs`.`dt` order by `idx_dayli_by_orgs`.`dt`), `deb` as (select count(0) AS `qty`,`brrgz`.`tb_gas_debit`.`dt_pay` AS `dt_pay` from `brrgz`.`tb_gas_debit` where (`brrgz`.`tb_gas_debit`.`dt_pay` < '2022-08-28') group by `brrgz`.`tb_gas_debit`.`dt_pay`) select `cte`.`dt` AS `dt`,`cte`.`qty_all` AS `qty_all`,`deb`.`qty` AS `qty` from (`cte` join `deb` on((`cte`.`dt` = `deb`.`dt_pay`))) where (`cte`.`qty_all` <> `deb`.`qty`);

DROP TABLE IF EXISTS `vw_reindexation_reals`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_reindexation_reals` AS with `cte` as (select `idx_real_dayli_by_orgs_details`.`dt` AS `dt`,count(0) AS `qty_all` from `idx_real_dayli_by_orgs_details` where (`idx_real_dayli_by_orgs_details`.`dt` < curdate()) group by `idx_real_dayli_by_orgs_details`.`dt`), `deb` as (select count(0) AS `qty`,`brrgz`.`tb_gas_credit`.`dt_action` AS `dt_action` from `brrgz`.`tb_gas_credit` where (`brrgz`.`tb_gas_credit`.`dt_action` < curdate()) group by `brrgz`.`tb_gas_credit`.`dt_action`) select `cte`.`dt` AS `dt`,`cte`.`qty_all` AS `qty_all`,`deb`.`qty` AS `real_qty` from (`cte` join `deb` on((`cte`.`dt` = `deb`.`dt_action`))) where (`cte`.`qty_all` <> `deb`.`qty`);

DROP TABLE IF EXISTS `vw_report_2_1`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_report_2_1` AS select `r`.`yy` AS `yil`,`r`.`dt` AS `sana`,`o`.`name` AS `HGT_bulimi`,`m`.`name` AS `mahalla`,concat('\'',`r`.`ballon_kod`) AS `ballon_kod`,`r`.`amount` AS `summa`,`r`.`real_at` AS `berilgan_vaqt`,concat('\'',`r`.`inspector_kod`) AS `inspector_kod`,concat('\'',`r`.`abonent_kod`) AS `abonent_kod`,`r`.`inspector_name` AS `inspector_name`,`r`.`abonent_name` AS `abonent_name`,`r`.`abonent_address` AS `abonent_address`,`r`.`abonent_phone` AS `abonent_phone`,`r`.`numb` AS `zayavka_raqami` from ((`i_real_details` `r` join `organizations` `o` on((`o`.`id` = `r`.`id_org`))) left join `mahallas` `m` on((`m`.`id` = `r`.`id_mah`))) where ((`o`.`id_region` = 8) and (`r`.`yy` = 2022)) limit 4000000,1000000;

-- 2026-03-28 07:57:21
