<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages;
use App\Filament\Resources\LocationResource\RelationManagers;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Warehouse;
use App\Models\Zone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Master Data';

public static function getEloquentQuery(): Builder
    {
        // === PERBAIKAN: Filter melalui relasi polimorfik 'locatable' ===
        return parent::getEloquentQuery()->whereHas('locatable', function (Builder $query) {
            $query->where('business_id', Auth::user()->business_id);
        });
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Location Details')
                    ->schema([
                        // Komponen khusus untuk memilih induk polimorfik (Warehouse/Outlet)
                        Forms\Components\MorphToSelect::make('locatable')
                            ->label('Parent Location (Warehouse/Outlet)')
                            ->types([
                                Forms\Components\MorphToSelect\Type::make(Warehouse::class)->titleAttribute('name'),
                                Forms\Components\MorphToSelect\Type::make(Outlet::class)->titleAttribute('name'),
                            ])
                            ->searchable()->preload()->required(),

                        Forms\Components\Select::make('parent_id')
                            ->label('Parent in Hierarchy (Optional)')
                            ->relationship('parent', 'name') // Relasi hierarki internal
                            ->searchable()->preload(),

                        Forms\Components\TextInput::make('name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('code')->maxLength(50),
                        Forms\Components\Select::make('type')->options([
                            'WAREHOUSE' => 'Warehouse', 'OUTLET' => 'Outlet',
                            'AREA' => 'Area', 'RACK' => 'Rack', 'BIN' => 'Bin', 'PALLET' => 'Pallet',
                        ])->required()->live(),
                        Forms\Components\Select::make('zone_id')->relationship('zone', 'name')->searchable()->preload()->live(),
                        Forms\Components\TextInput::make('barcode')->maxLength(50),
                        Forms\Components\Textarea::make('description')->maxLength(50),
                    ])->columns(2),

                        Forms\Components\Section::make('Stock Rules & Ownership')
                    ->schema([
                        Forms\Components\Toggle::make('is_sellable')
                            ->required()
                            ->helperText('Aktifkan jika stok di lokasi ini boleh dijual (AFS).'),

                        // === PENAMBAHAN KONSEP KONSINYASI ===
                        Forms\Components\Select::make('ownership_type')
                            ->label('Stock Ownership')
                            ->options([
                                'owned' => 'Owned Stock (Milik Perusahaan)',
                                'consignment' => 'Consignment (Titipan Supplier)',
                            ])
                            ->required()->default('owned')->live(), // 'live' agar field supplier muncul

                        Forms\Components\Select::make('supplier_id')
                            ->label('Supplier (Pemilik Stok Konsinyasi)')
                            ->relationship('supplier', 'name')
                            ->searchable()->preload()
                            // Hanya muncul jika tipe ownership adalah 'consignment'
                            ->visible(fn (Forms\Get $get) => $get('ownership_type') === 'consignment')
                            ->required(fn (Get $get) => $get('ownership_type') === 'consignment' && $get('type' !== 'AREA')),

                            Forms\Components\Toggle::make('is_default_staging')
                            ->label('Default Staging Location?')
                            ->helperText('Aktifkan jika ini lokasi STAGING utama di plant/gudang ini.')
                            // Hanya visible jika Zone = STG
                            ->visible(function(Get $get): bool {
                                 // Perlu query Zone berdasarkan ID yang dipilih
                                 $zone = Zone::find($get('zone_id'));
                                 return $zone?->code === 'STG';
                            }),

                        Forms\Components\Toggle::make('is_default_receiving')
                            ->label('Default Receiving/Main Location?')
                            ->helperText('Aktifkan jika ini lokasi RECEIVING/MAIN utama di plant/gudang/outlet ini.')
                             // Hanya visible jika Zone = RCV atau MAIN
                            ->visible(function(Get $get): bool {
                                 $zone = Zone::find($get('zone_id'));
                                 return in_array($zone?->code, ['RCV', 'MAIN', 'RET']);
                            }),
                        // ===================================
                    ])->columns(2),

                Forms\Components\Section::make('Capacity & WMS')
                        ->schema([
                            Forms\Components\TextInput::make('max_pallets')
                                ->label('Max Handling Units (Pallets)')
                                ->numeric()
                                ->default(1)
                                ->helperText('Berapa banyak pallet/box yang muat di bin ini? (0 = Unlimited)'),

                            Forms\Components\TextInput::make('current_pallets')
                                ->label('Current Occupancy')
                                ->numeric()
                                ->disabled() // Read only, diupdate oleh sistem
                                ->dehydrated(false),

                            Forms\Components\TextInput::make('picking_sequence')
                                ->label('Picking Path Sequence')
                                ->numeric()
                                ->default(0)
                                ->helperText('Urutan jalan picker. Angka lebih kecil dilewati duluan.'),

                            // Pastikan field is_sellable ada (Untuk menandai ini Bin Rak)
                            Forms\Components\Toggle::make('is_sellable')
                                ->label('Storage Bin')
                                ->helperText('Aktifkan jika ini adalah rak penyimpanan (bukan lorong/jalan).'),
                            Forms\Components\Toggle::make('status')
                     ->label('Active')
                     ->default(true)
                     ->columnSpanFull(),
                        ])->columns(2)->collapsed(),
            ]);

    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('locatable.name')->label('Parent (WH/Outlet)'),

                // === KOLOM BARU UNTUK KONSINYASI ===
                Tables\Columns\TextColumn::make('ownership_type')->badge()
                    ->label('Ownership')
                    ->formatStateUsing(fn (string $state): string => ucwords($state))
                    ->color(fn (string $state): string => $state === 'owned' ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('supplier.name')->label('Supplier/Owner'),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\IconColumn::make('is_sellable')->label('Sellable')->boolean(),
                Tables\Columns\IconColumn::make('is_default_staging')->boolean()->label('Def. STG?'),
                Tables\Columns\IconColumn::make('is_default_receiving')->boolean()->label('Def. RCV/Main?'),
                Tables\Columns\ToggleColumn::make('status'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('warehouse')
                    ->label('Warehouse')
                    ->options(Warehouse::pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) =>
                        $data['value'] ? $query->where('locatable_type', Warehouse::class)->where('locatable_id', $data['value']) : null
                    ),
                Tables\Filters\SelectFilter::make('outlet')
                    ->label('Outlet')
                    ->options(Outlet::pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) =>
                        $data['value'] ? $query->where('locatable_type', Outlet::class)->where('locatable_id', $data['value']) : null
                    ),
                Tables\Filters\SelectFilter::make('ownership_type')
                    ->options(['owned' => 'Owned', 'consignment' => 'Consignment']),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'AREA' => 'Area', 'RACK' => 'Rack',
                        'BIN' => 'Bin', 'PALLET' => 'Pallet',
                    ]),
                // Filter berdasarkan Zone
                Tables\Filters\SelectFilter::make('zone_id')
                    ->label('Zone')
                    ->relationship('zone', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            RelationManagers\ChildrenRelationManager::class,
            RelationManagers\InventoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'edit' => Pages\EditLocation::route('/{record}/edit'),
        ];
    }
}
