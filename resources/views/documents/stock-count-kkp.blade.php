<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>KKP {{ $stockCount->count_number }}</title>
    <style>
        body { font-family: sans-serif; font-size: 10px; }
        .header { text-align: center; }
        .content { margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #999; padding: 6px; text-align: left; }
        th { background-color: #f2f2f2; }
        .page-break { page-break-after: always; }
        .text-center { text-align: center; }
        .notes { font-style: italic; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>KERTAS KERJA PEMERIKSAAN (KKP)</h1>
        <p><strong>Nomor Dokumen:</strong> {{ $stockCount->count_number }}</p>
    </div>

    <div class="content">
        <p><strong>Tanggal Dibuat:</strong> {{ $stockCount->created_at->format('d M Y H:i') }}</p>
        <p><strong>Plant:</strong> {{ $stockCount->plant?->name ?? 'N/A' }}</p>
        <p><strong>Lokasi Hitung:</strong> {{ $stockCount->countable?->name ?? 'N/A' }}</p>
        <p><strong>Zona:</strong> <strong style="font-size: 14px;">{{ $stockCount->zone?->name ?? 'SEMUA ZONA' }}</strong></p>
        <p class="notes">Harap isi kolom "Hitungan Tim" (Tim 1 / Tim 2) dengan jumlah fisik yang ditemukan. Jangan mengisi kolom lain.</p>

        {{-- ========================================================== --}}
        {{-- --- TABEL ITEM YANG SUDAH DIURUTKAN --- --}}
        {{-- ========================================================== --}}
        <div style="margin-top: 15px;">
            <table>
                <thead>
                    <tr>
                        <th class="text-center">No.</th>
                        <th>Lokasi</th>
                        <th>Zona</th>
                        <th>SKU</th>
                        <th>Nama Produk</th>
                        <th>Batch</th>
                        <th>SLED</th>
                        <th>Stok Sistem</th>
                        <th>Satuan</th>
                        <th style="width: 100px;">Hitungan Tim 1 (Kuning)</th>
                        <th style="width: 100px;">Hitungan Tim 2 (Hijau)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $index => $item)
                        @php
                            $inventory = $item->inventory;
                            $location = $inventory?->location;
                            $product = $item->product;
                        @endphp
                        <tr>
                            <td class="text-center">{{ $index + 1 }}</td>
                            <td>{{ $location?->name ?? 'N/A' }} ({{ $location?->code ?? 'N/A' }})</td>
                            <td>{{ $location?->zone?->code ?? 'N/A' }}</td>
                            <td>{{ $product?->sku ?? 'N/A' }}</td>
                            <td>
                                {{ $product?->name ?? 'N/A' }}
                                @if($location?->ownership_type === 'consignment')
                                    <br><span class="notes">(Consignment: {{ $location?->supplier?->name ?? '??' }})</span>
                                @endif
                            </td>
                            <td>{{ $item->batch ?? 'N/A' }}</td>
                            <td>{{ $inventory?->sled ? \Carbon\Carbon::parse($inventory->sled)->format('d M Y') : 'N/A' }}</td>
                            <td class="text-center">{{ (float)$item->system_stock }}</td>
                            <td>{{ $product?->base_uom ?? 'PCS' }}</td>

                            {{-- Kolom kosong untuk input manual --}}
                            <td></td>
                            <td></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center">Tidak ada item inventaris yang ditemukan (di-snapshot) untuk kriteria ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{-- ========================================================== --}}


        <table style="width: 100%; margin-top: 50px; border: none;">
             <tr>
                <td style="border: none; text-align: center;">Dibuat Oleh,</td>
                <td style="border: none; text-align: center;">Tim Hitung 1 (Kuning)</td>
                <td style="border: none; text-align: center;">Tim Hitung 2 (Hijau)</td>
                <td style="border: none; text-align: center;">Validator (Putih)</td>
            </tr>
            <tr style="height: 80px;">
                <td style="border: none;"></td>
                <td style="border: none;"></td>
                <td style="border: none;"></td>
                <td style="border: none;"></td>
            </tr>
            <tr>
                <td style="border: none; text-align: center;">(___________________)</td>
                <td style="border: none; text-align: center;">(___________________)</td>
                <td style="border: none; text-align: center;">(___________________)</td>
                <td style="border: none; text-align: center;">(___________________)</td>
            </tr>
        </table>
    </div>
</body>
</html>
