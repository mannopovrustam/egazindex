<?php

namespace App\Services;

class GasDispenser {
    public function authify()
    {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) return 1;
        if ($_SERVER['PHP_AUTH_USER'] != env('DISPENSER_LOGIN','dispenser') || $_SERVER['PHP_AUTH_PW'] != env('DISPENSER_PSW','r1gw-2345-f12-5s3')) return 1;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        \Log::debug('GasDispenser: Response detected from ' . $ip);

        return 0; // Authentication successful
//    if (!in_array($ip, explode(',', env('ALLOW_IP')))) return json_encode(['result' => 202, 'data' => null, 'comments' => 'Wrong IP!'], JSON_UNESCAPED_UNICODE);
    }


    public function pullRequest($data) {

        if ($this->authify()) {
            \Log::error('GasDispenser: Authentication failed!');
            return response()->json(['api_status' => 0, 'api_message' => 'Authentication failed!', 'api_http' => 401]);
        }

//        if (!isset($data['hash']) || !$data['hash']) return response()->json(['api_status' => 0, 'api_message' => '[hash] parameter missed!', 'api_http' => 401]);
        if (!isset($data['org_id']) || !$data['org_id']) {
            \Log::error('GasDispenser: [org_id] parameter missed!');
            return response()->json(['api_status' => 0, 'api_message' => '[org_id] parameter missed!', 'api_http' => 401]);
        }

        \Log::debug('GasDispenser: Request ' . json_encode($data));
        \DB::table('integration_logs')->insert(['module'=>'GasDispenser','payload'=>json_encode($data)]);

        $detail = [
            'org_id' => $data['org_id'],
            'vaqt' => $data['vaqt'],
            'kg' => $data['kg']*1.000,
            'device' => $data['device']
        ];

        try {
            \DB::table('tb_gas_dispensers')->insert($detail);
            return response()->json(['api_status' => 1, 'api_message' => 'Data inserted successfully', 'api_http' => 200]);
        } catch (\Exception $ex) {
            \Log::error('GasDispenser: Database insert error: ' . $ex->getMessage());
            return response()->json(['api_status' => 0, 'api_message' => 'Database insert error: ' . $ex->getMessage(), 'api_http' => 500]);
        }
    }

}