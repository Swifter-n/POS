<?php

use App\Events\ConsignmentStockConsumed;
use App\Listeners\CreateInvoiceFromConsignment;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Ini adalah isi default yang penting untuk API, termasuk throttling
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'user.location.pos' => \App\Http\Middleware\EnsureUserHasPosLocation::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            // (tambahkan alias lain di sini jika perlu)
        ]);

    })
     ->withProviders([
        App\Providers\AuthServiceProvider::class, // <-- Daftarkan di sini
    ])
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->withEvents([
        // Daftarkan event dari Purchase Return
        // PurchaseReturnCompleted::class => [
        //     CreateDebitNoteFromPurchaseReturn::class,
        // ],
        // Daftarkan event baru untuk Konsinyasi
        // ConsignmentStockConsumed::class => [
        //     CreateInvoiceFromConsignment::class,
        // ],
    ])
    ->create();
