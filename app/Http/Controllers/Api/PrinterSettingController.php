<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class PrinterSettingController extends Controller
{
    public function getPrinterSettings(Request $request): JsonResponse
    {
        // 1. Dapatkan user dan outlet-nya
        // (Middleware 'user.location.pos' sudah me-load relasi 'locationable')
        $user = $request->user();
        $outlet = $user->locationable;

        // 2. Ambil SEMUA printer yang terdaftar HANYA di outlet ini
        $allPrinters = Printer::where('outlet_id', $outlet->id)->get([
            'id', 'name', 'connection_type', 'mac_address', 'ip_address', 'paper_width', 'default'
        ]);

        // 3. Cari printer 'default' (sesuai toggle di Filament)
        // Ini diasumsikan sebagai printer INVOICE / KASIR
        $defaultPrinter = $allPrinters->where('default', true)->first();

        // 4. Kembalikan data yang bersih dan siap pakai untuk Flutter
        return response()->json([
            // 'default_printer' digunakan untuk cetak INVOICE/STRUK
            'default_printer' => $defaultPrinter,

            // 'all_printers' digunakan jika Flutter perlu menampilkan
            // daftar untuk memilih printer (misal: untuk CHECKER DAPUR)
            'all_printers' => $allPrinters,
        ]);
    }
}
