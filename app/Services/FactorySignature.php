<?php

namespace App\Services;

/**
 * Zavod 1C -> egaz-indexator -> EGAZ MAIN (brrgz.tb_factory_signatures) intake.
 *
 * Log-first: payload HAR DOIM tb_factory_signature_logs ga yoziladi (norm_status=0),
 * keyin normalizatsiya qilinadi.
 *
 * norm_status:
 *   0 = qabul qilindi (hali ko'chirilmagan)
 *   1 = brrgz ga yozildi
 *   2 = VAQTINCHA xato (ulanish/lock) — sweeper qayta uriniladi, 1C ga 202 "deferred"
 *   3 = yozilmadi: brrgz da allaqachon IMZOLANGAN yoki dublikat (normal holat)
 *   4 = DOIMIY xato (validatsiya, uzunlik, ustun/jadval yo'q) YOKI eskirgan payload —
 *       sweeper BU QATORNI OLMAYDI (u faqat norm_status=2 ni suradi), 1C ga 422 qaytadi
 *
 * Ulanishlar:
 *   default 'pgsql'  = indexator bazasi (integration_logs, tb_factory_signature_logs)
 *   'pgsql1'         = brrgz       (tb_factory_signatures)  <- EGAZ MAIN
 */
class FactorySignature
{
    /** brrgz.tb_factory_signatures ustun kengliklari (DDL bilan bir xil bo'lishi SHART) */
    private static $LEN = [
        'uid'                  => 36,
        'waybill_number'       => 50,
        'sender_name'          => 255,
        'recipient_name'       => 255,
        'recipient_address'    => 500,
        'recipient_branch'     => 255,
        'recipient_tin'        => 20,
        'recipient_warehouse'  => 255,
        'poa_number'           => 50,
        'poa_person'           => 255,
        'wagon_number'         => 50,
        'receiving_station'    => 150,
        'seal_number'          => 50,
        'contract_number'      => 100,
        'contract_description' => 500,
        'product_name'         => 255,
        'product_unit'         => 20,
    ];

    /** decimal ustunlar — bind qilishdan oldin is_numeric bo'lishi SHART (STRICT rejim) */
    private static $NUM = [
        'gross_weight', 'tare_weight', 'net_weight', 'price', 'amount', 'vat_amount', 'total_amount',
    ];

    /**
     * DOIMIY (qayta urinish FOYDASIZ) MySQL xatolari.
     * 1062 (dublikat) bu yerda YO'Q — u alohida, muvaffaqiyat sifatida ishlanadi.
     */
    private static $PERMANENT_ERRNO = [
        1048, // Column cannot be null
        1054, // Unknown column
        1064, // SQL syntax
        1146, // Table doesn't exist
        1264, // Out of range value
        1265, // Data truncated
        1292, // Incorrect date/decimal value
        1366, // Incorrect string value
        1406, // Data too long
        1452, // FK constraint
        1526, // No partition
    ];

    /** 1 = xato, 0 = muvaffaqiyat (uy konvensiyasi — FactoryInvoice::authify()) */
    public function authify($request)
    {
        // public/.htaccess da HTTP_AUTHORIZATION pass-through YO'Q => $_SERVER faqat mod_php'da
        // to'ladi. getUser()/getPassword() — PHP-FPM/nginx uchun zaxira.
        $user = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : $request->getUser();
        $pw   = isset($_SERVER['PHP_AUTH_PW'])   ? $_SERVER['PHP_AUTH_PW']   : $request->getPassword();

        // BO'SH parol HECH QACHON qabul qilinmaydi: .env da FACTORYSIGNATURE_PSW= (bo'sh) qolsa,
        // env() '' qaytaradi (default'ga TUSHMAYDI — helpers.php: getenv() === false bo'lgandagina default).
        // Shu guard bo'lmasa bo'sh parol bilan autentifikatsiya chetlab o'tilardi.
        if ($user === null || $pw === null) return 1;
        if (trim((string) $user) === '' || (string) $pw === '') return 1;

        $env_user = (string) env('FACTORYSIGNATURE_LOGIN', 'factory-signature');
        $env_pw   = (string) env('FACTORYSIGNATURE_PSW',   'Fs7Q-2m4X-9vB1-kr6d');
        if ($env_user === '' || $env_pw === '') {          // noto'g'ri sozlangan .env — ochiq eshik emas
            \Log::error('FactorySignature: FACTORYSIGNATURE_LOGIN/PSW is empty in .env — auth refused');
            return 1;
        }

        if (!hash_equals($env_user, (string) $user)) return 1;
        if (!hash_equals($env_pw,   (string) $pw))   return 1;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-';
        }
        \Log::debug('FactorySignature: Request detected from ' . $ip);

        return 0; // Authentication successful
    }

    public function pullRequest($request)
    {
        if ($this->authify($request)) {
            \Log::error('FactorySignature: Authentication failed!');
            return response()->json(['api_status' => 0, 'api_message' => 'Authentication failed!', 'api_http' => 401], 401);
        }

        $raw  = (string) $request->getContent();          // XOM BAYTLAR (parse qilinmagan)
        $data = json_decode($raw, true);

        // form-encoded / multipart kelsa zaxira. DIQQAT: bunda XOM baytlar JSON EMAS,
        // shuning uchun logga QAYTA O'QISA BO'LADIGAN nusxa (json) yoziladi — aks holda
        // sweeper (normalizeLogRow) uni hech qachon tiklay olmaydi.
        $reparsable = $raw;
        if (!is_array($data)) {
            $data       = $request->all();
            $reparsable = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($reparsable)) $reparsable = $raw;
            \Log::debug('FactorySignature: non-JSON body, stored re-parsable copy');
        }

        \Log::debug('FactorySignature: Request size=' . strlen($raw));

        // 1) uy an'anasi uchun (ishonchli manba EMAS: TEXT/64KB, PK yo'q)
        try {
            \DB::table('integration_logs')->insert([
                'module'  => 'FactorySignature',
                'payload' => mb_substr($raw, 0, 60000),
            ]);
        } catch (\Exception $ex) {
            \Log::error('FactorySignature: integration_logs insert: ' . $ex->getMessage());
        }

        // 2) ASOSIY LOG — transaction'siz, hech qachon o'tkazib yuborilmaydi.
        //    ATAYLAB try/catch YO'Q: bu yozuv ishlamasa (jadval yo'q va h.k.) — 500 qaytsin,
        //    1C qayta yuborsin. "Qabul qildim" deb yolg'on gapirishdan ko'ra shovqin yaxshi.
        $log_id = \DB::table('tb_factory_signature_logs')->insertGetId([
            'uid'         => (isset($data['uid']) && is_scalar($data['uid'])) ? substr((string) $data['uid'], 0, 64) : null,
            'payload'     => $reparsable,
            'remote_ip'   => $request->ip(),
            'norm_status' => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->normalize($data, $log_id);
    }

    /** Sweeper (factory_signature:normalize) ham aynan shu normalizatsiyani chaqiradi */
    public function normalizeLogRow($log)
    {
        $data = json_decode($log->payload, true);
        if (!is_array($data)) {
            // Qayta o'qib bo'lmaydi => qayta urinish FOYDASIZ. Terminal (4), 2 EMAS:
            // aks holda sweeper 20 ta urinishni bekorga sarflaydi.
            $this->markLog($log->id, 4, 'invalid json (not re-parsable)');
            \Log::error('FactorySignature: log id=' . $log->id . ' payload is not re-parsable JSON');
            return false;
        }

        // ESKIRGAN PAYLOAD HIMOYASI: shu uid bo'yicha KEYINROQ kelgan payload allaqachon
        // muvaffaqiyatli yozilgan bo'lsa (norm_status 1/3), bu eski qatorni QAYTA QO'LLAMAYMIZ —
        // aks holda u yangi (tuzatilgan) yuk xatini eski qiymatlar bilan bosib ketadi.
        $uid = (isset($data['uid']) && is_scalar($data['uid'])) ? trim((string) $data['uid']) : '';
        if ($uid !== '') {
            $newer = \DB::table('tb_factory_signature_logs')
                ->where('uid', substr($uid, 0, 64))
                ->where('id', '>', $log->id)
                ->whereIn('norm_status', [1, 3])
                ->first(['id']);
            if ($newer) {
                $this->markLog($log->id, 4, 'superseded by newer log #' . $newer->id);
                \Log::info('FactorySignature: log id=' . $log->id . ' superseded by #' . $newer->id);
                return false;
            }
        }

        \DB::table('tb_factory_signature_logs')->where('id', $log->id)->increment('norm_attempts');
        $this->normalize($data, $log->id);

        return true;
    }

    private function normalize($data, $log_id)
    {
        $err = $this->validate($data);
        if ($err !== null) {
            // Validatsiya xatosi DOIMIY: aynan shu payload hech qachon o'tmaydi => terminal (4) + 422.
            $this->markLog($log_id, 4, $err);
            \Log::error('FactorySignature: ' . $err);
            return response()->json(['api_status' => 0, 'api_message' => $err, 'api_http' => 422], 422);
        }

        $row = $this->mapRow($data);

        // Ustun kengligi / son formati — STRICT rejimda bular INSERT'da ERROR beradi.
        // Oldindan tekshiramiz: 1C 422 ko'rsin, "202 deferred" deb yolg'on emas.
        $err = $this->checkRow($row);
        if ($err !== null) {
            $this->markLog($log_id, 4, $err);
            \Log::error('FactorySignature: ' . $err);
            return response()->json(['api_status' => 0, 'api_message' => $err, 'api_http' => 422], 422);
        }

        // EGAZ MAIN (brrgz MySQL) — egaz-indexator dagidek `mysql1`.
        //
        // ⚠ O'QISH ham shu ulanishda: pastdagi `where('uid', ...)` imzo allaqachon
        //   bor-yo'qligini aniqlaydi va 1C ga qaytariladigan `id` shu bazadan
        //   olinadi. Ya'ni birlamchi manba (source of truth) — MySQL.
        //   `pgsql1` nusxasiga yozishni dual write o'zi bajaradi
        //   (config/dual_write.php).
        $db = \DB::connection('mysql1');

        try {
            $ex = $db->table('tb_factory_signatures')->where('uid', $row['uid'])->first(['id', 'st']);

            if (!$ex) {
                $row['source_log_id'] = $log_id;
                $row['created_at']    = date('Y-m-d H:i:s');
                $id     = $db->table('tb_factory_signatures')->insertGetId($row);
                $action = 'created';
            } elseif ($ex->st == '0') {                       // hali imzolanmagan — 1C tuzatishi mumkin
                $id = $ex->id;
                $row['source_log_id'] = $log_id;
                $row['updated_at']    = date('Y-m-d H:i:s');
                $db->table('tb_factory_signatures')->where('id', $id)->where('st', '0')->update($row);
                $action = 'updated';
            } else {                                          // IMZOLANGAN => o'zgarmas (huquqiy hujjat)
                $id = $ex->id;
                $this->markLog($log_id, 3, null, $id);
                return response()->json([
                    'api_status'  => 1,
                    'api_message' => 'success',
                    'api_http'    => 200,
                    'data'        => ['id' => $id, 'uid' => $row['uid'], 'action' => 'ignored_signed'],
                ], 200);
            }
        } catch (\Exception $ex2) {
            // UNIQUE(uid) poygasi: ikkita bir vaqtdagi POST. Bu XATO EMAS.
            // DIQQAT: xabar MATNIDAN qidirmaymiz — Laravel QueryException xabariga BUTUN SQL
            // (barcha bind'lar, ya'ni raw_payload ham) qo'shiladi, u yerdagi "1062" satri
            // istalgan xatoni "dublikat" qilib ko'rsatardi. Faqat SQLSTATE / driver errno.
            if ($this->isDuplicate($ex2)) {
                $dup = null;
                try {
                    $dup = $db->table('tb_factory_signatures')->where('uid', $row['uid'])->first(['id']);
                } catch (\Exception $ex3) {
                    \Log::error('FactorySignature: dup re-select: ' . $ex3->getMessage());
                }
                $this->markLog($log_id, 3, null, $dup ? $dup->id : null);
                return response()->json([
                    'api_status'  => 1,
                    'api_message' => 'success',
                    'api_http'    => 200,
                    'data'        => ['id' => $dup ? $dup->id : null, 'uid' => $row['uid'], 'action' => 'ignored_duplicate'],
                ], 200);
            }

            \Log::error('FactorySignature: pgsql1 write: ' . $ex2->getMessage());

            if ($this->isPermanent($ex2)) {
                // Bu payload HECH QACHON yozilmaydi (jadval/ustun yo'q, ma'lumot xato).
                // Sweeper 20 marta bekorga urinmasin, 1C ESA MUVAFFAQIYAT deb o'ylamasin.
                $this->markLog($log_id, 4, substr($ex2->getMessage(), 0, 500));
                return response()->json([
                    'api_status'  => 0,
                    'api_message' => 'rejected: database refused the record (permanent error)',
                    'api_http'    => 422,
                    'data'        => ['uid' => $row['uid'], 'action' => 'rejected'],
                ], 422);
            }

            // VAQTINCHA xato (ulanish uzildi, deadlock, lock timeout).
            // 202: PAYLOAD SAQLANDI (log qatori bor), sweeper qayta uriniladi.
            // api_status=1 — chunki 1C uchun bu MUVAFFAQIYAT: qayta yubormasin.
            $this->markLog($log_id, 2, substr($ex2->getMessage(), 0, 500));

            return response()->json([
                'api_status'  => 1,
                'api_message' => 'accepted, normalization deferred',
                'api_http'    => 202,
                'data'        => ['uid' => $row['uid'], 'action' => 'deferred'],
            ], 202);
        }

        $this->markLog($log_id, 1, null, $id);

        return response()->json([
            'api_status'  => 1,
            'api_message' => 'success',
            'api_http'    => 200,
            'data'        => ['id' => $id, 'uid' => $row['uid'], 'action' => $action],
        ], 200);
    }

    /** PDO drayver errno (QueryException $errorInfo dan) — xabar matnidan EMAS */
    private function errno($ex)
    {
        if (!($ex instanceof \Illuminate\Database\QueryException)) return 0;
        $info = $ex->errorInfo;
        if (!is_array($info) || !isset($info[1])) return 0;
        return (int) $info[1];
    }

    /** SQLSTATE kodi ($errorInfo[0]) */
    private function sqlstate($ex)
    {
        if (!($ex instanceof \Illuminate\Database\QueryException)) return '';
        $info = $ex->errorInfo;
        return (is_array($info) && isset($info[0])) ? (string) $info[0] : '';
    }

    /**
     * UNIQUE(uid) buzilishi — ikkita bir vaqtdagi POST poygasi.
     *
     * PostgreSQL da bu SQLSTATE 23505; errorInfo[1] esa drayverning ichki
     * raqami (MySQL dagi 1062 EMAS), shuning uchun SQLSTATE bo'yicha
     * tekshiriladi. 1062 eski MySQL ulanishi uchun qoldirildi.
     */
    private function isDuplicate($ex)
    {
        return $this->sqlstate($ex) === '23505' || $this->errno($ex) === 1062;
    }

    /** true = qayta urinish foydasiz (ma'lumot/sxema xatosi). Ulanish xatolari => false. */
    private function isPermanent($ex)
    {
        // Dublikat alohida (muvaffaqiyat sifatida) ishlanadi — bu yerda EMAS.
        if ($this->isDuplicate($ex)) return false;

        $errno = $this->errno($ex);

        if ($errno !== 0 && in_array($errno, self::$PERMANENT_ERRNO, true)) return true;

        $sqlstate = $this->sqlstate($ex);
        if ($sqlstate === '') return false;                   // driver/ulanish xatosi — vaqtincha
        $cls = substr($sqlstate, 0, 2);

        // 22xxx = data exception, 42xxx = sintaksis/jadval/ustun yo'q.
        // 23xxx = cheklov buzilishi (NOT NULL / FK / CHECK) — PG da MySQL ning
        //         1048/1452 o'rnini bosadi; dublikat (23505) yuqorida chiqarildi.
        // 08xxx / HY000 / 40001 (deadlock) / 28000 (parol) => VAQTINCHA (qayta urinamiz).
        return ($cls === '22' || $cls === '42' || $cls === '23');
    }

    private function markLog($log_id, $status, $error, $waybill_id = null)
    {
        $upd = ['norm_status' => $status, 'norm_error' => $error, 'norm_at' => date('Y-m-d H:i:s')];
        if ($waybill_id) $upd['waybill_id'] = $waybill_id;

        try {
            \DB::table('tb_factory_signature_logs')->where('id', $log_id)->update($upd);
        } catch (\Exception $e) {
            \Log::error('FactorySignature: markLog: ' . $e->getMessage());
        }
    }

    private function validate($d)
    {
        // SKALYAR GUARD: PHP 7.0 da (string)['a'=>1] === 'Array' (faqat notice).
        // Guard bo'lmasa {"uid":{"guid":"..."}} => uid='Array' bo'lib, tabiiy kalit buziladi
        // va ikkita turli yuk xati bitta qatorga qulab tushadi.
        if (!isset($d['uid']) || !is_scalar($d['uid'])) return 'uid is required and must be a scalar';
        if (trim((string)$d['uid']) === '') return 'uid is required';
        if (mb_strlen(trim((string)$d['uid'])) > 36) return 'uid is too long';
        if (!isset($d['waybill_number']) || !is_scalar($d['waybill_number'])) return 'Yuk xati majburiy (waybill_number is required and must be a scalar)';
        if (trim((string)$d['waybill_number']) === '') return 'Yuk xati majburiy (waybill_number is required)';
        if (!isset($d['waybill_date']) || !is_scalar($d['waybill_date']) || !strtotime((string)$d['waybill_date'])) return 'Yuk xati majburiy (waybill_date is invalid)';
        if (!isset($d['product']) || !is_array($d['product'])) return 'Mahsulot (product is required)';
        if (!isset($d['recipient']) || !is_array($d['recipient'])) return 'Qabul qiluvchi majburiy (recipient is required)';
        if (trim((string)$d['transport']['seal_number']) == '') return 'Plomba majburiy (seal_number is required)';
        return null;
    }

    /** Yozishdan OLDIN: ustun kengligi + son formati (STRICT rejim ERROR beradi, truncate qilmaydi) */
    private function checkRow($row)
    {
        foreach (self::$LEN as $col => $max) {
            if (!isset($row[$col]) || $row[$col] === null) continue;
            if (mb_strlen((string) $row[$col]) > $max) {
                return $col . ' is too long (max ' . $max . ')';
            }
        }
        foreach (self::$NUM as $col) {
            if (!isset($row[$col]) || $row[$col] === null) continue;
            if (!is_numeric($row[$col])) {
                return $col . ' is not a valid number';
            }
        }
        return null;
    }

    /** MASS-ASSIGNMENT YO'Q — har bir ustun aniq. Kutilmagan 1C kaliti "Unknown column" bermaydi. */
    private function mapRow($d)
    {
        return [
            'uid'                  => trim((string) $d['uid']),
            'waybill_number'       => trim((string) $d['waybill_number']),      // STRING! boshidagi nol saqlanadi
            'waybill_date'         => date('Y-m-d', strtotime((string) $d['waybill_date'])),
            'sender_name'          => $this->s($d, 'sender', 'name'),
            'recipient_name'       => $this->s($d, 'recipient', 'name'),
            'recipient_address'    => $this->s($d, 'recipient', 'address'),
            'recipient_branch'     => $this->s($d, 'recipient', 'branch'),
            'recipient_tin'        => $this->s($d, 'recipient', 'tin'),         // STRING!
            'recipient_warehouse'  => $this->s($d, 'recipient', 'warehouse'),
            'poa_number'           => $this->s($d, 'power_of_attorney', 'number'),
            'poa_date'             => $this->dt($this->s($d, 'power_of_attorney', 'date')),
            'poa_person'           => $this->s($d, 'power_of_attorney', 'person'),
            'wagon_number'         => $this->s($d, 'transport', 'wagon_number'),  // STRING!
            'receiving_station'    => $this->s($d, 'transport', 'receiving_station'),
            'seal_number'          => $this->s($d, 'transport', 'seal_number'),   // STRING! plomba
            'contract_number'      => $this->s($d, 'contract', 'number'),
            'contract_date'        => $this->dt($this->s($d, 'contract', 'date')),
            'contract_description' => $this->s($d, 'contract', 'description'),
            'product_name'         => $this->s($d, 'product', 'name'),
            'product_unit'         => $this->s($d, 'product', 'unit'),
            // DIQQAT: n() ga XOM qiymat beriladi (s() EMAS) — s() double'ni (string) bilan
            // precision=14 ga yo'qotib yuboradi.
            'gross_weight'         => $this->n($this->g($d, 'product', 'gross_weight')),
            'tare_weight'          => $this->n($this->g($d, 'product', 'tare_weight')),
            'net_weight'           => $this->n($this->g($d, 'product', 'net_weight')),
            'price'                => $this->n($this->g($d, 'product', 'price')),
            'amount'               => $this->n($this->g($d, 'product', 'amount')),
            'vat_rate'             => $this->i($d, 'vat_rate'),
            'vat_amount'           => $this->n($this->t($d, 'vat_amount')),
            'total_amount'         => $this->n($this->t($d, 'total_amount')),
            'raw_payload'          => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'st'                   => '0',
        ];
    }

    /** Ichki guruhdan XOM qiymat (skalyar bo'lmasa NULL) — n() uchun */
    private function g($d, $grp, $k)
    {
        if (!isset($d[$grp]) || !is_array($d[$grp]) || !isset($d[$grp][$k])) return null;
        $v = $d[$grp][$k];
        return is_scalar($v) ? $v : null;
    }

    /** Yuqori darajadagi XOM skalyar qiymat */
    private function t($d, $k)
    {
        if (!isset($d[$k]) || !is_scalar($d[$k])) return null;
        return $d[$k];
    }

    /** Ichki guruhdan string: "" -> NULL (receiving_station, contract.description) */
    private function s($d, $grp, $k)
    {
        $v = $this->g($d, $grp, $k);
        if ($v === null || is_bool($v)) return null;
        if (is_float($v)) $v = $this->n($v);                 // exponent ko'rinishiga tushmasin
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function i($d, $k)
    {
        $v = $this->t($d, $k);
        return is_numeric($v) ? (int) $v : 0;
    }

    private function dt($v)
    {
        return (!$v || !strtotime($v)) ? null : date('Y-m-d', strtotime($v));
    }

    /**
     * decimal ustun uchun STRING bind.
     * 1C sonni JSON NUMBER qilib yuborsa json_decode allaqachon double qilgan bo'ladi;
     * (string)$double ini precision=14 bilan chiqadi ('1.2345678901235E+14' yoki kesilgan
     * '1234567890123.4') — STRICT decimal(20,2) buni RAD ETADI yoki qiymat BUZILADI.
     * sprintf('%.6F') to'liq o'nlik yozuv beradi, MySQL ustun aniqligiga yaxlitlaydi.
     */
    private function n($v)
    {
        if ($v === null || $v === '' || is_bool($v) || is_array($v) || is_object($v)) return null;  // 0 EMAS — yo'q qiymat NULL
        if (is_float($v))   return sprintf('%.6F', $v);
        if (is_int($v))     return (string) $v;

        $v = trim(str_replace(',', '.', (string) $v));
        return $v === '' ? null : $v;
    }
}
