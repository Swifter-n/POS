<x-filament-panels::page>
    {{-- Baris ini akan merender form dari method form() Anda --}}
    {{ $this->form }}

    {{--
        Tampilkan QR Code hanya jika table_number sudah ada di dalam state form.
        Ini untuk mencegah error saat halaman pertama kali dimuat.
    --}}
    @if (isset($this->data['table_number']))
        <div class="mt-4 flex justify-center">
            {!! QrCode::size(200)->margin(1)->generate($this->data['table_number']) !!}
        </div>
    @endif

    {{--
        Tombol ini terhubung ke method save() di class CreateQr.php Anda.
        Saya menggantinya dengan komponen Filament Actions agar lebih konsisten.
    --}}
    <div class="mt-6">
        <x-filament::button wire:click="save" color="primary">
            Create QR Code
        </x-filament::button>
    </div>

</x-filament-panels::page>
