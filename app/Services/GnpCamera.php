<?php

namespace App\Services;

class GnpCamera {
    public function authify()
    {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) return 1;
        if ($_SERVER['PHP_AUTH_USER'] != env('CAMERA_LOGIN','gnp-cameras') || $_SERVER['PHP_AUTH_PW'] != env('CAMERA_PSW','eac-fc3-jt31-c3q5')) return 1;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        \Log::debug('GnpCamera: Response detected from ' . $ip);

//    if (!in_array($ip, explode(',', env('ALLOW_IP')))) return json_encode(['result' => 202, 'data' => null, 'comments' => 'Wrong IP!'], JSON_UNESCAPED_UNICODE);
    }


    public function pullRequest($data) {

        if ($this->authify()) return response()->json(['status' => 'error', 'message' => 'Unauthorized access!', 'data' => null]);

//        $this->authify();
/*        if (!isset($data['hash']) || !$data['hash']) return response()->json(['api_status' => 0, 'api_message' => '[hash] parameter missed!', 'api_http' => 401]);
        if (!isset($data['org_id']) || !$data['org_id']) return response()->json(['api_status' => 0, 'api_message' => '[org_id] parameter missed!', 'api_http' => 401]);
        $client_hash = strtolower($data['hash']);
        $secret = 'tarozi';
        $sys_hash = strtolower(sha1($data['org_id'] . $secret));*/
//        if ($sys_hash != $client_hash) return response()->json(['api_status' => 0, 'api_message' => 'Wrong hash sum!', 'api_http' => 401]);

        \Log::debug('GnpCamera: Request ' . json_encode($data));


        $detail = [
            'org_id' => $data['org_id'],
            'car_number' => $data['car_number'],
            'event_date' => date('Y-m-d H:i:s', strtotime($data['event_date'])),
            'photo' => $this->saveBase64ToFile($data['image'])
        ];

//        if (\DB::table('tb_gnp_camera_logs')->insert($detail)) return 1;

        return 1;
/*        $detail = [
            'org_id' => $data['org_id'],
            'car_number' => $data['car_number'],
            'weight' => $data['weight']*1.00,
            'event_date' => date('Y-m-d H:i:s', strtotime($data['event_date'])),
        ];*/

//        if (\DB::table('tb_scales_logs')->insert($detail)) return 1;

//        ClickhouseService::scales(json_encode($data));

//        return 1;
    }

    public function saveBase64ToFile($photo)
    {
        $photo = str_replace('data:image/jpeg;base64,', '', $photo);
        $photo = str_replace(' ', '+', $photo);
        $photo = base64_decode($photo);
        $filename = 'uploads/gnp-camera/' . uniqid() . '.jpg';
        $success = file_put_contents($filename, $photo);
        if ($success) return $filename;
        return false;
    }

}