<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
|--------------------------------------------------------------------------
| egaz-index13 — ilova konfiguratsiyasi
|--------------------------------------------------------------------------
|
| Laravel 11+ da `app/Http/Kernel.php`, `app/Console/Kernel.php`,
| `app/Exceptions/Handler.php` va `app/Providers/RouteServiceProvider.php`
| YO'Q — ularning hammasi shu faylga yig'ilgan. Quyidagi sozlamalar
| egaz-indexator (Laravel 5.5) dagi o'sha to'rt faylning AYNAN ekvivalenti.
|
| Manba mosligi:
|   withRouting(web/api/commands)     ← RouteServiceProvider::map()
|   validateCsrfTokens(except:)       ← App\Http\Middleware\VerifyCsrfToken
|   trimStrings(except:)              ← App\Http\Middleware\TrimStrings
|   api(append: throttle:60,1 + bindings) ← Kernel::$middlewareGroups['api']
|   alias(guest:)                     ← Kernel::$routeMiddleware['guest']
|
| Console komandalari `app/Console/Commands` dan avtomatik topiladi
| (Application::configure() ichida withCommands() chaqiriladi) — L5.5 dagi
| `Console\Kernel::commands()` -> `$this->load(__DIR__.'/Commands')` bilan bir xil.
|
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // L5.5 RouteServiceProvider::mapApiRoutes() — prefiks `api`
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        // DIQQAT: `health: '/up'` ATAYLAB berilmagan — egaz-indexator da bunday
        // manzil yo'q edi, URL to'plami aynan o'sha holicha qolishi kerak.
        // Shuningdek `channels:` ham berilmagan (u yerda BroadcastServiceProvider
        // izohga olingan edi, ya'ni routes/channels.php yuklanmasdi).
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ── App\Http\Middleware\TrimStrings ──────────────────────────────
        $middleware->trimStrings(except: [
            'password',
            'password_confirmation',
        ]);

        // ── App\Http\Middleware\VerifyCsrfToken::$except ─────────────────
        // Tashqi tizimlar (1C, tarozi, kamera, dispenser, UzGPS va h.k.) CSRF
        // tokeni yubormaydi — bu ro'yxatdan chiqarilsa ular 419 oladi.
        $middleware->validateCsrfTokens(except: [
            '/datatransactions', 'datatransactions',
            '/datarealizations', 'datarealizations',
            'uzgps/*', 'scales/*', 'gnp-camera', 'factory-invoice', 'dispensers', 'social-sphere', 'engraving', 'levelmeters',
            'factory-signature',
        ]);

        // ── Kernel::$middlewareGroups['api'] = ['throttle:60,1', 'bindings'] ──
        // append: EMAS, group: — L13 ning standart `api` guruhi (SubstituteBindings)
        // butunlay almashtiriladi, ya'ni tarkib egaz-indexator dagidek AYNAN shu ikkitasi.
        $middleware->group('api', [
            'throttle:60,1',
            'bindings',
        ]);

        // ── Kernel::$routeMiddleware['guest'] ────────────────────────────
        // Loyihaning o'z middleware'i: autentifikatsiyadan o'tganlarni `/home` ga
        // yuboradi (framework standarti boshqa manzilga yuboradi).
        $middleware->alias([
            'guest' => App\Http\Middleware\RedirectIfAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // App\Exceptions\Handler da faqat $dontFlash bor edi, report()/render()
        // esa parent ni chaqirardi — ya'ni framework standarti.
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
        ]);

        // DIQQAT: skeletdagi `shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))`
        // ATAYLAB olib tashlandi. Integratsiya manzillari `api/` prefiksisiz
        // (masalan POST /factory-signature) va Accept: application/json bilan
        // keladi — L5.5 dagidek `expectsJson()` bo'yicha JSON qaytishi kerak.
    })->create();
