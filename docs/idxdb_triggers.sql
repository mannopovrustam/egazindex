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

--------------------------------------------------
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

--------------------------------------------------
CREATE TRIGGER `updateKod` BEFORE UPDATE ON `cms_users` FOR EACH ROW
BEGIN

END;;

--------------------------------------------------
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

--------------------------------------------------
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

--------------------------------------------------
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

--------------------------------------------------
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

--------------------------------------------------
CREATE TRIGGER `insert_i_deposit_details` AFTER INSERT ON `i_deposit_details` FOR EACH ROW
BEGIN
    insert into i_deposit_orgs (mm, yy, id_org, id_mah, id_reg, deposit, kg, amount, real_amount) values (NEW.mm, NEW.yy, NEW.id_org, NEW.id_mah, (SELECT id_region FROM organizations o where o.id = NEW.id_org), NEW.deposit,NEW.kg, NEW.amount, NEW.real_amount) ON DUPLICATE KEY UPDATE deposit=deposit + NEW.deposit, kg=kg + NEW.kg, amount=amount + NEW.amount, real_amount=real_amount + NEW.real_amount;
END;;

--------------------------------------------------
CREATE TRIGGER `plus_deposit_org` AFTER INSERT ON `i_money_details` FOR EACH ROW
BEGIN
 UPDATE organizations set deposit = deposit + NEW.amount where id = NEW.id_org;
END;;

--------------------------------------------------
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

--------------------------------------------------
CREATE TRIGGER `insert_i_real_details` AFTER INSERT ON `i_real_details` FOR EACH ROW
BEGIN INSERT INTO i_real_orgs (dt, yy, mm, id_region, id_org, id_mah, amount_total, total_qty) VALUES (NEW.dt, NEW.yy, MONTH(NEW.dt), (SELECT id_region FROM organizations o where o.id = NEW.id_org limit 1), NEW.id_org, NEW.id_mah, NEW.amount, 1) ON DUPLICATE KEY UPDATE amount_total = amount_total + NEW.amount, total_qty = total_qty + 1; END;;

--------------------------------------------------
CREATE TRIGGER `minus_deposit_orgs` AFTER INSERT ON `i_real_details` FOR EACH ROW
BEGIN
 UPDATE organizations set deposit = deposit - NEW.amount where id = NEW.id_org;
END;;

--------------------------------------------------
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

--------------------------------------------------
CREATE TRIGGER `tb_scales_logs_bi` BEFORE INSERT ON `tb_scales_logs` FOR EACH ROW
begin
    set new.org_id = case new.org_id
                             when 1 then 13
                             when 239 then 350
                             else new.org_id
    end;
end;;

--------------------------------------------------
