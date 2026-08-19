<?php

namespace App\Services;

class Scales {
    public function authify()
    {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) return 1;
        if ($_SERVER['PHP_AUTH_USER'] != env('SCALES_LOGIN','scales-weg') || $_SERVER['PHP_AUTH_PW'] != env('SCALES_PSW','rgw-345-f142-5se3')) return 1;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        \Log::debug('Scales: Response detected from ' . $ip);

        return 0; // Authentication successful
//    if (!in_array($ip, explode(',', env('ALLOW_IP')))) return json_encode(['result' => 202, 'data' => null, 'comments' => 'Wrong IP!'], JSON_UNESCAPED_UNICODE);
    }


    public function pullRequest($data) {

        if ($this->authify()) {
            \Log::error('Scales: Authentication failed!');
            return response()->json(['api_status' => 0, 'api_message' => 'Authentication failed!', 'api_http' => 401]);
        }

//        if (!isset($data['hash']) || !$data['hash']) return response()->json(['api_status' => 0, 'api_message' => '[hash] parameter missed!', 'api_http' => 401]);
        if (!isset($data['org_id']) || !$data['org_id']) {
            \Log::error('Scales: [org_id] parameter missed!');
            return response()->json(['api_status' => 0, 'api_message' => '[org_id] parameter missed!', 'api_http' => 401]);
        }

        \Log::debug('Scales: Request ' . json_encode($data));

        $detail = [
            'org_id' => $data['org_id'],
            'car_number' => $data['car_number'],
            'weight' => $data['weight']*1.00,
            'event_date' => date('Y-m-d H:i:s', strtotime($data['event_date'])),
            'photo' => $this->saveBase64ToFile($data['image'])
        ];

        try {
            // DB trigger `tb_scales_logs_bi` (BEFORE INSERT) — tarozi yuboradigan
            // noto'g'ri org_id larni tuzatadi (1→13, 239→350)
            \App\Services\DbTriggers\TriggerBus::insert('tb_scales_logs', $detail);
            return 1;
        } catch (\Exception $ex) {
            \Log::error('Scales: Database insert error: ' . $ex->getMessage());
            return response()->json(['api_status' => 0, 'api_message' => 'Database insert error: ' . $ex->getMessage(), 'api_http' => 500]);
        }

//        ClickhouseService::scales(json_encode($data));

        return 1;
    }

    public function gotPhoto($data) {

        if ($this->authify()) return response()->json(['api_status' => 0, 'api_message' => 'Authentication failed!', 'api_http' => 401]);

        if (!isset($data['id']) || !$data['id']) return response()->json(['api_status' => 0, 'api_message' => '[id] parameter missed!', 'api_http' => 401]);

        $id = $data['id'];
        $photo = \DB::table('tb_scales_logs')->where('id', $id)->select('photo')->first()->photo;

        if (empty($photo)) {
            \Log::error('Scales: Photo not found for ID ' . $id);
            return response()->json(['api_status' => 0, 'api_message' => 'Photo not found!', 'api_http' => 404]);
        }
        $base64 = $this->ImgToBase64($photo);
        if (empty($base64)) {
            \Log::error('Scales: Failed to convert photo to base64 for ID ' . $id);
            return response()->json(['api_status' => 0, 'api_message' => 'Failed to convert photo to base64!', 'api_http' => 500]);
        }
        return response()->json(['api_status' => 1, 'api_message' => 'Photo retrieved successfully!', 'api_http' => 200, 'photo' => $base64]);

    }

    public function saveBase64ToFile($photo)
    {
        if (empty($photo)) return null;
        $photo = str_replace('data:image/jpeg;base64,', '', $photo);
        $photo = str_replace(' ', '+', $photo);
        $photo = base64_decode($photo);

        // Bazaga NISBIY yo'l yoziladi (eski qatorlar formati saqlanadi), lekin
        // fayl ABSOLYUT yo'l bilan yoziladi — 'uploads/...' cwd ga bog'liq edi
        // va php-fpm/artisan/cron da boshqa papkaga tushib "Permission denied"
        // berardi.
        $filename = 'uploads/' . uniqid() . '.jpg';
        $target   = public_path($filename);
        $dir      = dirname($target);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            \Log::error('Scales: uploads papkasini yaratib bolmadi: ' . $dir);
            return null;
        }

        if (!is_writable($dir)) {
            \Log::error('Scales: uploads papkasi yozuvga yopiq: ' . $dir);
            return null;
        }

        if (file_put_contents($target, $photo) === false) {
            \Log::error('Scales: rasmni saqlab bolmadi: ' . $target);
            return null;
        }

        return $filename;
    }

    public function ImgToBase64($photo){
        if (empty($photo)) return null;

        // Bazada nisbiy yo'l ('uploads/xxx.jpg') turadi — public_path orqali
        // absolyutga o'giramiz, aks holda o'qish ham cwd ga bog'liq bo'ladi.
        $path = $photo;
        if (!preg_match('#^([A-Za-z]:[\\/]|/)#', $path)) {
            $path = public_path($path);
        }

        if (!is_file($path) || !is_readable($path)) {
            \Log::error('Scales: rasm fayli topilmadi: ' . $path);
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode(file_get_contents($path));
    }


}