<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriorityLevelResource\Pages;
use App\Filament\Resources\PriorityLevelResource\RelationManagers;
use App\Models\PriorityLevel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class PriorityLevelResource extends Resource
{
    protected static ?string $model = PriorityLevel::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';
    protected static ?string $navigationGroup = 'Master Data';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // -------------------------------------------------------
                // 1. Identitas Level (Nama & Urutan)
                // -------------------------------------------------------
                Forms\Components\Section::make('Level Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Level Name')
                            ->placeholder('Contoh: Silver, Gold, VIP'),

                        Forms\Components\Select::make('scope')
                            ->label('Applies To')
                            ->options([
                                'sales_order' => 'Sales Order (B2B Customer)',
                                'pos' => 'POS (Member Loyalty Tier)',
                            ])
                            ->required()
                            ->default('sales_order')
                            ->live(), // Agar form di bawahnya reaktif

                        Forms\Components\TextInput::make('level_order')
                            ->numeric()
                            ->required()
                            ->label('Priority Order')
                            ->helperText('1 = Terendah. Semakin tinggi angka, semakin tinggi prioritasnya.'),
                    ])->columns(3),

                // -------------------------------------------------------
                // 2. Syarat B2B (Sales Order)
                // -------------------------------------------------------
                Forms\Components\Section::make('B2B Criteria')
                    ->description('Kriteria kenaikan level untuk Customer B2B.')
                    ->schema([
                        Forms\Components\Select::make('price_list_id')
                            ->relationship('priceList', 'name')
                            ->label('Associated Price List')
                            ->helperText('Customer di level ini akan otomatis menggunakan daftar harga ini.'),

                        Forms\Components\TextInput::make('min_orders')
                            ->numeric()
                            ->default(0)
                            ->label('Minimum Order Count'),

                        Forms\Components\TextInput::make('min_spend')
                            ->numeric()
                            ->default(0)
                            ->label('Minimum Total Spend')
                            ->prefix('Rp'),
                    ])
                    ->columns(3)
                    // Sembunyikan jika scope bukan 'sales_order'
                    ->hidden(fn (Get $get) => $get('scope') !== 'sales_order'),

                // -------------------------------------------------------
                // 3. Syarat POS (Membership)
                // -------------------------------------------------------
                Forms\Components\Section::make('Membership Criteria')
                    ->description('Syarat Poin untuk Member POS naik ke tier ini.')
                    ->schema([
                        Forms\Components\TextInput::make('min_points')
                            ->numeric()
                            ->default(0)
                            ->label('Minimum Points Required')
                            ->suffix('Pts')
                            ->helperText('Contoh: 1000 Poin untuk naik ke Gold.'),
                    ])
                    // Sembunyikan jika scope bukan 'pos'
                    ->hidden(fn (Get $get) => $get('scope') !== 'pos'),

                Forms\Components\Section::make('Additional Info')
                    ->schema([
                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->columnSpanFull(),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('scope')
                    ->badge()
                    ->colors([
                        'primary' => 'sales_order',
                        'success' => 'pos',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sales_order' => 'B2B Customer',
                        'pos' => 'POS Member',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('level_order')
                    ->label('Order')
                    ->sortable()
                    ->alignCenter(),

                // Kolom Dinamis: Tampilkan info relevan
                Tables\Columns\TextColumn::make('requirements')
                    ->label('Requirements')
                    ->getStateUsing(function ($record) {
                        if ($record->scope === 'pos') {
                            return "Min. {$record->min_points} Pts";
                        } else {
                            return "Min. Spend: " . number_format($record->min_spend, 0, ',', '.');
                        }
                    }),

                Tables\Columns\TextColumn::make('priceList.name')
                    ->label('Price List (B2B)')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('scope')
                    ->options([
                        'sales_order' => 'B2B Customer',
                        'pos' => 'POS Member',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('level_order', 'asc');
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
            'index' => Pages\ListPriorityLevels::route('/'),
            'create' => Pages\CreatePriorityLevel::route('/create'),
            'edit' => Pages\EditPriorityLevel::route('/{record}/edit'),
        ];
    }
}
