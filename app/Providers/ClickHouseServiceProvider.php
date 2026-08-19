<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use ClickHouseDB\Client;

class ClickHouseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register()
    {
        $this->app->singleton(Client::class, function () {
            $config = [
                'host' => env('CLICKHOUSE_HOST', 'localhost'),
                'port' => env('CLICKHOUSE_PORT', 8123),
                'username' => env('CLICKHOUSE_USERNAME', 'default'),
                'password' => env('CLICKHOUSE_PASSWORD', ''),
                'database' => env('CLICKHOUSE_DATABASE', 'default'),
            ];

            // Передаем конфигурацию в конструктор
            $client = new Client($config);

            // Дополнительные настройки
            $client->setTimeout(10);          // Таймаут соединения
            $client->setConnectTimeOut(5);   // Таймаут подключения

            return $client;
        });
    }
}
