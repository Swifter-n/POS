<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Delivery Order {{ $shipment->shipment_number }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        .header, .footer { width: 100%; text-align: center; position: fixed; }
        .header { top: 0px; }
        .footer { bottom: 0px; font-size: 10px; }
        .content { margin-top: 150px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>SURAT JALAN / DELIVERY ORDER</h1>
        <p><strong>Nomor DO:</strong> {{ $shipment->shipment_number }}</p>
    </div>

    <div class="content">
        <p><strong>Tanggal Jadwal Kirim:</strong> {{ $shipment->scheduled_for ? $shipment->scheduled_for->format('d M Y') : 'N/A' }}</p>
        <p><strong>Tanggal Aktual Kirim:</strong> {{ $shipment->shipped_at ? $shipment->shipped_at->format('d M Y') : 'N/A' }}</p>

        {{-- ========================================================== --}}
        {{-- --- LOGIKA DOKUMEN SUMBER BARU (M2M) --- --}}
        {{-- ========================================================== --}}
        <p><strong>Dokumen Sumber:</strong><br>
            @forelse($shipment->sourceables as $source)
                {{-- Tampilkan nomor SO atau STO dari relasi M2M --}}
                {{ $source->so_number ?? $source->transfer_number ?? 'N/A' }}
                @if(!$loop->last), @endif
            @empty
                N/A
            @endforelse
        </p>
        {{-- ========================================================== --}}

        <hr>

        {{-- Tampilkan Gudang Sumber (dari Shipment) --}}
        <h4>Detail Pengirim:</h4>
        <p>
            <strong>Plant Source:</strong><br>
            {{ $shipment->sourcePlant?->name ?? 'N/A' }}<br>
            {{-- {{ $shipment->sourceWarehouse?->name ?? 'N/A' }} --}}
            {{-- Alamat bisa diambil dari Plant --}}
            <br>{{ $shipment->sourcePlant?->address ?? 'Alamat plant tidak ditemukan' }}
        </p>

        {{-- ========================================================== --}}
        {{-- --- LOGIKA TUJUAN BARU (Bebas Policy) --- --}}
        {{-- ========================================================== --}}
        <h4>Detail Tujuan Pengiriman:</h4>
        <p>
            <strong>Tujuan:</strong><br>
            @if($shipment->destinationPlant)
                {{-- 1. Jika tujuan adalah Plant (STO Plant-to-Plant) --}}
                <strong>{{ $shipment->destinationPlant->name }} (Plant)</strong><br>
                {{ $shipment->destinationPlant->address }}
            @elseif($shipment->destinationOutlet)
                 {{-- 2. Jika tujuan adalah Outlet (STO Plant-to-Outlet) --}}
                <strong>{{ $shipment->destinationOutlet->name }} (Outlet)</strong><br>
                {{ $shipment->destinationOutlet->address }}
            @elseif($shipment->customer)
                 {{-- 3. Jika tujuan adalah Customer (SO) --}}
                <strong>{{ $shipment->customer->name }} (Customer)</strong><br>
                {{ $shipment->customer->address }}
            @else
                N/A
            @endif
        </p>
        {{-- ========================================================== --}}

        <h4>Armada:</h4>
        @forelse($shipment->fleets as $fleet)
            <p>
                <strong>Kendaraan:</strong> {{ $fleet->vehicle_name }} ({{ $fleet->plate_number }}) <br>
                <strong>Supir:</strong> {{ $fleet->pivot?->driver_name ?? 'N/A' }}
            </p>
        @empty
            <p>Armada belum dialokasikan.</p>
        @endforelse
        <br>

        <h4>Daftar Barang:</h4>
        <table>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Kode Produk</th>
                    <th>Nama Produk</th>
                    <th>Jumlah</th>
                </tr>
            </thead>
            <tbody>
                {{-- ========================================================== --}}
                {{-- --- LOGIKA ITEM BARU (Sederhana, dari Shipment Items) --- --}}
                {{-- (karena Qty sudah Base UoM dari Workbench) --}}
                {{-- ========================================================== --}}
                @foreach($shipment->items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $item->product?->sku ?? 'N/A' }}</td>
                        <td>{{ $item->product?->name ?? 'N/A' }}</td>
                        <td>{{ (int) $item->quantity }} {{ $item->product?->base_uom ?? 'PCS' }}</td>
                    </tr>
                @endforeach
                 {{-- ========================================================== --}}
            </tbody>
        </table>

        <br><br>

        {{-- Tanda Tangan --}}
        <table style="border: none;">
            <tr>
                <td style="border: none; text-align: center;">Disiapkan Oleh,</td>
                <td style="border: none; text-align: center;">Dikirim Oleh,</td>
                <td style="border: none; text-align: center;">Diterima Oleh,</td>
            </tr>
            <tr style="height: 80px;">
                <td style="border: none;"></td>
                <td style="border: none;"></td>
                <td style="border: none;"></td>
            </tr>
            <tr>
                <td style="border: none; text-align: center;">(___________________)</td>
                <td style="border: none; text-align: center;">(___________________)</td>
                <td style="border: none; text-align: center;">(___________________)</td>
            </tr>
