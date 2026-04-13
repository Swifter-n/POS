<?php

use App\Http\Controllers\Document\InvoiceController;
use App\Http\Controllers\Document\ProductionPrintController;
use App\Http\Controllers\Document\ShipmentPrintController;
use App\Http\Controllers\Document\StockCountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/shipments/{shipment}/print', [ShipmentPrintController::class, 'printDo'])
    ->middleware('auth') // Pastikan hanya user yang login yang bisa akses
    ->name('shipments.print.do');

Route::get('/production-orders/{productionOrder}/print', [ProductionPrintController::class, 'printPo'])
    ->middleware('auth')
    ->name('production-orders.print');

Route::get('invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');

Route::get('stock-counts/{stockCount}/print-kkp', [StockCountController::class, 'printKKP'])
    ->name('stock-counts.print-kkp')
    ->middleware('auth');
