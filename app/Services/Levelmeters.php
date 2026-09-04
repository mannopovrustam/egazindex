<?php

namespace App\Services;

class Levelmeters {
    public function authify()
    {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) return 1;
        if ($_SERVER['PHP_AUTH_USER'] != env('LEVELMETERS_LOGIN','levelmeters') || $_SERVER['PHP_AUTH_PW'] != env('LEVELMETERS_PSW','yEmX46rr4FKQ2A7o')) return 1;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        \Log::debug('Levelmeters: Response detected from ' . $ip);

        return 0; // Authentication successful
//    if (!in_array($ip, explode(',', env('ALLOW_IP')))) return json_encode(['result' => 202, 'data' => null, 'comments' => 'Wrong IP!'], JSON_UNESCAPED_UNICODE);
    }


    public function pullRequest($data) {

        if ($this->authify()) {
            \Log::error('Levelmeters: Authentication failed!');
            return response()->json(['api_status' => 0, 'api_message' => 'Authentication failed!', 'api_http' => 401],401);
        }

//        \Log::debug('Levelmeters: Request ' . json_encode($data));
        \DB::table('integration_logs')->insert(['module'=>'Levelmeters','payload'=>json_encode($data)]);
        //json_encode($data) => {"ts":"2026-03-12T12:35:31Z","items":[{"device_address":1,"param_id":"14","value":7.7052001953125},{"device_address":2,"param_id":"02","value":8.84375},{"device_address":3,"param_id":"04","value":21.81494140625},{"device_address":3,"param_id":"11","value":22.56982421875},{"device_address":4,"param_id":"04","value":43.541015625},{"device_address":4,"param_id":"11","value":45.0859375}]}

        // tb_levelmeters: id serial primary key, id_org int, device_address int, param_id varchar(20), value float, ts timestamp,
        // site -> id_org moslamasi
        $siteMap = [
            'shahrixon'   => 355,
            'kurgontepa'  => 12,
            'qurgontepa'  => 12,
            'asaka'       => 13,
            'qiziriq'     => 307,
            'surxandaryo' => 307,
        ];
        $site = null;
        if (isset($data['site'])) {
            $key = strtolower(trim((string) $data['site']));
            if (isset($siteMap[$key])) $site = $siteMap[$key];
        }

        // `items` MAJBURIY. Ilgari tekshirilmasdan foreach ga berilardi va
        // qurilma bo'sh/boshqacha so'rov yuborsa "Undefined index: items"
        // bilan 500 qaytardi — jo'natuvchi esa nima noto'g'ri ekanini bilmasdi.
        if (!isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
            \Log::warning('Levelmeters: [items] yo`q yoki bo`sh; payload=' . json_encode($data));
            return response()->json(['api_status' => 0, 'api_message' => '[items] parameter missed or empty!', 'api_http' => 400], 400);
        }

        // `ts` bo'lmasa yoki tanib bo'lmasa — server vaqti. strtotime(null)
        // PHP 8 da xato beradi, false esa 1970 yilni yozib qo'yardi.
        $ts = isset($data['ts']) ? strtotime((string) $data['ts']) : false;
        if ($ts === false) {
            if (isset($data['ts'])) \Log::warning('Levelmeters: [ts] o`qib bo`lmadi (' . json_encode($data['ts']) . '), server vaqti ishlatildi');
            $ts = time();
        }
        $tsFmt = date('Y-m-d H:i:s', $ts);

        foreach ($data['items'] as $item) {
            // Nuqsonli element butun so'rovni yiqitmaydi: uni o'tkazib yuborib,
            // qolganini yozamiz — bitta buzuq o'lchov tufayli boshqa qurilmalar
            // ma'lumoti yo'qolmasin.
            if (!is_array($item) || !isset($item['device_address']) || !isset($item['param_id']) || !isset($item['value'])) {
                \Log::warning('Levelmeters: nuqsonli element o`tkazib yuborildi: ' . json_encode($item));
                continue;
            }

            $detail = [
                'id_org' => $site ?? 307,
                'device_address' => $item['device_address'],
                'param_id' => $item['param_id'],
                'value' => $item['value'],
                'ts' => $tsFmt
            ];
            try {
                \DB::table('tb_levelmeters')->insert($detail);
            } catch (\Exception $ex) {
                \Log::error('Levelmeters: Database insert error for device_address ' . $item['device_address'] . ': ' . $ex->getMessage());
                return response()->json(['api_status' => 0, 'api_message' => 'Database insert error for device_address ' . $item['device_address'] . ': ' . $ex->getMessage(), 'api_http' => 500],500);
            }
        }

        try {
            return response()->json(['api_status' => 1, 'api_message' => 'Data inserted successfully', 'api_http' => 200],200);
        } catch (\Exception $ex) {
            \Log::error('Levelmeters: Database insert error: ' . $ex->getMessage());
            return response()->json(['api_status' => 0, 'api_message' => 'Database insert error: ' . $ex->getMessage(), 'api_http' => 500],500);
        }
    }

}
