<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * brrgz ga yozilmay qolgan (norm_status=2 — VAQTINCHA xato: ulanish/deadlock) yuk xati
 * payloadlarini qayta normalizatsiya qiladi.
 *
 * norm_status=4 (DOIMIY xato: validatsiya, ustun kengligi, jadval/ustun yo'q, eskirgan payload)
 * BU YERDA OLINMAYDI — qayta urinish foydasiz, 1C ga allaqachon 422 qaytarilgan.
 * Ularni qo'lda ko'rish:
 *   SELECT id, uid, norm_error FROM tb_factory_signature_logs WHERE norm_status = 4;
 * OS cron (Laravel scheduler bu loyihada ishlamaydi):
 *   asterisk/5 * * * * cd /var/www/egaz-indexator && php artisan factory_signature:normalize >> storage/logs/fs_norm.log 2>&1
 */
class FactorySignatureNormalize extends Command
{
    protected $signature = 'factory_signature:normalize {--limit=100}';

    protected $description = 'brrgz ga yozilmagan yuk xati payloadlarini qayta normalizatsiya qiladi';

    public function handle()
    {
        $rows = \DB::table('tb_factory_signature_logs')
            ->where('norm_status', 2)
            ->where('norm_attempts', '<', 20)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if (!count($rows)) return;

        \Log::info('FactorySignature: normalize sweep, rows=' . count($rows));

        $svc = new \App\Services\FactorySignature();
        foreach ($rows as $r) {
            try {
                $svc->normalizeLogRow($r);
            } catch (\Exception $e) {
                \Log::error('FactorySignature: normalize id=' . $r->id . ': ' . $e->getMessage());
            }
        }
    }
}
