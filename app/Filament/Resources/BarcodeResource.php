<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BarcodeResource\Pages;
use App\Filament\Resources\BarcodeResource\RelationManagers;
use App\Models\Barcode;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Zone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BarcodeResource extends Resource
{
    protected static ?string $model = Barcode::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationLabel = 'Barcodes';
    protected static ?string $navigationGroup = 'Business Management';

    private static function userHasPermission(string $permissionName): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        if (self::userHasRole('Owner')) return true;

        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)->where('model_id', $user->id)
            ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.name', $permissionName)
            ->exists();
    }
    private static function userHasRole(string $roleName): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)->where('model_id', $user->id)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', $roleName)
            ->exists();
    }


    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user || !$user->business_id) return parent::getEloquentQuery()->whereRaw('0 = 1');

        $query = parent::getEloquentQuery();

        // Jika Owner, filter berdasarkan business_id dari TIGA kemungkinan parent
        if (self::userHasRole('Owner')) {
            $query->where(function (Builder $q) use ($user) {

                // 1. Tipe Product
                $q->where(function (Builder $subQ) use ($user) {
                    $subQ->where('barcodeable_type', Product::class)
                         ->whereHasMorph('barcodeable', [Product::class], fn($prod) => $prod->where('business_id', $user->business_id));
                });

                // 2. Tipe Outlet (Table)
                $q->orWhere(function (Builder $subQ) use ($user) {
                    $subQ->where('barcodeable_type', Outlet::class)
                         ->whereHasMorph('barcodeable', [Outlet::class], fn($otl) => $otl->where('business_id', $user->business_id));
                });

                // 3. Tipe Location (Bin/Pallet)
                $q->orWhere(function (Builder $subQ) use ($user) {
                    $subQ->where('barcodeable_type', Location::class)
                         ->whereHasMorph('barcodeable', [Location::class], function (Builder $locationQuery) use ($user) {

                             // --- INI LOGIKA BARUNYA ---
                             // Di dalam query Lokasi, kita cek parent-nya (locatable)
                             $locationQuery->whereHasMorph('locatable', [Warehouse::class, Outlet::class], function (Builder $locatableQuery, string $type) use ($user) {

                                 // Jika parent-nya Warehouse, cek relasi 'plant'
                                 if ($type === Warehouse::class) {
                                     $locatableQuery->whereHas('plant', fn($plant) => $plant->where('business_id', $user->business_id));
                                 }
                                 // Jika parent-nya Outlet, cek relasi 'supplyingPlant'
                                 if ($type === Outlet::class) {
                                     $locatableQuery->whereHas('supplyingPlant', fn($plant) => $plant->where('business_id', $user->business_id));
                                 }
                             });
                         });
                });

            });
        }

        // (Tambahkan logika filter untuk non-Owner jika perlu,
        //  mirip seperti di InventoryResource)

        return $query;
    }



    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // 1. Tipe Barcode (QR atau EAN13, dll)
                Forms\Components\Select::make('type')
                    ->options([
                        'qr_code' => 'QR Code (untuk Meja, Lokasi)',
                        'ean13' => 'EAN-13 (untuk Produk)',
                        'code128' => 'Code 128 (untuk Pallet/Bin)',
                    ])
                    ->required()
                    ->live()
                    ->default('qr_code')
                    ->columnSpanFull(),

                // 2. Tipe Model (Menempel ke mana?)
                Forms\Components\Select::make('barcodeable_type')
                    ->label('Barcode For')
                    ->options([
                        Product::class => 'Product (Finished Good)',
                        Location::class => 'Location (Pallet/Bin)',
                        Outlet::class => 'Outlet (Table)',
                    ])
                    ->required()
                    ->live() // Wajib live()
                    ->columnSpanFull(),

                // 3. Pilihan Model (Dinamis)
                Forms\Components\Select::make('barcodeable_id')
                    ->label('Select Item')
                    // Tampilkan field ini HANYA JIKA Tipe Model sudah dipilih
                    ->visible(fn (Get $get) => !empty($get('barcodeable_type')))
                    ->options(function (Get $get): array {
                        $type = $get('barcodeable_type');
                        if ($type === Product::class) {
                            return Product::where('business_id', Auth::user()->business_id)
                                ->where('product_type', 'finished_good')
                                ->pluck('name', 'id')
                                ->toArray();
                        }
                        if ($type === Location::class) {
                            // Asumsi Anda punya Model Zone
                            return Location::whereHas('locatable.plant', fn($q) => $q->where('business_id', Auth::user()->business_id))
                                ->whereIn('zone_id', Zone::whereIn('code', ['PALLET', 'BIN'])->pluck('id')) // Asumsi Zona Pallet/Bin
                                ->pluck('name', 'id')
                                ->toArray();
                        }
                        if ($type === Outlet::class) {
                            return Outlet::where('business_id', Auth::user()->business_id)
                                ->pluck('name', 'id')
                                ->toArray();
                        }
                        return [];
                    })
                    ->searchable()
                    ->preload()
                    ->required()
                    ->columnSpanFull(),

                // 4. Kode Barcode
                Forms\Components\TextInput::make('code')
                    ->label('Barcode Code / Table Number')
                    ->helperText('Untuk Produk/Pallet, ini adalah kode unik. Untuk Meja, ini adalah Nomor Meja.')
                    ->required()
                    ->maxLength(255)
                    ->default(fn() => strtoupper(chr(rand(65, 90)) . rand(1000, 9999)))
                    ->columnSpanFull(),

                // 5. Value (Dibuat otomatis)
                Forms\Components\TextInput::make('value')
                    ->label('Barcode Value')
                    ->helperText('Akan di-generate otomatis saat simpan jika kosong.')
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('status')
                    ->default(true)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Ganti 'table_number' menjadi 'code'
                Tables\Columns\TextColumn::make('code')
                    ->label('Code / Table')
                    ->searchable()
                    ->sortable(),

                // Kolom Dinamis (Menampilkan Parent)
                Tables\Columns\TextColumn::make('barcodeable_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => class_basename($state)) // Tampilkan nama model (Product, Location)
                    ->badge(),

                Tables\Columns\TextColumn::make('barcodeable.name') // Asumsi semua parent punya 'name'
                    ->label('Attached To')
                    ->searchable(),

                // Ganti 'qr_value' menjadi 'value'
                Tables\Columns\TextColumn::make('value')
                    ->label('Value')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('type') // Tampilkan tipe QR/Barcode
                    ->badge(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('d-M-Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Filter baru untuk tipe
                Tables\Filters\SelectFilter::make('barcodeable_type')
                    ->label('Type')
                    ->options([
                        Product::class => 'Product',
                        Location::class => 'Location',
                        Outlet::class => 'Outlet (Table)',
                    ])
            ])
            ->actions([
            Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    // --- PERBAIKAN 2: Ganti Tipe Return Hint ---
                    ->action(function (Barcode $record): ?BinaryFileResponse {

                        // Cek jika file ada
                        if ($record->image && Storage::disk('public')->exists($record->image)) {
                            $fileName = $record->code . '.svg';

                            // --- PERBAIKAN 3: Ganti Logika Download ---
                            // Ambil path absolut ke file di storage
                            $path = Storage::disk('public')->path($record->image);
                            // Gunakan helper response() global
                            return response()->download($path, $fileName);
                        }

                        // Kirim notifikasi jika file tidak ditemukan
                        Notification::make()
                            ->title('Download Failed')
                            ->body('Barcode image file not found in storage.')
                            ->danger()
                            ->send();
                        return null;
                    }),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBarcodes::route('/'),
            'create' => Pages\CreateBarcode::route('/create'),
            'edit' => Pages\EditBarcode::route('/{record}/edit'),
        ];
    }
}
