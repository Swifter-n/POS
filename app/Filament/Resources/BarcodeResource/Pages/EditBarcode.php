<?php

namespace App\Filament\Resources\BarcodeResource\Pages;

use App\Filament\Resources\BarcodeResource;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Milon\Barcode\DNS1D;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class EditBarcode extends EditRecord
{
    protected static string $resource = BarcodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Mutasi data SEBELUM record di-update.
     * Kita duplikasi logika dari CreateBarcode untuk konsistensi
     * agar gambar barcode di-generate ulang jika ada perubahan.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = null;
        $host = url('/'); // URL dasar aplikasi Anda

        // 1. Tentukan 'value' (nilai) barcode secara dinamis
        if (empty($data['value'])) {
            $recordModel = $data['barcodeable_type'];
            $recordId = $data['barcodeable_id'];

            if ($recordModel && $recordId) {
                $record = $recordModel::find($recordId);
            }

            if ($record) {
                switch ($data['barcodeable_type']) {
                    case Outlet::class:
                        $data['value'] = $host . '/table/' . $record->code . '/' . $data['code'];
                        break;
                    case Product::class:
                        $data['value'] = $record->barcode ?? $record->sku ?? $data['code'];
                        break;
                    case Location::class:
                        $data['value'] = $record->code ?? $data['code'];
                        break;
                    default:
                        $data['value'] = $data['code'];
                }
            } else {
                $data['value'] = $data['code'];
            }
        }

        // 2. Generate ulang gambar barcode/QR
        $imagePath = 'barcodes/' . $data['code'] . '.svg';

        try {
            if ($data['type'] === 'qr_code') {
                $svgContent = QrCode::margin(1)->size(200)->generate($data['value']);
                Storage::disk('public')->put($imagePath, $svgContent);
                $data['image'] = $imagePath;
            }
            else if ($data['type'] === 'ean13') {
                $generator = new DNS1D();
                $barcodeContent = $generator->getBarcodeSVG($data['value'], 'EAN13', 2, 60);
                Storage::disk('public')->put($imagePath, $barcodeContent);
                $data['image'] = $imagePath;
            }
            else if ($data['type'] === 'code128') {
                $generator = new DNS1D();
                $barcodeContent = $generator->getBarcodeSVG($data['value'], 'C128', 2, 60);
                Storage::disk('public')->put($imagePath, $barcodeContent);
                $data['image'] = $imagePath;
            }
            else {
                 Log::warning("Barcode generation skipped: Unsupported type '{$data['type']}' for code {$data['code']}.");
                 $data['image'] = null;
            }

        } catch (\Exception $e) {
             Log::error("Barcode generation failed for code {$data['code']}: " . $e->getMessage());
             $data['image'] = null;
        }

        return $data;
    }
}
