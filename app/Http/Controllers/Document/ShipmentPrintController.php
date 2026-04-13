<?php

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\StockTransfer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ShipmentPrintController extends Controller
{
    public function printDO($id)
    {
        try {
            // 1. Gunakan findOrFail
            // 2. EAGER LOAD relasi LANGSUNG dari Shipment
            $shipment = Shipment::with([
                'items.product', // Daftar item final (sudah Base UoM)
                'fleets',          // Armada yang ditugaskan

                // Relasi untuk Alamat SUMBER
                'sourcePlant',
                'sourceWarehouse',

                // Relasi untuk Alamat TUJUAN
                'destinationPlant',
                'destinationOutlet',
                'customer', // Relasi ke customer (jika SO)

                // ==========================================================
                // --- INI PERBAIKANNYA ---
                // ==========================================================
                // Hapus 'sourceables' (karena itu accessor)
                // 'sourceables',

                // Ganti dengan relasi aslinya:
                'salesOrders',
                'stockTransfers',
                // ==========================================================

            ])->findOrFail($id);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404, "Shipment dengan ID $id tidak ditemukan.");
        }

        // =Dihapus)
        // (Kita tidak lagi bergantung padanya untuk data krusial)
        // ==========================================================

        // 4. Arahkan ke view yang benar
        //    Di view 'documents.shipment_do', Anda tetap bisa memanggil
        //    $shipment->sourceables (accessor-nya akan otomatis menggabungkan
        //    'salesOrders' dan 'stockTransfers' yang sudah kita load)
        $pdf = Pdf::loadView('documents.shipment_do', ['shipment' => $shipment]);

        return $pdf->stream("DO-{$shipment->shipment_number}.pdf");
    }
}
