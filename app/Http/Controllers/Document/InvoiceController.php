<?php

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceController extends Controller
{
    public function print(Invoice $invoice)
    {
        // Eager load relasi agar query lebih efisien di dalam view
        $invoice->load(['customer', 'items.product']);

        // Buat PDF dari file Blade view, dan kirim data $invoice
        $pdf = Pdf::loadView('documents.invoices_print', ['invoice' => $invoice]);

        // Tampilkan PDF di browser, bukan diunduh
        return $pdf->stream('invoice-' . $invoice->invoice_number . '.pdf');
    }
}
