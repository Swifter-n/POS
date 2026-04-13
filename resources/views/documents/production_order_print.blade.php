<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Production Order {{ $productionOrder->production_order_number }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        .header { text-align: center; }
        .content { margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #999; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <div class="header">
        <h1>FORMULIR PERINTAH PRODUKSI (SPK)</h1>
        <p><strong>Nomor:</strong> {{ $productionOrder->production_order_number }}</p>
    </div>

    <div class="content">
        <p><strong>Tanggal:</strong> {{ $productionOrder->created_at->format('d M Y') }}</p>
        <p><strong>Plant:</strong> {{ $productionOrder->plant?->name ?? 'N/A' }}</p>
        <p><strong>Warehouse:</strong> {{ $warehouse?->name ?? 'N/A' }}</p>

        <h4>Detail Produksi:</h4>
        <p>
            <strong>Produk Jadi:</strong> {{ $productionOrder->finishedGood?->name ?? 'N/A' }}
            (SKU: {{ $productionOrder->finishedGood?->sku ?? 'N/A' }})<br>
            <strong>Jumlah Rencana (Target):</strong> {{ (float) $productionOrder->quantity_planned }} {{ $productionOrder->finishedGood?->base_uom ?? 'PCS' }}
        </p>
        <p><strong>Catatan PO:</strong> {{ $productionOrder->notes ?? '-' }}</p>

        {{-- ========================================================== --}}
        {{-- --- BAGIAN 1: TAMPILKAN BILL OF MATERIALS (SPK) --- --}}
        {{-- ========================================================== --}}
        @if($bomItems && $bomItems->isNotEmpty())
            <h4>Daftar Kebutuhan Material (BOM)</h4>
            <div style="margin-top: 15px; page-break-inside: avoid;">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Kode Komponen</th>
                            <th>Nama Komponen</th>
                            <th>Qty (per 1 Unit)</th>
                            <th>Qty Total Dibutuhkan</th>
                            <th>Tipe Penggunaan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bomItems as $index => $bomItem)
                            {{-- Hanya tampilkan RM yang dikonsumsi pabrik --}}
                            @if($bomItem->usage_type == 'RAW_MATERIAL')
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $bomItem->product?->sku ?? 'N/A' }}</td>
                                    <td>{{ $bomItem->product?->name ?? 'N/A' }}</td>
                                    <td>{{ (float) $bomItem->quantity }} {{ $bomItem->uom }}</td>
                                    {{-- Hitung total kebutuhan (Qty BOM * Qty PO) --}}
                                    <td>
                                        {{ (float) $bomItem->quantity * (float) $productionOrder->quantity_planned }}
                                        {{ $bomItem->uom }}
                                    </td>
                                    <td>{{ $bomItem->usage_type }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
             <p><strong>Error: BOM (Bill of Materials) tidak ditemukan untuk produk ini.</strong></p>
        @endif
        {{-- ========================================================== --}}


        {{-- ========================================================== --}}
        {{-- --- BAGIAN 2: TAMPILKAN PICKING LIST (KKP) JIKA ADA --- --}}
        {{-- ========================================================== --}}
        @if($pickingList)
            <div class="page-break"></div> {{-- Pisah halaman untuk KKP --}}
            <div class="header">
                <h1>KERTAS KERJA PICKING (KKP)</h1>
                <p><strong>Nomor PL:</strong> {{ $pickingList->picking_list_number }}</p>
                <p><strong>PIC:</strong> {{ $pickingList->user?->name ?? 'N/A' }}</p>
                <p><strong>Sumber PO:</strong> {{ $productionOrder->production_order_number }}</p>
            </div>

            <div class="content">
                <h4>Daftar Ambil Bahan Baku (Total Kebutuhan):</h4>
                @foreach($pickingList->items as $item)
                    <div style="margin-top: 15px; page-break-inside: avoid;">
                        <p><strong>Bahan: {{ $item->product?->name ?? 'N/A' }} (Total: {{ (float)$item->total_quantity_to_pick }} {{ $item->uom }})</strong></p>
                        <table>
                            <thead>
                                <tr>
                                    <th>Lokasi Sumber</th>
                                    <th>Zona</th>
                                    <th>Batch</th>
                                    <th>Exp. Date</th>
                                    <th>Jumlah Ambil</th>
                                    <th>Kolom Cek Fisik</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($item->sources as $source)
                                    <tr>
                                        <td>{{ $source->inventory?->location?->name ?? 'N/A' }}</td>
                                        <td>{{ $source->inventory?->location?->zone?->code ?? 'N/A' }}</td>
                                        <td>{{ $source->inventory?->batch ?? 'N/A' }}</td>
                                        <td>{{ $source->inventory?->sled ? \Carbon\Carbon::parse($source->inventory->sled)->format('d M Y') : 'N/A' }}</td>
                                        <td>{{ (float)$source->quantity_to_pick_from_source }}</td>
                                        <td style="width: 100px;"></td> {{-- Kolom kosong untuk ceklis manual --}}
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" style="text-align: center;">- Instruksi Pengambilan Tidak Ditemukan -</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        @else
            <div style="margin-top: 20px; page-break-inside: avoid;">
                <p><em>(Picking List belum dibuat)</em></p>
            </div>
        @endif
        {{-- ========================================================== --}}


        <div style="margin-top: 40px; page-break-inside: avoid;">
            <h4>Hasil Produksi (Diisi Operator):</h4>
            <table style="width: 60%;">
                <tr>
                    <th style="width: 50%;">Jumlah Berhasil (PCS)</th>
                    <td style="height: 30px;"></td>
                </tr>
                <tr>
                    <th>Jumlah Gagal (PCS)</th>
                    <td style="height: 30px;"></td>
                </tr>
                 <tr>
                    <th>Batch Produksi Jadi</th>
                    <td style="height: 30px;"></td>
                </tr>
            </table>
            <p><strong>Catatan:</strong></p>
            <div style="border: 1px solid #999; height: 80px;"></div>
        </div>

        <table style="width: 100%; margin-top: 50px; border: none;">
             <tr>
                <td style="border: none; text-align: center;">Disiapkan Oleh,</td>
                <td style="border: none; text-align: center;">Dieksekusi Oleh,</td>
            </tr>
            <tr style="height: 80px;">
                <td style="border: none;"></td>
                <td style="border: none;"></td>
            </tr>
            <tr>
                <td style="border: none; text-align: center;">(___________________)</td>
                <td style="border: none; text-align: center;">(___________________)</td>
            </tr>
        </table>
    </div>
</body>
</html>
