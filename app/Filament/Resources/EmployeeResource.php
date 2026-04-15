<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Models\Outlet;
use App\Models\Plant;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class EmployeeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Employees';
    protected static ?string $navigationGroup = 'Business Management';




    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Fieldset::make('Employee Details')
                    ->schema([
                        Forms\Components\TextInput::make('nik')
                            ->required()
                            ->maxLength(10)
                            ->label('NIK Employee'),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->label('Name Employee'),
                        Forms\Components\TextInput::make('email')
                            ->required()
                            ->email()
                            ->maxLength(100)
                            ->label('Email Employee')
                            ->unique(ignoreRecord: true), // Tambahkan unique
                        Forms\Components\TextInput::make('phone')
                            ->required()
                            ->maxLength(15)
                            ->numeric()
                            ->label('Phone Employee'),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->label('Password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255),
                        Forms\Components\Toggle::make('status') // Tambahkan status
                             ->label('Active Status')
                             ->default(true)
                             ->required(),

                    ])->columns(2), // Ubah ke 2 kolom

                Fieldset::make('Assignment & Hierarchy')
                    ->schema([
                        Forms\Components\Select::make('position_id')
                        ->relationship(
                            name: 'position',
                            titleAttribute: 'name',
                            // Filter jabatan hanya milik bisnis ini
                            modifyQueryUsing: fn (Builder $query) => $query->where('business_id', Auth::user()->business_id)
                        )
                        ->searchable()->preload()->required(),

                        Forms\Components\Select::make('supervisor_id')
                            ->relationship('supervisor', 'name')
                            ->label('Reports To')
                            ->searchable()->preload(),

                        // ==========================================================
                        // --- PERBAIKAN: Tambahkan Plant ID ---
                        // ==========================================================
                        Forms\Components\Select::make('plant_id')
                            ->label('Assigned Plant / DC')
                            ->options(fn() => Plant::where('business_id', Auth::user()->business_id)
                                        ->where('status', true)->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get) => $get('locationable_type') === Warehouse::class)
                            ->required(fn (Get $get) => $get('locationable_type') === Warehouse::class)
                            ->helperText('Pilih Plant utama tempat staf ini ditugaskan.'),
                        // ==========================================================

                        Forms\Components\Select::make('locationable_type')
                            ->label('Location Type')
                            ->options([
                                Warehouse::class => 'Warehouse',
                                Outlet::class => 'Outlet',
                            ])
                            ->live()
                            ->required(),

                        // ==========================================================
                        // --- PERBAIKAN: Filter locationable_id berdasarkan Plant & Tipe ---
                        // ==========================================================
                        Forms\Components\Select::make('locationable_id')
                            ->label('Location Name')
                            ->options(function (Get $get) {
                                $type = $get('locationable_type');
                                if (!$type) return [];

                                $query = $type::query()->where('business_id', Auth::user()->business_id);

                                if ($type === Warehouse::class) {
                                    $plantId = $get('plant_id');
                                    // Harus pilih Plant dulu jika tipe Warehouse
                                    if (!$plantId) return [];
                                    $query->where('plant_id', $plantId);
                                } elseif ($type === Outlet::class) {
                                    // Outlet tidak difilter berdasarkan Plant
                                } else {
                                    return []; 
                                }

                                return $query->where('status', true)->pluck('name', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText(fn (Get $get) => $get('locationable_type') === Warehouse::class ? 'Lokasi akan difilter berdasarkan Plant yang dipilih.' : 'Pilih Outlet tempat staf ditugaskan.'),
                        // ==========================================================

                        Forms\Components\Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->required(),

                    ])->columns(2), // Ubah ke 2 kolom
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('email')->searchable(),
            Tables\Columns\TextColumn::make('role.name')->badge(),
            Tables\Columns\TextColumn::make('position.name'),

            // --- KOLOM LOKASI DINAMIS ---
            Tables\Columns\TextColumn::make('locationable.name')
                ->label('Assigned Location')
                ->formatStateUsing(function ($state, Model $record) {
                    // Tampilkan nama lokasi dan tambahkan label tipenya
                    $type = $record->locationable_type;
                    $typeName = (new \ReflectionClass($type))->getShortName(); // Ambil nama class (Outlet/Warehouse)
                    return "{$state} ({$typeName})";
                })
                ->searchable(),
            // --- AKHIR KOLOM ---

            Tables\Columns\IconColumn::make('status')->boolean(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                ->hidden(fn () => Auth::user()->role_id === '1'),
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
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
