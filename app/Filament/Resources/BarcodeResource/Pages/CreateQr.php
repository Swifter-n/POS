<?php

namespace App\Filament\Resources\BarcodeResource\Pages;

use App\Filament\Resources\BarcodeResource;
use App\Models\Barcode;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms; // 1. Import Trait untuk Form
use Filament\Forms\Contracts\HasForms;          // 2. Import Contract untuk Form
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;      // 3. Import Builder untuk kueri
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Milon\Barcode\DNS1D;

class CreateQr extends CreateRecord
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
            // --- CONTOH LOGIKA UNTUK BARCODE LAIN (EAN13/Code128) ---
            // ==========================================================
            else if ($data['type'] === 'ean13') {
                // Pastikan Anda sudah `composer require milon/barcode`
                $generator = new DNS1D();
                $barcodeContent = $generator->getBarcodeSVG($data['value'], 'EAN13');
                Storage::disk('public')->put($imagePath, $barcodeContent);
                $data['image'] = $imagePath;
            }
            else if ($data['type'] === 'code128') {
                $generator = new DNS1D();
                $barcodeContent = $generator->getBarcodeSVG($data['value'], 'C128');
                Storage::disk('public')->put($imagePath, $barcodeContent);
                $data['image'] = $imagePath;
            }
            // ==========================================================
            else {
                // Jika tipe lain tidak di-support, simpan path saja
                // (Anda bisa tambahkan logika generator barcode lain di sini)
                $data['image'] = $imagePath;
            }

        } catch (\Exception $e) {
            // Tangani error jika generator gagal
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
