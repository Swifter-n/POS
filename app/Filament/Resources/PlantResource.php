<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlantResource\Pages;
use App\Filament\Resources\PlantResource\RelationManagers;
use App\Models\Area;
use App\Models\District;
use App\Models\Plant;
use App\Models\Province;
use App\Models\Regency;
use App\Models\Village;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class PlantResource extends Resource
{
    protected static ?string $model = Plant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 2; // Urutan setelah Business?

    // Filter data berdasarkan Business ID user
    public static function getEloquentQuery(): Builder
    {
        // Pastikan user terautentikasi sebelum mengakses business_id
        if (Auth::check()) {
            return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
        }
        // Jika tidak terautentikasi, jangan tampilkan data
        return parent::getEloquentQuery()->whereRaw('0 = 1');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('area_id')
                ->label('Area')
                ->options(Area::pluck('name', 'id')) // Gunakan ->options()
                ->searchable()
                ->preload()
                ->required(),
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true) // Pastikan kode unik
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('type')
                    ->label('Plant Type')
                    ->options([
                        'MANUFACTURING' => 'Manufacturing Plant',
                        'DISTRIBUTION' => 'Distribution Center (DC)',
                        'OTHER' => 'Other',
                    ])
                    ->required()
                    ->helperText('Pilih tipe utama dari lokasi plant ini.'),

                    Forms\Components\Select::make('province_id')
                    ->searchable()
                    ->preload()
                    ->options(Province::pluck('name', 'id'))
                    ->afterStateUpdated(fn (callable $set) => $set('regency_id', null))
                    ->live(), // Pemicu untuk dropdown di bawahnya

                Forms\Components\Select::make('regency_id')
                    ->options(fn (Get $get) => Regency::where('province_id', $get('province_id'))->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->afterStateUpdated(fn (callable $set) => $set('district_id', null))
                    ->live(), // Pemicu untuk dropdown di bawahnya

                Forms\Components\Select::make('district_id')
                    ->options(fn (Get $get) => District::where('regency_id', $get('regency_id'))->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->afterStateUpdated(fn (callable $set) => $set('village_id', null))
                    ->live(), // Pemicu untuk dropdown di bawahnya

                Forms\Components\Select::make('village_id')
                    ->options(fn (Get $get) => Village::where('district_id', $get('district_id'))->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\Textarea::make('address')
                    ->label('Detail Alamat (Jalan, Nomor, dll)')->columnSpanFull(),

                Forms\Components\Toggle::make('status')
                    ->required()
                    ->default(true),

                // Business ID akan diisi otomatis
                Forms\Components\Hidden::make('business_id')
                     ->default(fn() => Auth::check() ? Auth::user()->business_id : null), // Handle jika user tidak login
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type') // Tampilkan tipe Plant
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucwords(strtolower(str_replace('_', ' ', $state))) : '-') // Handle null state
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('status'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Tambahkan Relation Manager untuk Warehouses di sini
            RelationManagers\WarehousesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlants::route('/'),
            'create' => Pages\CreatePlant::route('/create'),
            'edit' => Pages\EditPlant::route('/{record}/edit'),
            //'view' => Pages\ViewPlant::route('/{record}'), // Tambahkan halaman View
        ];
    }
}
