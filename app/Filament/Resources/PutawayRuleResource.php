<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PutawayRuleResource\Pages;
use App\Filament\Resources\PutawayRuleResource\RelationManagers;
use App\Models\Category;
use App\Models\PutawayRule;
use App\Models\Zone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class PutawayRuleResource extends Resource
{
    protected static ?string $model = PutawayRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Putaway Rules (Strategies)';
    protected static ?int $navigationSort = 9;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Matching Criteria (Input)')
                    ->description('Tentukan kriteria produk. Field yang dikosongkan akan dianggap "Any" (Berlaku untuk semua).')
                    ->schema([
                        Forms\Components\Select::make('activity')
                        ->label('Scope / Activity')
                        ->options([
                            'putaway' => 'Inbound (Putaway Strategy)',
                            'picking' => 'Outbound (Picking Strategy)',
                            'both'    => 'Both (General Rule)',
                        ])
                        ->default('putaway')
                        ->required()
                        ->columnSpanFull()
                        ->helperText('Pilih "Picking" jika ingin mengatur urutan pencarian stok saat barang keluar.'),
                        // CRITERIA 1: STORAGE CONDITION (Fisik/Velocity)
                        Forms\Components\Select::make('criteria_storage_condition')
                            ->label('Storage Condition')
                            ->options([
                                'FAST_MOVING' => 'Fast Moving',
                                'SLOW_MOVING' => 'Slow Moving',
                                'COLD' => 'Cold Storage',
                                'DRY' => 'Dry Storage',
                                'FRAGILE' => 'Fragile / Pecah Belah',
                                'HAZARDOUS' => 'B3 / Hazardous',
                                'BULK' => 'Bulk Item',
                            ])
                            ->searchable()
                            ->placeholder('Any Condition')
                            ->helperText('Mencocokkan kolom "Storage Condition" di data Produk.'),

                        // CRITERIA 2: PRODUCT TYPE (Bisnis)
                        Forms\Components\Select::make('criteria_product_type')
                            ->label('Business Type')
                            ->options([
                                'finished_good' => 'Finished Good',
                                'raw_material' => 'Raw Material',
                                'merchandise' => 'Merchandise',
                                'asset' => 'Asset',
                                'packaging' => 'Packaging',
                            ])
                            ->searchable()
                            ->placeholder('Any Type')
                            ->helperText('Mencocokkan kolom "Product Type" di data Produk.'),

                        // CRITERIA 3: CATEGORY
                        Forms\Components\Select::make('category_id')
                            ->label('Product Category')
                            ->relationship('category', 'name', function (Builder $query) {
                                return $query->where('business_id', Auth::user()->business_id);
                            })
                            ->searchable()
                            ->preload()
                            ->placeholder('Any Category')
                            ->helperText('Berlaku spesifik untuk kategori tertentu.'),
                    ])->columns(3),

                Forms\Components\Section::make('Target Strategy (Output)')
                    ->schema([
                        // TARGET ZONE
                        Forms\Components\Select::make('target_zone_id')
                            ->label('Target Zone')
                            ->relationship('targetZone', 'name', function (Builder $query) {
                                return $query->where('business_id', Auth::user()->business_id);
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('Jika kriteria cocok, arahkan barang ke Zone ini.'),

                        // PRIORITY
                        Forms\Components\TextInput::make('priority')
                            ->label('Priority Level')
                            ->numeric()
                            ->default(10)
                            ->required()
                            ->minValue(1)
                            ->helperText('Angka KECIL = Prioritas TINGGI. (1 dieksekusi sebelum 10).'),

                        // STRATEGY
                        Forms\Components\Select::make('strategy')
                            ->label('Placement Logic')
                            ->options([
                                'empty_bin' => 'Empty Bin (Cari bin kosong)',
                                'mixed' => 'Mixed Storage (Boleh tumpuk/campur)',
                                // 'addition' => 'Add to Existing Stock',
                            ])
                            ->default('empty_bin')
                            ->required(),

                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Kolom Prioritas (Kiri agar mudah dibaca urutannya)
                Tables\Columns\TextColumn::make('priority')
                    ->label('Pri')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state): string => $state <= 5 ? 'danger' : 'gray'),

                // Kolom Kriteria (Logic Visual)
                Tables\Columns\TextColumn::make('criteria_storage_condition')
                    ->label('Condition')
                    ->badge()
                    ->color('info')
                    ->placeholder('Any'),

                Tables\Columns\TextColumn::make('criteria_product_type')
                    ->label('Biz Type')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state)))
                    ->placeholder('Any'),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->placeholder('Any'),

                // Panah Visual
                Tables\Columns\TextColumn::make('arrow')
                    ->label('')
                    ->default('➔')
                    ->alignCenter(),

                // Kolom Target
                Tables\Columns\TextColumn::make('targetZone.name')
                    ->label('Target Zone')
                    ->sortable()
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('strategy')
                    ->label('Logic')
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                Tables\Columns\TextColumn::make('activity')
                ->badge()
                ->colors([
                    'success' => 'putaway',
                    'warning' => 'picking',
                    'primary' => 'both',
                ])
                ->formatStateUsing(fn (string $state): string => strtoupper($state)),
            ])
            ->defaultSort('priority', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('target_zone_id')
                    ->label('Target Zone')
                    ->relationship('targetZone', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPutawayRules::route('/'),
            'create' => Pages\CreatePutawayRule::route('/create'),
            'edit' => Pages\EditPutawayRule::route('/{record}/edit'),
        ];
    }

}
