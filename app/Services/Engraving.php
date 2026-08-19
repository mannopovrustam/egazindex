<?php

namespace App\Services;

class Engraving {
    public function authify()
    {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) return 1;
        if ($_SERVER['PHP_AUTH_USER'] != env('ENGRAVING_LOGIN','engraving5') || $_SERVER['PHP_AUTH_PW'] != env('ENGRAVING_PSW','h1g1-2fg5-521-5s3')) return 1;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        \Log::debug('Engraving: Response detected from ' . $ip);

        return 0; // Authentication successful
//    if (!in_array($ip, explode(',', env('ALLOW_IP')))) return json_encode(['result' => 202, 'data' => null, 'comments' => 'Wrong IP!'], JSON_UNESCAPED_UNICODE);
    }


    public function pullRequest($data) {

        if ($this->authify()) {
            \Log::error('Engraving: Authentication failed!');
            return response()->json(['api_status' => 0, 'api_message' => 'Authentication failed!', 'api_http' => 401]);
        }

        \Log::debug('Engraving: Request ' . json_encode($data));
        \DB::table('integration_logs')->insert(['module'=>'Engraving','payload'=>json_encode($data)]);

        try {
            return response()->json(['api_status' => 1, 'api_message' => 'Data inserted successfully', 'api_http' => 200]);
        } catch (\Exception $ex) {
            \Log::error('Engraving: Database insert error: ' . $ex->getMessage());
            return response()->json(['api_status' => 0, 'api_message' => 'Database insert error: ' . $ex->getMessage(), 'api_http' => 500]);
        }
    }

}