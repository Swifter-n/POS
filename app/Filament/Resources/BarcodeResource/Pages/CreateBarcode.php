<?php

namespace App\Filament\Resources\BarcodeResource\Pages;

use App\Filament\Resources\BarcodeResource;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Milon\Barcode\DNS1D;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CreateBarcode extends CreateRecord
{
    protected static string $resource = BarcodeResource::class;

    /**
     * Mutasi data SEBELUM record dibuat.
     * Di sinilah kita akan men-generate value dan gambar barcode.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $record = null;
        $host = url('/'); // URL dasar aplikasi Anda

        // 1. Tentukan 'value' (nilai) barcode secara dinamis
        if (empty($data['value'])) {
            $recordModel = $data['barcodeable_type']; // misal: "App\Models\Outlet"
            $recordId = $data['barcodeable_id'];

            if ($recordModel && $recordId) {
                $record = $recordModel::find($recordId);
            }

            if ($record) {
                switch ($data['barcodeable_type']) {
                    case Outlet::class:
                        // Nilai QR Meja = URL unik untuk meja tsb
                        // Ganti '/table/' dengan path Anda jika berbeda
                        $data['value'] = $host . '/table/' . $record->code . '/' . $data['code']; // Cth: .../BOGOR/M1
                        break;
                    case Product::class:
                        // Nilai Barcode Produk = SKU atau barcode internal produk
                        $data['value'] = $record->barcode ?? $record->sku ?? $data['code'];
                        break;
                    case Location::class:
                        // Nilai Barcode Bin/Pallet = Kode unik lokasi
                        $data['value'] = $record->code ?? $data['code']; // 'code' dari Location
                        break;
                    default:
                        // Fallback
                        $data['value'] = $data['code'];
                }
            } else {
                // Fallback jika record tidak ditemukan
                $data['value'] = $data['code'];
            }
        }

        // 2. Generate gambar barcode/QR
        $imagePath = 'barcodes/' . $data['code'] . '.svg';

        try {
            if ($data['type'] === 'qr_code') {
                $svgContent = QrCode::margin(1)->size(200)->generate($data['value']);
                Storage::disk('public')->put($imagePath, $svgContent);
                $data['image'] = $imagePath;
            }
            // ==========================================================
            // --- (PERBAIKAN 2) LOGIKA UNTUK EAN-13 DAN Code-128 ---
            // ==========================================================
            else if ($data['type'] === 'ean13') {
                $generator = new DNS1D();
                // (value, type, width_factor, height)
                // Pastikan $data['value'] adalah string 12 atau 13 digit yang valid untuk EAN13
                $barcodeContent = $generator->getBarcodeSVG($data['value'], 'EAN13', 2, 60);
                Storage::disk('public')->put($imagePath, $barcodeContent);
                $data['image'] = $imagePath;
            }
            else if ($data['type'] === 'code128') {
                $generator = new DNS1D();
                // (value, type, width_factor, height)
                // C128 adalah tipe untuk Code128 di library ini
                $barcodeContent = $generator->getBarcodeSVG($data['value'], 'C128', 2, 60);
                Storage::disk('public')->put($imagePath, $barcodeContent);
                $data['image'] = $imagePath;
            }
            // ==========================================================
            else {
                // Jika tipe lain tidak di-support, catat log dan jangan simpan gambar
                 Log::warning("Barcode generation skipped: Unsupported type '{$data['type']}' for code {$data['code']}.");
                 $data['image'] = null; // Set ke null jika tipe tidak didukung
            }

        } catch (\Exception $e) {
            // Tangani error jika generator gagal (misal: $data['value'] tidak valid untuk EAN13)
             Log::error("Barcode generation failed for code {$data['code']}: " . $e->getMessage());
             $data['image'] = null; // Gagal membuat gambar
        }

        return $data;
    }

    // Arahkan kembali ke halaman index setelah create
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
