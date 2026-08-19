<?php

namespace App\Services;

class UzGPS {
    public function authify() {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) return 1;
        if ($_SERVER['PHP_AUTH_USER'] != env('UzGPS_LOGIN','uzgps-loc') || $_SERVER['PHP_AUTH_PW'] != env('UzGPS_PSW','pcs-513s-7t2w-saw1')) return 1;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        \Log::debug('UzGPS: Response detected from ' . $ip);

//    if (!in_array($ip, explode(',', env('ALLOW_IP')))) return json_encode(['result' => 202, 'data' => null, 'comments' => 'Wrong IP!'], JSON_UNESCAPED_UNICODE);
    }


    public function pullRequest($data,$ver) {
        if ($this->authify()) return response()->json(['status' => 'error', 'message' => 'Unauthorized access!', 'data' => null]);
        \Log::debug('UzGPS: Request ' . $ver .' '. json_encode($data));
    }
}