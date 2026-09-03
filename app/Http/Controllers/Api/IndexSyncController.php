<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyIndexRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * egaz-indexator → egazindex satr sinxronizatsiyasining YAGONA kirish nuqtasi.
 *
 *     POST /api/v1/index/rows
 *
 * Kutilgan tana (JSON):
 *
 *     {
 *       "table": "i_real_details",
 *       "op":    "I",                       // faqat I (insert) qo'llanadi
 *       "uid":   "3f9c1a0b5d2e7c48",        // ixtiyoriy, loglarni bog'lash uchun
 *       "src":   "egaz-indexator",
 *       "rows":  [ { "dt": "2026-09-02", "yy": 2026, ... }, ... ]
 *     }
 *
 * Header'lar:
 *   X-API-Key   — config('index_sync.key') bilan solishtiriladi
 *                 (config bo'sh bo'lsa tekshirilmaydi)
 *   X-Sync-Sign — hash_hmac('sha256', <xom tana>, config('index_sync.secret'))
 *                 secret bo'sh bo'lsa tekshirilmaydi
 *
 * Controller BAZAGA YOZMAYDI: qatorlarni faqat tekshirib navbatga qo'yadi
 * (`ApplyIndexRows`), shunda yuboruvchi tarafdagi 3 sekundlik curl timeout'i
 * PostgreSQL yozuvini kutib qolmaydi.
 *
 * @see config/index_sync.php
 * @see \App\Jobs\ApplyIndexRows
 */
class IndexSyncController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! config('index_sync.enabled', true)) {
            return response()->json([
                'status'  => 'disabled',
                'message' => 'index sync intake o`chirilgan (INDEX_SYNC_ENABLED=false)',
            ], 503);
        }

        if (! $this->apiKeyIsValid($request)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'X-API-Key noto`g`ri',
            ], 401);
        }

        if (! $this->signatureIsValid($request)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'imzo (X-Sync-Sign) noto`g`ri',
            ], 401);
        }

        $tables = (array) config('index_sync.tables', []);

        $data = $request->validate([
            'table'  => ['required', 'string', Rule::in(array_keys($tables))],
            // `op` ixtiyoriy va faqat diagnostika uchun: bu jadvallar
            // append-only, ya'ni amal DOIM insert (ApplyIndexRows izohiga qarang).
            'op'     => ['nullable', 'string', 'max:10'],
            'uid'    => ['nullable', 'string', 'max:64'],
            'rows'   => ['required', 'array', 'min:1', 'max:' . (int) config('index_sync.max_rows', 500)],
            'rows.*' => ['array'],
        ]);

        $rows = array_values($data['rows']);
        $uid  = $data['uid'] ?? null;

        ApplyIndexRows::dispatch($data['table'], $rows, $uid)
            ->onQueue((string) config('index_sync.queue', 'default'));

        if (config('index_sync.log', true)) {
            Log::info(sprintf(
                'IDXSYNC intake: %s %d qator navbatga qo`yildi (uid=%s, ip=%s)',
                $data['table'], count($rows), $uid ?? '-', $request->ip()
            ));
        }

        return response()->json([
            'status'   => 'queued',
            'table'    => $data['table'],
            'accepted' => count($rows),
            'uid'      => $uid,
        ], 202);
    }

    /**
     * X-API-Key. `config('index_sync.key')` bo'sh bo'lsa tekshiruv
     * o'tkazib yuboriladi (faqat yopiq ichki tarmoqda mumkin).
     */
    private function apiKeyIsValid(Request $request): bool
    {
        $expected = (string) config('index_sync.key', '');
        if ($expected === '') {
            return true;
        }

        return hash_equals($expected, (string) $request->header('X-API-Key', ''));
    }

    /**
     * HMAC-SHA256 imzosi. `config('index_sync.secret')` bo'sh bo'lsa tekshiruv
     * o'tkazib yuboriladi (faqat X-API-Key ishlaydi).
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('index_sync.secret', '');
        if ($secret === '') {
            return true;
        }

        $provided = (string) $request->header('X-Sync-Sign', '');
        if ($provided === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $provided);
    }
}
