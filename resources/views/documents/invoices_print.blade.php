<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-g">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: 'Helvetica', sans-serif;
            font-size: 12px;
            color: #333;
        }
        .container {
            width: 100%;
            margin: 0 auto;
        }
        .header, .footer {
            width: 100%;
            text-align: center;
            position: fixed;
        }
        .header { top: 0px; }
        .footer { bottom: 0px; font-size: 10px; color: #777; }
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
        }
        .invoice-header .logo {
            width: 150px;
        }
        .invoice-header .company-details {
            text-align: right;
        }
        .invoice-details {
            margin-bottom: 30px;
        }
        .bill-to {
            margin-bottom: 30px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th, td {
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .totals {
            width: 40%;
            float: right;
        }
        .totals table td:first-child {
            text-align: right;
            font-weight: bold;
        }
        .notes {
            clear: both;
            margin-top: 30px;
        }
        h1, h2, h3 { margin: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="invoice-header">
            <div class="company-logo">
                {{-- <img src="{{ public_path('logo.png') }}" alt="Company Logo" class="logo"> --}}
                <h3>NAMA PERUSAHAAN ANDA</h3>
                <p>
                    Alamat Perusahaan Anda<br>
                    Kota, Kode Pos<br>
                    Email: kontak@perusahaan.com<br>
                    Telepon: (021) 123-4567
                </p>
            </div>
            <div class="company-details">
                <h1>INVOICE</h1>
                <p>
                    <strong>Invoice #:</strong> {{ $invoice->invoice_number }}<br>
                    <strong>Order #:</strong> {{ $invoice->salesOrder->so_number }}<br>
                    <strong>Tanggal Invoice:</strong> {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}<br>
                    <strong>Jatuh Tempo:</strong> {{ \Carbon\Carbon::parse($invoice->due_date)->format('d M Y') }}
                </p>
            </div>
        </div>

        <div class="bill-to">
            <h3>Bill To:</h3>
            <p>
                <strong>{{ $invoice->customer->name }}</strong><br>
                {{-- Anda bisa tambahkan alamat customer di sini --}}
                {{ $invoice->customer->address ?? '' }}<br>
                {{ $invoice->customer->phone ?? '' }}
            </p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item Description</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->product->name }}</td>
                    <td>{{ $item->quantity }} {{ $item->uom }}</td>
                    <td>{{ number_format($item->price_per_item, 0, ',', '.') }}</td>
                    <td>{{ number_format($item->total_price, 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            <table>
                <tr>
                    <td>Subtotal</td>
                    <td>Rp {{ number_format($invoice->sub_total, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>Discount</td>
                    <td>- Rp {{ number_format($invoice->total_discount, 0, ',', '.') }}</td>
                </tr>
                 <tr>
                    <td>Shipping</td>
                    <td>Rp {{ number_format($invoice->shipping_cost, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>Tax</td>
                    <td>Rp {{ number_format($invoice->tax, 0, ',', '.') }}</td>
                </tr>
                <tr style="font-weight: bold; font-size: 1.2em;">
                    <td>Grand Total</td>
                    <td>Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                </tr>
            </table>
        </div>

        <div class="notes">
            <h3>Notes:</h3>
            <p>Mohon lakukan pembayaran ke rekening berikut:</p>
            <p><strong>Bank BCA: 123-456-7890</strong> a/n Avis</p>
            <br>
            <p>Terima kasih atas kerja sama Anda.</p>
        </div>
    </div>
</body>
</html>
