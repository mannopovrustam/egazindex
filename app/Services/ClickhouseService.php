<?php

namespace App\Services;
use ClickHouseDB\Client;

class ClickhouseService {
    public static function debit($date)
    {
        $clickhouse = app(Client::class);
        if (strtotime($date) > strtotime(date('Y-m-d'))) {
            \Log::info('Date is greater than today!');
            return;
        }
        // max size of variables
        ini_set('memory_limit', '4G');

        $version = (int)time(); // Ensure version is an integer

        $debits = \DB::connection('mysql1')->table('cms_users as u')
            ->select('d.id','d.id_abonent','u.name','u.dtbirth','u.mobile','u.sex','u.id_region','r.name_uz as region','u.id_district','t.name_uz as district','u.id_mahalla','m.name as mahalla','u.address','u.kadastr','u.kod','u.psp','u.pinfl','u.qty_family','u.qty_lives','u.deposit','u.created_at','u.insp as attach_insp','u.is_need','u.tp','u.avai_qty','d.dt_pay','d.amount','d.supplier','d.sys_bid','d.payer_name','d.payer_inn','d.payee_id','d.payer_branch','d.payer_account','o.name as org','d.payee_branch','d.payee_account','d.payee_inn','d.payee_name','d.pay_type',\DB::raw("JSON_OBJECT('id',d.id,'id_abonent',d.id_abonent,'dt_pay',d.dt_pay,'amount',d.amount,'descr',d.descr,'supplier',d.supplier,'sys_bid',d.sys_bid,'sys_tid',d.sys_tid,'payer_branch',d.payer_branch,'payer_account',d.payer_account,'payer_name',d.payer_name,'payer_inn',d.payer_inn,'payee_branch',d.payee_branch,'payee_account',d.payee_account,'payee_id',d.payee_id,'payee_inn',d.payee_inn,'payee_name',d.payee_name,'purpose_code',d.purpose_code,'purpose_text',d.purpose_text,'amount_cur',d.amount_cur,'pay_type',d.pay_type,'st',d.st,'created_at',d.created_at,'updated_at',d.updated_at,'confirmed_at',d.confirmed_at) as payload"),'d.st','d.confirmed_at',\DB::raw("$version as version"))
            ->join('regions as r', 'r.id', '=', 'u.id_region')
            ->join('districts as t', 't.id', '=', 'u.id_district')
            ->join('mahallas as m', 'm.id', '=', 'u.id_mahalla')
            ->leftjoin('tb_gas_debit as d', 'd.id_abonent', '=', 'u.id')
            ->leftjoin('organizations as o', 'o.id', '=', 'd.payee_id')
            ->where('d.dt_pay', $date)
            // Aslida `orderBy('d.id','d.sys_bid','d.dt_pay')` edi: 2-argument yo'nalish (direction)
            // bo'lgani uchun L5.5 buni "d.id DESC" deb tushunardi (3-argument umuman e'tiborsiz).
            // L13 esa noto'g'ri yo'nalishda InvalidArgumentException tashlaydi, shuning uchun
            // AYNAN o'sha natijani beradigan ko'rinish yozildi.
            ->orderBy('d.id', 'desc')
            ->get();

        if (!$debits || count($debits) == 0) {
            \Log::info('No data found for date: ' . $date);
            return 0;
        }
        \Log::info('Found: ' . count($debits) . ' data');

        $qty = count($debits);
        $debits = json_decode(json_encode($debits, JSON_UNESCAPED_UNICODE), true);
        \Log::info('json encode decode data');


// Clean and validate data
        foreach ($debits as $index => &$row) {
            // Cast UInt32 fields to integers
            $row['id'] = (int)$row['id'];
            $row['id_abonent'] = (int)$row['id_abonent'];
            $row['id_region'] = (int)$row['id_region'];
            $row['id_district'] = (int)$row['id_district'];
            $row['id_mahalla'] = (int)$row['id_mahalla'];
            $row['qty_family'] = (int)$row['qty_family'];
            $row['qty_lives'] = (int)$row['qty_lives'];
            $row['avai_qty'] = (int)$row['avai_qty'];
            $row['payee_id'] = isset($row['payee_id']) ? (int)$row['payee_id'] : 0;
            $row['sys_bid'] = isset($row['sys_bid']) ? (int)$row['sys_bid'] : 0;
            $row['version'] = (int)$version;

            // Ensure UTF-8 for strings
            $row['name'] = mb_convert_encoding($row['name'], 'UTF-8', 'UTF-8');
            $row['region'] = mb_convert_encoding($row['region'], 'UTF-8', 'UTF-8');
            $row['district'] = mb_convert_encoding($row['district'], 'UTF-8', 'UTF-8');
            $row['mahalla'] = mb_convert_encoding($row['mahalla'], 'UTF-8', 'UTF-8');
            $row['address'] = mb_convert_encoding($row['address'], 'UTF-8', 'UTF-8');
            $row['payer_name'] = mb_convert_encoding($row['payer_name'], 'UTF-8', 'UTF-8');
            $row['payee_name'] = mb_convert_encoding($row['payee_name'], 'UTF-8', 'UTF-8');
            $row['org'] = mb_convert_encoding($row['org'], 'UTF-8', 'UTF-8');

            // Fix payload JSON
            if (isset($row['payload'])) {
                \Log::info('start payload');
                $payload = json_decode($row['payload'], true);
                \Log::info('end payload');
                if ($payload) {
                    $payload['id'] = (int)$payload['id'];
                    $payload['id_abonent'] = (int)$payload['id_abonent'];
                    $payload['sys_bid'] = isset($payload['sys_bid']) ? (int)$payload['sys_bid'] : 0;
                    $payload['payee_id'] = isset($payload['payee_id']) ? (int)$payload['payee_id'] : 0;
                    $row['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
                }
            }

            // Log invalid data
            if (!is_numeric($row['id']) || !is_numeric($row['id_abonent'])) {
                \Log::error("Invalid data at index $index: ");
                unset($debits[$index]);
            }
        }
        unset($row);
        $debits = array_values($debits);

        \Log::info('Inserting data to clickhouse cl_gas_debit table...');
        try {
            $clickhouse->settings()->set('input_format_allow_errors_num', 10);
            $clickhouse->settings()->set('input_format_allow_errors_ratio', 0.1);
            $clickhouse->insert('cl_egaz.cl_gas_debit', $debits, ['id','id_abonent','name','dtbirth','mobile','sex','id_region','region','id_district','district','id_mahalla','mahalla','address','kadastr','kod','psp','pinfl','qty_family','qty_lives','deposit','created_at','attach_insp','is_need','tp','avai_qty','dt_pay','amount','supplier','sys_bid','payer_name','payer_inn','payee_id','payer_branch','payer_account','org','payee_branch','payee_account','payee_inn','payee_name','pay_type','payload','st','confirmed_at','version']);
            \Log::info('Successfully inserted ' . $qty . ' rows');
        } catch (\Exception $e) {
            \Log::error('ClickHouse insert failed: ' . $e->getMessage() .'; line ' . $e->getLine());
            \Log::error('Failed data sample: ' . json_encode(array_slice($debits, 0, 5), JSON_UNESCAPED_UNICODE));
            throw $e;
        }

        return $qty;

    }

    public static function amend($sys_byd){
        $clickhouse = app(Client::class);

        $version = time();

        $debits = \DB::connection('mysql1')->table('cms_users as u')
            ->select('d.id','d.id_abonent','u.name','u.dtbirth','u.mobile','u.sex','u.id_region','r.name_uz as region','u.id_district','t.name_uz as district','u.id_mahalla','m.name as mahalla','u.address','u.kadastr','u.kod','u.psp','u.pinfl','u.qty_family','u.qty_lives','u.deposit','u.created_at','u.insp as attach_insp','u.is_need','u.tp','u.avai_qty','d.dt_pay','d.amount','d.supplier','d.sys_bid','d.payer_name','d.payer_inn','d.payee_id','d.payer_branch','d.payer_account','o.name as org','d.payee_branch','d.payee_account','d.payee_inn','d.payee_name','d.pay_type','d.st','d.confirmed_at',\DB::raw("$version as version"))
            ->join('regions as r', 'r.id', '=', 'u.id_region')
            ->join('districts as t', 't.id', '=', 'u.id_district')
            ->join('mahallas as m', 'm.id', '=', 'u.id_mahalla')
            ->leftjoin('tb_gas_debit as d', 'd.id_abonent', '=', 'u.id')
            ->leftjoin('organizations as o', 'o.id', '=', 'd.payee_id')
            ->where('d.sys_bid', $sys_byd)
            ->get();
        // to array with utf8 encoding
        if(!$debits || count($debits) == 0) {
            \Log::info('No data found for sys_byd: '.$sys_byd);
            return 0;
        }

        $qty = count($debits);
        $debits = json_decode(json_encode($debits), true);

        \Log::info('Amending data in clickhouse cl_gas_debit table by sys_bid...');
        $clickhouse->insert('cl_egaz.cl_gas_debit', $debits);

        \Log::info('Data changed in clickhouse cl_gas_debit table!');
        return $qty;

    }

    public static function real($date){
        $clickhouse = app(Client::class);
        if(strtotime($date) > strtotime(date('Y-m-d'))){
            \Log::info('Date is greater than today!');
            return;
        }
        ini_set('memory_limit', '25G');
        \Log::info('Date: '.$date);
        $version = (int)time();

        $realDetails = \DB::connection('mysql1')->table('tb_requests_ballons as rb')
            ->select(\DB::raw("r.id as r_id,r.numb as r_numb,r.id_gns as r_id_gns,o.name as gns,r.id_raygas as r_id_raygas, o2.name as raygas, rg.id as r_id_region, rg.name_uz as r_region, ds.id as r_id_district, ds.name_uz as r_district, r.id_rzvr as r_id_rzvr,rv.name as rzvr_name,r.applied_to as r_applied_to,r.created_by as r_created_by,r.dt_creation as r_dt_creation,r.accepted_qty as r_accepted_qty,r.passed_qty as r_passed_qty,r.returned_qty as r_returned_qty,r.amount as r_amount,r.bhash as r_bhash,r.accepted_by as r_accepted_by,r.dt_acception as r_dt_acception,r.descr as r_descr,r.id_driver as r_id_driver,r.id_vehicle as r_id_vehicle,r.created_at as r_created_at,v.id_gps as v_id_gps,v.name as v_name,v.number as v_number,v.man_year as v_man_year,v.weight as v_weight,v.id_org as v_id_org,vo.name as v_org,rb.id as rb_id,rb.id_request as rb_id_request,rb.id_ballon as rb_id_ballon,rb.price as rb_price,rb.id_abonent as rb_id_abonent,rb.id_relation as rb_id_relation,rb.passed_by as rb_passed_by,rb.location_lon as rb_location_lon,rb.location_lat as rb_location_lat,rb.passed_at as rb_passed_at,rb.dt as rb_dt,rb.created_at as rb_created_at,JSON_OBJECT('tb_requests', JSON_OBJECT('id', r.id,'numb', r.numb,'id_gns', r.id_gns,'id_raygas', r.id_raygas,'id_rzvr', r.id_rzvr,'applied_to', r.applied_to,'created_by', r.created_by,'dt_creation', r.dt_creation,'accepted_qty', r.accepted_qty,'passed_qty', r.passed_qty,'returned_qty', r.returned_qty,'amount', r.amount,'bhash', r.bhash,'accepted_by', r.accepted_by,'dt_acception', r.dt_acception,'descr', r.descr,'id_driver', r.id_driver,'id_vehicle', r.id_vehicle,'created_at', r.created_at,'updated_at', r.updated_at),'tb_requests_ballons',JSON_OBJECT('id', rb.id,'id_request', rb.id_request,'id_ballon', rb.id_ballon,'price', rb.price,'id_abonent', rb.id_abonent,'id_relation', rb.id_relation,'passed_by', rb.passed_by,'location_lon', rb.location_lon,'location_lat', rb.location_lat,'passed_at', rb.passed_at,'dt', rb.dt,'created_at', rb.created_at,'updated_at', rb.updated_at)) AS payload, $version as version"))
            ->join('tb_requests as r', 'r.id', '=', 'rb.id_request')
            ->join('organizations as o', 'o.id', '=', 'r.id_gns')
            ->join('organizations as o2', 'o2.id', '=', 'r.id_raygas')
            ->join('regions as rg', 'o2.id_region', '=', 'rg.id')
            ->join('districts as ds', 'o2.id_district', '=', 'ds.id')
            ->join('tb_rezervuars as rv', 'r.id_rzvr', '=', 'rv.id')
            ->leftjoin('vehicles as v', 'v.id', '=', 'r.id_vehicle')
            ->leftjoin('organizations as vo', 'vo.id', '=', 'v.id_org')
            ->whereNotNull('rb.id_abonent')
            ->where('rb.dt', $date)
            ->get();

        \Log::info('Data found for date: '.$date);

        $realDetails = json_decode(json_encode($realDetails, JSON_UNESCAPED_UNICODE), true);

        \Log::info('Inserting data to clickhouse cl_realization table...');
        $clickhouse->insert('cl_egaz.cl_realization', $realDetails, ['r_id','r_numb','r_id_gns','gns','r_id_raygas','raygas','r_id_region','r_region','r_id_district','r_district','r_id_rzvr','rzvr_name','r_applied_to','r_created_by','r_dt_creation','r_accepted_qty','r_passed_qty','r_returned_qty','r_amount','r_bhash','r_accepted_by','r_dt_acception','r_descr','r_id_driver','r_id_vehicle','r_created_at','v_id_gps','v_name','v_number','v_man_year','v_weight','v_id_org','v_org','rb_id','rb_id_request','rb_id_ballon','rb_price','rb_id_abonent','rb_id_relation','rb_passed_by','rb_location_lon','rb_location_lat','rb_passed_at','rb_dt','rb_created_at','payload','version']);

        \Log::info('Data inserted to clickhouse cl_realization table!');
        return count($realDetails);

    }

    public static function amending($date)
    {
        $clickhouse = app(Client::class);

        if (strtotime($date) > strtotime(date('Y-m-d'))) {
            \Log::info('Date is greater than today!');
            return;
        }

        ini_set('memory_limit', '4G');

        $amends = \DB::connection('mysql1')->table('tb_amending as a')
            ->select('a.id', 'r.id as id_region', 'd.id as id_district', 'o.id as id_org', 'm.id as id_mahalla', 'u.id as id_abonent',
                'r.name_uz as region_name', 'd.name_uz as district_name', 'o.name as org_name', 'm.name as mahalla_name',
                'u.id as abonent_id', 'u.kod as abonent_kod', 'u.name as abonent_name',
                'h.kod as inspektor_kod', 'h.name as inspektor_name',
                'a.info', 'a.entry_by', 'a.created_at')
            ->join('cms_users as u', 'a.id_moniker', '=', 'u.id')
            ->join('cms_users as h', 'a.entry_by', '=', 'h.id')
            ->join('regions as r', 'u.id_region', '=', 'r.id')
            ->join('districts as d', 'u.id_district', '=', 'd.id')
            ->join('organizations as o', 'u.id_org', '=', 'o.id')
            ->join('mahallas as m', 'm.id', '=', 'u.id_mahalla')
            ->where(\DB::raw("DATE(a.created_at)"), $date)
            ->get();
        // to array with utf8 encoding
        if (!$amends || count($amends) == 0) {
            \Log::info('No data found for DATE: ' . $date);
            return 0;
        }

        $qty = count($amends);
        $amends = json_decode(json_encode($amends), true);

        \Log::info('Amending data in clickhouse cl_amending table by date...');
        $clickhouse->insert('cl_egaz.cl_amending', $amends);

        \Log::info('Data changed in clickhouse cl_amending table!');
        return $qty;

    }

    public static function optimize($table) {
        $clickhouse = app(Client::class);
        $clickhouse->write("OPTIMIZE TABLE cl_egaz.$table FINAL");
    }

    public static function scales($data){
        $clickhouse = app(Client::class);

        $scales = json_decode($data, true);
        unset($scales['hash']);

        $detail = [
            'org_id' => $scales['org_id'],
            'car_number' => $scales['car_number'],
            'weight' => $scales['weight']*1.00,
            'event_date' => strtotime($scales['event_date']),
            'version' => time()
        ];

        \Log::info('Amending data in clickhouse cl_scales_logs table by sys_bid...'.json_encode($scales));
        $clickhouse->insert('cl_scales_logs', [$detail], ['org_id', 'car_number', 'weight', 'event_date', 'version']);

        \Log::info('Data changed in clickhouse cl_scales_logs table!');
        return 1;

    }

    public static function abonent($id_org)
    {
        $clickhouse = app(Client::class);

        ini_set('memory_limit', '4G');

        $abonent = \DB::connection('mysql1')->table('cms_users')->where('id_org', $id_org)->where('id_cms_privileges', 4)->get();
        // to array with utf8 encoding
        if (!$abonent || count($abonent) == 0) {
            \Log::info('No data found for ORG_ID: ' . $id_org);
            return 0;
        }

        $qty = count($abonent);
        $abonent = json_decode(json_encode($abonent), true);

        \Log::info('Amending data in clickhouse cl_users table by date...');
        $clickhouse->insert('cl_egaz.cl_users', $abonent);

        \Log::info('Data changed in clickhouse cl_users table!');
        return $qty;

    }

    /**
     * MySQL brrgz.tb_amending dan ma'lumotlarni
     * ClickHouse tb_amending jadvaliga sinxronlash (raw sync)
     */
    public static function syncAmending($date)
    {
        $clickhouse = app(Client::class);

        ini_set('memory_limit', '4G');

        // MySQL brrgz dan shu sanadagi tb_amending yozuvlarini olish
        $rows = \DB::connection('mysql1')->table('tb_amending')
            ->whereRaw("DATE(created_at) = ?", [$date])
            ->get();

        if (!$rows || count($rows) == 0) {
            \Log::info('syncAmending: No amending data for date: ' . $date);
            return 0;
        }

        $qty = count($rows);

        // ClickHouse'da shu sanadagi eski ma'lumotlarni o'chirish
        $clickhouse->write("ALTER TABLE cl_egaz.tb_amending DELETE WHERE toDate(created_at) = '{$date}'");

        // Ma'lumotlarni ClickHouse formatiga o'tkazish
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id'         => (int)$row->id,
                'id_moniker' => (int)$row->id_moniker,
                'module'     => $row->module ?? '',
                'entry_by'   => (int)$row->entry_by,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'info'       => mb_convert_encoding($row->info ?? '', 'UTF-8', 'UTF-8'),
            ];
        }

        \Log::info('syncAmending: Inserting ' . count($data) . ' rows to ClickHouse tb_amending...');
        $clickhouse->insert('cl_egaz.tb_amending', $data, [
            'id', 'id_moniker', 'module', 'entry_by', 'created_at', 'updated_at', 'info'
        ]);

        \Log::info('syncAmending: Done for date ' . $date . ', rows: ' . $qty);
        return $qty;
    }

    /**
     * ClickHouse tb_amending dan status o'zgarishlarni o'qib,
     * ClickHouse tb_subscriber_daily_status ga yozish
     *
     * Oqim: MySQL tb_amending -> CH tb_amending -> CH tb_subscriber_daily_status
     */
    public static function subscriberDailyStatus($date)
    {
        $clickhouse = app(Client::class);

        if (strtotime($date) > strtotime(date('Y-m-d'))) {
            \Log::info('subscriberDailyStatus: Date is greater than today!');
            return 0;
        }

        ini_set('memory_limit', '4G');

        // 1-qadam: MySQL brrgz.tb_amending -> ClickHouse tb_amending
        \Log::info('subscriberDailyStatus: Step 1 - syncing tb_amending for date: ' . $date);
        $syncedRows = self::syncAmending($date);
        \Log::info('subscriberDailyStatus: Synced amending rows: ' . $syncedRows);

        // 2-qadam: ClickHouse tb_amending -> tb_subscriber_daily_status
        $clickhouse->write("ALTER TABLE cl_egaz.tb_subscriber_daily_status DELETE WHERE event_date = '{$date}'");

        // ClickHouse ichida INSERT...SELECT
        $sql = "
            INSERT INTO cl_egaz.tb_subscriber_daily_status
            SELECT
                toDate(created_at + INTERVAL 5 HOUR) AS log_date,
                id_moniker,
                argMax(
                    extract(info, '(?i)\\\\[status\\\\]:.*?-->\\s*([a-zA-Z]+)'),
                    created_at + INTERVAL 5 HOUR
                ) AS final_status,
                max(created_at + INTERVAL 5 HOUR) AS last_update
            FROM cl_egaz.tb_amending
            WHERE match(info, '(?i)\\\\[status\\\\]')
              AND module = 'subscribers'
              AND toDate(created_at) = '{$date}'
            GROUP BY
                log_date,
                id_moniker
            ORDER BY log_date
        ";

        $clickhouse->write($sql);

        // Natijani tekshirish
        $result = $clickhouse->select("SELECT count() as cnt FROM cl_egaz.tb_subscriber_daily_status WHERE event_date = '{$date}'");
        $cnt = $result->rows()[0]['cnt'] ?? 0;

        \Log::info('subscriberDailyStatus: Done for date ' . $date . ', rows: ' . $cnt);
        return $cnt;
    }

    /**
     * ClickHouse tb_subscriber_daily_status + cms_users dan
     * tb_subscriber_geo_daily_stats ga agregatlangan hisobot yaratish
     */
    public static function subscriberGeoStats($date)
    {
        $clickhouse = app(Client::class);

        if (strtotime($date) > strtotime(date('Y-m-d'))) {
            \Log::info('subscriberGeoStats: Date is greater than today!');
            return 0;
        }

        ini_set('memory_limit', '4G');

        // ClickHouse'da shu sanadagi eski geo stats ni o'chirish
        $clickhouse->write("ALTER TABLE cl_egaz.tb_subscriber_geo_daily_stats DELETE WHERE event_date = '{$date}'");

        // ClickHouse ichida SQL orqali to'g'ridan-to'g'ri INSERT qilish
        // tb_subscriber_daily_status + cms_users (ClickHouse'dagi nusxa) dan
        $sql = "
            INSERT INTO cl_egaz.tb_subscriber_geo_daily_stats
            SELECT
                l.event_date AS event_date,
                u.id_org AS id_org,
                u.id_region AS id_region,
                u.id_mahalla AS id_mahalla,
                l.status AS status,
                count(l.id_moniker) AS total_subscribers
            FROM cl_egaz.tb_subscriber_daily_status AS l
            INNER JOIN cl_egaz.cms_users AS u ON l.id_moniker = u.id
            WHERE l.event_date = '{$date}'
            GROUP BY
                event_date,
                id_org,
                id_region,
                id_mahalla,
                status
        ";

        $clickhouse->write($sql);

        // Natijani tekshirish
        $result = $clickhouse->select("SELECT count() as cnt FROM cl_egaz.tb_subscriber_geo_daily_stats WHERE event_date = '{$date}'");
        $cnt = $result->rows()[0]['cnt'] ?? 0;

        \Log::info('subscriberGeoStats: Done for date ' . $date . ', rows: ' . $cnt);
        return $cnt;
    }

    /**
     * MySQL brrgz.cms_users dan yangi qo'shilgan abonentlarni
     * ClickHouse cms_users jadvaliga sinxronlash
     */
    public static function syncNewSubscribers($date)
    {
        $clickhouse = app(Client::class);

        ini_set('memory_limit', '4G');

        $newUsers = \DB::connection('mysql1')->table('cms_users')
            ->whereRaw("DATE(created_at) = ?", [$date])
            ->where('id_cms_privileges', 4)
            ->get();

        if (!$newUsers || count($newUsers) == 0) {
            \Log::info('syncNewSubscribers: No new subscribers for date: ' . $date);
            return 0;
        }

        $qty = count($newUsers);

        $ids = $newUsers->pluck('id')->toArray();
        $idsStr = implode(',', $ids);
        $clickhouse->write("ALTER TABLE cl_egaz.cms_users DELETE WHERE id IN ({$idsStr})");

        $data = [];
        foreach ($newUsers as $user) {
            $data[] = [
                'id' => (int)$user->id,
                'name' => $user->name,
                'photo' => $user->photo,
                'email' => $user->email,
                'password' => $user->password,
                'password_dt' => $user->password_dt,
                'id_cms_privileges' => $user->id_cms_privileges ? (int)$user->id_cms_privileges : null,
                'mobile' => $user->mobile,
                'status' => $user->status,
                'dtbirth' => $user->dtbirth,
                'lastlogin' => $user->lastlogin,
                'sex' => $user->sex ?? '1',
                'id_org' => $user->id_org ? (int)$user->id_org : null,
                'id_region' => (int)$user->id_region,
                'id_district' => $user->id_district ? (int)$user->id_district : null,
                'id_mahalla' => $user->id_mahalla ? (int)$user->id_mahalla : null,
                'contract_num' => $user->contract_num ?? '',
                'contract_dt' => $user->contract_dt,
                'contract_filled_by' => $user->contract_filled_by ? (int)$user->contract_filled_by : null,
                'is_yurid' => (int)($user->is_yurid ?? 2),
                'level' => (int)($user->level ?? 1),
                'mahalla' => $user->mahalla,
                'address' => $user->address ?? '',
                'kadastr' => $user->kadastr,
                'kadastr_owner' => $user->kadastr_owner,
                'kod' => $user->kod,
                'psp' => $user->psp,
                'pinfl' => $user->pinfl ?? '',
                'qty_family' => (int)($user->qty_family ?? 1),
                'qty_lives' => (int)($user->qty_lives ?? 1),
                'home_type' => $user->home_type ?? '3',
                'entry_by' => $user->entry_by ? (int)$user->entry_by : null,
                'deposit' => (float)($user->deposit ?? 0),
                'trgflag' => $user->trgflag ?? '1',
                'rdt' => $user->rdt,
                'next_rdt' => $user->next_rdt,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'sync_st' => $user->sync_st ?? '1',
                'insp' => $user->insp,
                'avai_qty' => (int)($user->avai_qty ?? 1),
                'tp' => $user->tp ?? 'AB',
                'last_req' => $user->last_req ? (int)$user->last_req : null,
                'is_need' => (int)($user->is_need ?? 1),
                'verified' => (int)($user->verified ?? 0),
                'verify_kod' => $user->verify_kod,
                'is_nfc' => $user->is_nfc ?? '2',
                'auth_token' => $user->auth_token,
                'is_banned' => $user->is_banned ? (int)$user->is_banned : null,
                'eimzo_key' => $user->eimzo_key,
            ];
        }

        \Log::info('syncNewSubscribers: Inserting ' . count($data) . ' new subscribers to ClickHouse cms_users...');
        $clickhouse->insert('cl_egaz.cms_users', $data, [
            'id', 'name', 'photo', 'email', 'password', 'password_dt',
            'id_cms_privileges', 'mobile', 'status', 'dtbirth', 'lastlogin',
            'sex', 'id_org', 'id_region', 'id_district', 'id_mahalla',
            'contract_num', 'contract_dt', 'contract_filled_by', 'is_yurid',
            'level', 'mahalla', 'address', 'kadastr', 'kadastr_owner', 'kod',
            'psp', 'pinfl', 'qty_family', 'qty_lives', 'home_type', 'entry_by',
            'deposit', 'trgflag', 'rdt', 'next_rdt', 'created_at', 'updated_at',
            'sync_st', 'insp', 'avai_qty', 'tp', 'last_req', 'is_need',
            'verified', 'verify_kod', 'is_nfc', 'auth_token', 'is_banned', 'eimzo_key'
        ]);

        \Log::info('syncNewSubscribers: Done for date ' . $date . ', rows: ' . $qty);
        return $qty;
    }

}
