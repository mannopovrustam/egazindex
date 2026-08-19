<?php

namespace App\Database\DualWrite;

use Illuminate\Database\MySqlConnection as BaseConnection;

/**
 * MySQL ulanishi — so'rov quruvchisi sifatida yozishni ushlab qoladigan
 * App\Database\DualWrite\Builder ni qaytaradi.
 *
 * `mysql` DRAYVERIDAGI HAMMA ulanish (mysql, mysql1, mysql_brrgz, mysql_egaz)
 * shu klassdan foydalanadi — App\Providers\DualWriteServiceProvider shunday
 * ro'yxatga oladi. Lekin nusxa FAQAT config/dual_write.php → `pairs` da
 * ko'rsatilgan ulanishlar uchun olinadi; qolganlari uchun bu klass oddiy
 * MySqlConnection dan farq qilmaydi.
 *
 * Boshqa hech narsa o'zgarmaydi: o'qish, tranzaksiya, grammatika, sxema —
 * hammasi framework standarti.
 */
class MySqlConnection extends BaseConnection
{
    /**
     * Yangi so'rov quruvchisi.
     *
     * @return \App\Database\DualWrite\Builder
     */
    public function query()
    {
        return new Builder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }
}
