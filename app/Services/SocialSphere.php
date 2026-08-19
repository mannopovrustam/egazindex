<?php

namespace App\Services;

class SocialSphere {
    public function authify()
    {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) return 1;
        if ($_SERVER['PHP_AUTH_USER'] != env('SOCIALSPHERE_LOGIN','socsphere5') || $_SERVER['PHP_AUTH_PW'] != env('SOCIALSPHERE_PSW','h1ge-2fh0-5r1-zs3')) return 1;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        \Log::debug('SocialSphere: Response detected from ' . $ip);

        return 0; // Authentication successful
//    if (!in_array($ip, explode(',', env('ALLOW_IP')))) return json_encode(['result' => 202, 'data' => null, 'comments' => 'Wrong IP!'], JSON_UNESCAPED_UNICODE);
    }


    public function pullRequest($data) {

        if ($this->authify()) {
            \Log::error('SocialSphere: Authentication failed!');
            return response()->json(['api_status' => 0, 'api_message' => 'Authentication failed!', 'api_http' => 401]);
        }

        \Log::debug('SocialSphere: Request ' . json_encode($data));
        \DB::table('integration_logs')->insert(['module'=>'SocialSphere','payload'=>json_encode($data)]);

        try {
            \DB::beginTransaction();
            $params = $data['params'];
            if(!in_array($params['region_id'],[1,2,3,4,5,6,7,8,9,10,11,12,13,14,15])){
                return response()->json(['api_status' => 0, 'api_message' => 'Region is invalid!', 'api_http' => 401]);
            }

            if($params['region_id'] == 1) $params['region_id']=15;
            if($params['region_id'] == 2) $params['region_id']=13;
            if($params['region_id'] == 3) $params['region_id']=1;
            if($params['region_id'] == 4) $params['region_id']=2;
            if($params['region_id'] == 5) $params['region_id']=3;
            if($params['region_id'] == 6) $params['region_id']=4;
            if($params['region_id'] == 7) $params['region_id']=5;
            if($params['region_id'] == 8) $params['region_id']=6;
            if($params['region_id'] == 9) $params['region_id']=8;
            if($params['region_id'] == 10) $params['region_id']=7;
            if($params['region_id'] == 11) $params['region_id']=9;
            if($params['region_id'] == 12) $params['region_id']=10;
            if($params['region_id'] == 13) $params['region_id']=11;
            if($params['region_id'] == 14) $params['region_id']=12;
            if($params['region_id'] == 15) $params['region_id']=14;

            $inv_detail = [
                "request_id" => $params['request_id'],
                "id_region" => $params['region_id'],
                "id_district" => $params['district_id'],
                "organization" => $params['organization'],
                "partner" => $params['partner'],
                "partner_tin" => $params['partner_tin'],
                "inn" => $params['inn'],
                "realize_dt" => date("Y-m-d H:i:s", $params['realize_dt']),
                "accept_dt" => date("Y-m-d H:i:s", $params['accept_dt']),
                "realize_kg" => $params['realize_kg'],
                "accept_kg" => $params['accept_kg'],
                "realize_sum" => $params['realize_sum'],
                "amount" => $params['amount'],
                "items" => $params['items'],
                "contract" => $params['contract'],
                "invoice" => $params['invoice']
            ];
            // Asosiy egaz bazasi (brrgz MySQL) — egaz-indexator dagidek `mysql1`.
            // Nusxa `pgsql1` ga dual write orqali avtomatik ketadi (config/dual_write.php).
            \DB::connection('mysql1')->table('tb_social_sphere')->insertGetId($inv_detail);

            \DB::commit();
            return response()->json(['api_status' => 1, 'api_message' => 'Data inserted successfully', 'api_http' => 200],200);
        } catch (\Exception $ex) {
            \Log::error('SocialSphere: Database insert error: ' . $ex->getMessage());
            \DB::rollback();
            return response()->json(['api_status' => 0, 'api_message' => 'Database insert error: ' . $ex->getMessage(), 'api_http' => 500],500);
        }
    }

}