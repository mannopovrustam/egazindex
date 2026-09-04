<?php

namespace App\Services;

class FactoryInvoice
{
    public function authify()
    {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) return 1;
        if ($_SERVER['PHP_AUTH_USER'] != env('FACTORYINVOICE_LOGIN', 'factory-invoice') || $_SERVER['PHP_AUTH_PW'] != env('FACTORYINVOICE_PSW', '4zc-2d8-426b-8a7b')) return 1;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        \Log::debug('FactoryInvoice: Response detected from ' . $ip);

//    if (!in_array($ip, explode(',', env('ALLOW_IP')))) return json_encode(['result' => 202, 'data' => null, 'comments' => 'Wrong IP!'], JSON_UNESCAPED_UNICODE);
    }


    public function pullRequest($data)
    {

        if ($this->authify()) return response()->json(['api_status' => 0, 'api_message' => 'Authentication failed!', 'api_http' => 401]);

        \Log::debug('FactoryInvoice: Request ' . json_encode($data));

        \DB::table('integration_logs')->insert(['module'=>'FactoryInvoice','payload'=>json_encode($data)]);

        $data = json_decode(json_encode($data), true);
        unset($data['model_auto']);
        $data['dt'] = date('Y-m-d H:i:s', strtotime($data['dt']));

        // Rad javoblari 1C tomonida ko'rinmaydi — shuning uchun logga ham yoziladi,
        // aks holda payload `integration_logs` da qolib, jadvallarga tushmaydi va
        // sababi hech qayerda qolmaydi. `php artisan factory-invoice:replay` shu
        // qatorlarni topib beradi.
        if(!in_array($data['factory'], ['000000002','000000004','000000006','000000009','000000010'])) {
            \Log::error('FactoryInvoice: Invalid factory! factory=' . $data['factory'] . ' numb=' . (isset($data['numb']) ? $data['numb'] : '?'));
            return response()->json(['api_status' => 0, 'api_message' => 'Invalid factory!', 'api_http' => 401]);
        }
        if(!in_array($data['hgt_filial'], ['000000001','000000002','000000003','000000004','000000005','000000006','000000007','000000008','000000009','000000010','000000011','000000012','000000013'])) {
            \Log::error('FactoryInvoice: Invalid hgt_filial! hgt_filial=' . $data['hgt_filial'] . ' numb=' . (isset($data['numb']) ? $data['numb'] : '?'));
            return response()->json(['api_status' => 0, 'api_message' => 'Invalid hgt_filial!', 'api_http' => 401]);
        }

        // 1C payloadga yangi maydon qo'shsa INSERT "Unknown column" bilan yiqilardi.
        // Jadvalda yo'q kalitlarni tashlaymiz — `factory-invoice:replay` ham shunday.
        $intCols = self::integrationColumns();
        $extra   = array_diff(array_keys($data), $intCols);
        if ($extra) {
            \Log::warning('FactoryInvoice: payload da ortiqcha ustun (tashlandi): ' . implode(', ', $extra)
                . ' numb=' . (isset($data['numb']) ? $data['numb'] : '?'));
            $data = array_intersect_key($data, array_flip($intCols));
        }

        // `tb_factory_integration_bi` (BEFORE INSERT) DB triggeri hgt_filial va factory
        // matnli kodlarini egaz tashkilot id lariga o'giradi (hgt_filial_egaz /
        // factory_egaz). AMMO `hgt_filial_egaz` — NOT NULL va DEFAULT'siz: trigger
        // bazada bo'lmasa INSERT "Field 'hgt_filial_egaz' doesn't have a default value"
        // bilan yiqiladi, rollback esa tb_factory_integration ni ham, tb_fc_invoices ni
        // ham bekor qiladi — payload `integration_logs` da YOLG'IZ qolib ketadi.
        //
        // Shuning uchun qiymatlarni O'ZIMIZ qo'yamiz (aynan `factory-invoice:replay`
        // dagidek). DB triggeri joyida bo'lsa u shu qiymatlarning AYNAN o'zini qayta
        // yozadi — xarita bir xil (TbFactoryIntegrationTriggers ↔ docs/idxdb_triggers.sql),
        // ya'ni trigger bor-yo'qligidan qat'i nazar natija o'zgarmaydi.
        if (in_array('hgt_filial_egaz', $intCols, true)) {
            $data['hgt_filial_egaz'] = \App\Services\DbTriggers\TbFactoryIntegrationTriggers::egazFilial($data['hgt_filial']);
        }
        if (in_array('factory_egaz', $intCols, true)) {
            $data['factory_egaz'] = \App\Services\DbTriggers\TbFactoryIntegrationTriggers::egazFactory($data['factory']);
        }

        \DB::beginTransaction();
        try {
            $fac_id = \App\Services\DbTriggers\TriggerBus::insertGetId('tb_factory_integration', $data);
            $fac_1c = \DB::table('tb_factory_integration')->where('id', $fac_id)->first();
            $inv_detail = [
                'numb' => $fac_1c->numb,
                'numb_plomb' => 1,
                'id_factory' => $fac_1c->factory_egaz,
                'dt' => date('Y-m-d', strtotime($fac_1c->dt)),
                'qty_output' => $fac_1c->qty_output * 1000,
                'qty_accepted' => 0.000,
                'id_oblgaz' => $fac_1c->hgt_filial_egaz,
                'out_tp' => $fac_1c->out_tp == 'auto' ? 'avto' : 'vagon',
                'vagon_drv_name' => $fac_1c->vagon_drv_name,
                'descr' => "Zavod Integratsiya:" . $fac_1c->id,
                'numb_auto' => $fac_1c->numb_auto,
                'numb_pricep' => $fac_1c->numb_pricep,
                'entry_by' => 0,
                'brutto' => $fac_1c->brutto,
                'netto' => $fac_1c->netto,
                'created_at' => $fac_1c->created_at,
            ];

            // Asosiy egaz bazasi (brrgz MySQL) — egaz-indexator dagidek `mysql1`.
            // DUAL_WRITE=true bo'lsa nusxa `pgsql1` ga avtomatik ketadi (config/dual_write.php).
            $inv_id = \DB::connection('mysql1')->table('tb_fc_invoices')->insertGetId($inv_detail);

            \DB::commit();

            // 1C QR kodni O'ZI chizadi: bazaviy manzilga shu tokenni ulaydi
            // (https://egaz.uz/factory-invoice-qr/{token}). Token egaz dagi yuk xati
            // qatoriga (tb_fc_invoices.id) bog'langan.
            $token = $this->qrToken($inv_id);

            \Log::debug('FactoryInvoice: invoice id=' . $inv_id . ' qr_token=' . ($token === null ? '-' : $token));

            // ESKI JAVOB shunchaki "1" edi. `result` maydoni shu moslik uchun
            // qoldirilgan, token esa `data` ichida. (egaz-indexator 0f2c729 bilan aynan)
            return response()->json([
                'api_status'  => 1,
                'api_message' => 'success',
                'api_http'    => 200,
                'result'      => 1,
                'data'        => [
                    'id'          => $inv_id,
                    'qr_token'    => $token
                ],
            ], 200);
        } catch (\Exception $e) {
            \DB::rollback();
            // Xato faqat javob matnida qaytardi — 1C uni o'qimaydi, natijada
            // "logda bor, jadvalda yo'q" holati sabab qoldirmasdan yuzaga kelardi.
            \Log::error('FactoryInvoice: INSERT xatosi numb=' . (isset($data['numb']) ? $data['numb'] : '?')
                . ' dt=' . (isset($data['dt']) ? $data['dt'] : '?') . ' — ' . $e->getMessage());
            return $e->getMessage();
        }

//        ClickhouseService::scales(json_encode($data));

//        return 1;
    }

    /**
     * `tb_factory_integration` ustunlari ro'yxati.
     * Jarayon davomida bir marta o'qiladi — har so'rovga qo'shimcha SELECT bo'lmasin.
     *
     * @return string[]
     */
    private static function integrationColumns()
    {
        static $cols = null;

        if ($cols === null) {
            $cols = \Illuminate\Support\Facades\Schema::getColumnListing('tb_factory_integration');
        }

        return $cols;
    }

    /**
     * QR token: md5(EGAZ APP_KEY . '-' . id) . '-' . id
     *
     * Aynan shu formulani egaz MAIN dagi marshrut tekshiradi
     * (routes/web.php — Route::get('factory-invoice-qr/{hash}')): hash '-' bo'yicha
     * ikkiga bo'linadi, o'ng tomoni tb_fc_invoices.id, chap tomoni md5.
     *
     * Kalit — egaz ning XOM env('APP_KEY') qiymati ("base64:" prefiksi bilan birga),
     * config/waybill_qr.php ga qarang. Sozlanmagan bo'lsa NULL qaytadi: noto'g'ri
     * token berib 404 chiqadigan QR chizdirgandan ko'ra token bermagan yaxshi.
     */
    private function qrToken($id)
    {
        $id  = (int) $id;
        $key = (string) config('waybill_qr.key');

        if ($id <= 0) return null;

        if ($key === '') {
            \Log::error('FactoryInvoice: WAYBILL_QR_KEY .env da sozlanmagan — QR token berilmadi (invoice id=' . $id . ')');
            return null;
        }

        return md5($key . '-' . $id) . '-' . $id;
    }
}