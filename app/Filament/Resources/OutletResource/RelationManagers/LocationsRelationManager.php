<?php

namespace App\Filament\Resources\OutletResource\RelationManagers;

use App\Models\Zone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Location Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(255),
                        Forms\Components\Select::make('parent_id')
                            ->label('Parent Location (in this Outlet)') // <-- Label diubah
                            ->options(fn (RelationManager $livewire) => $livewire->ownerRecord->locations()->pluck('name', 'id'))
                            ->searchable(),
                        Forms\Components\TextInput::make('code')->maxLength(50),
                        Forms\Components\Select::make('type')
                            ->options([
                                'AREA' => 'Area', 'RACK' => 'Rack',
                                'BIN' => 'Bin', 'PALLET' => 'Pallet',
                            ])->required()->live(),
                        Forms\Components\Select::make('zone_id')->relationship('zone', 'name')->searchable()->preload()->live(),
                        Forms\Components\TextInput::make('barcode')->maxLength(50),
                        Forms\Components\Textarea::make('description')->maxLength(50), // <-- Dihapus dari file asli, tapi sepertinya berguna
                    ])->columns(2),

                Forms\Components\Section::make('Stock Rules & Ownership')
                    ->schema([
                        Forms\Components\Toggle::make('is_sellable')->required(),
                        Forms\Components\Select::make('ownership_type')
                            ->label('Stock Ownership')
                            ->options(['owned' => 'Owned Stock', 'consignment' => 'Consignment'])
                            ->required()->default('owned')->live(),
                        Forms\Components\Select::make('supplier_id')
                            ->label('Supplier (Consignment Owner)')
                            ->relationship('supplier', 'name')
                            ->searchable()->preload()
                            ->visible(fn (Get $get) => $get('ownership_type') === 'consignment')
                            ->required(fn (Get $get) => $get('ownership_type') === 'consignment' && $get('type') !== 'AREA'), // <-- Perbaikan $get('type' !== 'AREA')

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
                    ])->columns(2),

                Forms\Components\Section::make('Capacity Details')
                    ->schema([
                        Forms\Components\TextInput::make('max_capacity')->numeric()->label('Max Capacity'),
                        Forms\Components\Select::make('capacity_unit')
                            ->label('Capacity Unit')
                            ->options(['KG' => 'KG', 'CBM' => 'CBM', 'PALLET' => 'Pallet', 'BIN' => 'Bin', 'UNIT' => 'Unit']),
                        Forms\Components\Toggle::make('status')
                             ->label('Active')
                             ->default(true)
                             ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
               Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('ownership_type')->badge()
                    ->color(fn (string $state): string => $state === 'owned' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('supplier.name')->label('Consignment Owner'),
                Tables\Columns\IconColumn::make('is_sellable')->boolean(),
                Tables\Columns\IconColumn::make('is_default_staging')->boolean()->label('Def. STG?'),
                Tables\Columns\IconColumn::make('is_default_receiving')->boolean()->label('Def. RCV/Main?'),
                Tables\Columns\ToggleColumn::make('status'),
            ])
            ->filters([
                // Filter ini tidak lagi relevan jika RM ini *hanya* untuk Outlet
                // Tables\Filters\SelectFilter::make('warehouse')
                //     ...
                // Tables\Filters\SelectFilter::make('outlet')
                //     ...
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
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
