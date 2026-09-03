<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Exception;
use Log;

/**
 * Umumiy oylik eksport komandasi.
 *
 * dt ustuni bo'yicha oylik filtrlab, bir yoki bir nechta manba jadval/view'dan
 * ma'lumotni CSV faylga yuklab beradi.
 *
 * Keyinchalik boshqa ma'lumotni olish uchun quyidagi $sources ro'yxatini yoki
 * --source / --connection / --column parametrlarini o'zgartirib ishlataverish mumkin.
 *
 * Misollar:
 *   php artisan export:monthly 2026-07
 *   php artisan export:monthly 2026-07 --source=money_details --column=dt
 *   php artisan export:monthly 2026-07 --source=vw_money_details_AN --source=vw_money_details_BU
 *   php artisan export:monthly 2026-07 --connection=mysql1 --path=storage/app/exports
 */
class ExportMonthly extends Command {

    protected $signature = 'export:monthly
        {month : Oy YYYY-MM formatida (masalan 2026-07)}
        {--source=* : Aniq jadval/view nomi (bir nechta marta berilishi mumkin). Bosh bolsa pastdagi toplamlardan foydalaniladi}
        {--set=money_details : Oldindan belgilangan manba toplami nomi (--source berilmaganda ishlaydi)}
        {--connection=mysql1 : Manba DB ulanishi (egaz asosiy DB = mysql1)}
        {--column=dt : Oylik filtr qollanadigan sana ustuni}
        {--path= : Chiqish papkasi (bosh bolsa storage/app/exports)}
        {--delimiter=, : CSV ajratuvchi belgisi}';

    protected $description = 'Manba jadval/view lardan malumotni sana ustuni boyicha oylik filtrlab CSV faylga yuklaydi';

    /**
     * Oldindan belgilangan manba to'plamlari.
     * Yangi to'plam kerak bo'lsa shu yerga qo'shing va --set=<nom> bilan chaqiring.
     */
    protected $sets = [
        // Viloyat kesimidagi pul (money) detallari view'lari
        'money_details' => [
            'vw_money_details_AN', // Andijon
            'vw_money_details_BU', // Buxoro
            'vw_money_details_FA', // Farg'ona
            'vw_money_details_JI', // Jizzax
            'vw_money_details_NM', // Namangan
            'vw_money_details_NV', // Navoiy
            'vw_money_details_QA', // Qashqadaryo
            'vw_money_details_QQ', // Qoraqalpog'iston
            'vw_money_details_SA', // Samarqand
            'vw_money_details_SI', // Sirdaryo
            'vw_money_details_SU', // Surxondaryo
            'vw_money_details_TV', // Toshkent viloyati
            'vw_money_details_XO', // Xorazm
        ],
    ];

    public function __construct() {
        parent::__construct();
    }

    public function handle() {
        try {
            $month = $this->argument('month');
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                $this->error("Oy noto'g'ri formatda: '{$month}'. To'g'ri format: YYYY-MM (masalan 2026-07)");
                return 1;
            }

            $from = $month . '-01';
            $to   = date('Y-m-t', strtotime($from)); // oyning oxirgi kuni
            if ($to === false) {
                $this->error("Oyni aniqlab bo'lmadi: '{$month}'");
                return 1;
            }

            $connection = $this->option('connection');
            $column     = $this->option('column');
            $delimiter  = $this->option('delimiter');
            $sources    = $this->resolveSources();

            if (empty($sources)) {
                $this->error("Manba topilmadi. --source bilan bering yoki --set=<nom> to'plamini ko'rsating.");
                return 1;
            }

            $dir = $this->resolveDir($month);

            $this->info("Oy: {$month}  ({$from} .. {$to})");
            $this->info("Ulanish: {$connection}  |  Sana ustuni: {$column}");
            $this->info("Manbalar: " . count($sources) . " ta");
            $this->info("Papka: {$dir}");

            $totalRows = 0;
            foreach ($sources as $source) {
                $rows = $this->exportSource($connection, $source, $column, $from, $to, $dir, $month, $delimiter);
                $totalRows += $rows;
            }

            $done = "Tugadi. Jami {$totalRows} qator, " . count($sources) . " ta fayl yuklandi -> {$dir}";
            $this->info($done);
            Log::info('export:monthly ' . $done);
            return 0;
        }
        catch (Exception $ex) {
            $this->error('Xatolik: ' . $ex->getMessage());
            Log::error('export:monthly xatolik: ' . $ex->getMessage());
            return 1;
        }
    }

    /**
     * --source berilgan bo'lsa o'shani, aks holda --set to'plamini qaytaradi.
     */
    private function resolveSources() {
        $explicit = $this->option('source');
        if (!empty($explicit)) {
            return array_values(array_unique($explicit));
        }
        $set = $this->option('set');
        if (!isset($this->sets[$set])) {
            $this->error("To'plam topilmadi: '{$set}'. Mavjud: " . implode(', ', array_keys($this->sets)));
            return [];
        }
        return $this->sets[$set];
    }

    /**
     * Chiqish papkasini hisoblaydi va yaratadi.
     */
    private function resolveDir($month) {
        $base = $this->option('path');
        if (empty($base)) {
            $base = storage_path('app/exports');
        }
        $dir = rtrim($base, "/\\") . DIRECTORY_SEPARATOR . $month;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Bitta manbani oylik filtrlab CSV'ga yozadi. Yozilgan qatorlar sonini qaytaradi.
     */
    private function exportSource($connection, $source, $column, $from, $to, $dir, $month, $delimiter) {
        $start = microtime(true);
        $this->line("  -> {$source} ...");

        $file = $dir . DIRECTORY_SEPARATOR . $source . '_' . $month . '.csv';
        $fh = fopen($file, 'w');
        if ($fh === false) {
            $this->error("     Fayl ochilmadi: {$file}");
            return 0;
        }

        // Excel UTF-8 ni to'g'ri o'qishi uchun BOM
        fwrite($fh, "\xEF\xBB\xBF");

        $written = 0;
        $headerWritten = false;

        // Katta view'lar xotirani bosmasligi uchun chunk bo'yicha o'qiymiz
        DB::connection($connection)
            ->table($source)
            ->whereBetween($column, [$from, $to])
            ->orderBy($column)
            ->chunk(5000, function ($chunk) use ($fh, &$written, &$headerWritten, $delimiter) {
                foreach ($chunk as $row) {
                    $arr = (array) $row;
                    if (!$headerWritten) {
                        fputcsv($fh, array_keys($arr), $delimiter);
                        $headerWritten = true;
                    }
                    fputcsv($fh, array_values($arr), $delimiter);
                    $written++;
                }
            });

        fclose($fh);

        if ($written === 0) {
            // Ma'lumot bo'lmasa bo'sh faylni qoldirmaymiz
            @unlink($file);
            $this->warn("     Ma'lumot yo'q ({$from}..{$to}) — o'tkazib yuborildi");
            return 0;
        }

        $sec = round(microtime(true) - $start, 2);
        $this->info("     {$written} qator -> {$source}_{$month}.csv  ({$sec} sek)");
        return $written;
    }
}
