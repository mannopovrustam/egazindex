<?php

namespace App\Providers;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use App\Database\DualWrite\MySqlConnection;

/**
 * Dual write ni ilovaga ulaydi: `mysql` drayveri uchun ulanish klassini
 * almashtiradi, xolos.
 *
 * Shu bitta almashtirish tufayli chaqiruv joylarini o'zgartirish kerak
 * bo'lmadi — ilovadagi barcha `DB::table(...)->insert/update/delete/...`
 * amallari nusxa oladi (config/dual_write.php dagi juftliklar bo'yicha).
 *
 * ⚠ Bu provayder DOIM ro'yxatga olinadi, hatto `DUAL_WRITE=false` bo'lganda
 *   ham: Builder bayroqni HAR BIR amalda tekshiradi, ya'ni o'chirilgan holatda
 *   qo'shimcha ishi yo'q. Shu sababli bayroqni yoqish/o'chirish uchun
 *   provayderga tegish kerak emas.
 *
 * ⚠ `mariadb` drayveri ATAYLAB ro'yxatga olinmadi — bu loyihada MySQL
 *   ishlatiladi (config/database.php). MariaDB ga o'tilsa shu yerga
 *   MariaDbConnection ning shunga o'xshash vorisini qo'shish kerak.
 *
 * @see config/dual_write.php
 * @see docs/dual-write.md
 */
class DualWriteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor('mysql', function ($pdo, $database, $prefix, $config) {
            return new MySqlConnection($pdo, $database, $prefix, $config);
        });
    }
}
