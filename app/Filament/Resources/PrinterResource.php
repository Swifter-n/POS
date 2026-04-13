<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PrinterResource\Pages;
use App\Filament\Resources\PrinterResource\RelationManagers;
use App\Models\Outlet;
use App\Models\Printer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PrinterResource extends Resource
{
    protected static ?string $model = Printer::class;

    protected static ?string $navigationIcon = 'heroicon-o-printer'; // Icon yg lebih relevan

    protected static ?string $navigationGroup = 'Settings'; // Grup yg lebih relevan

    /**
     * Filter multi-tenancy:
     * Manajer/User hanya bisa melihat printer
     * yang ada di outlet-outlet dalam bisnis mereka.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user(); // <-- PERBAIKAN (Auth::user() -> auth()->user())
        if ($user->hasRole('Owner')) { // Asumsi Anda punya role 'Owner'
             // Owner melihat semua printer di dalam bisnisnya
            return parent::getEloquentQuery()
                ->whereHas('outlet', function ($query) use ($user) {
                    $query->where('business_id', $user->business_id);
                });
        }

        // User biasa (misal: Manajer Outlet)
        // hanya melihat printer di outlet tempat dia ditugaskan
        if ($user->locationable_type === Outlet::class) {
            return parent::getEloquentQuery()->where('outlet_id', $user->locationable_id);
        }

        // Fallback: Super Admin (jika business_id == null) bisa lihat semua
        if (is_null($user->business_id)) {
            return parent::getEloquentQuery();
        }

        // Default: Sembunyikan jika tidak ada yg cocok
        return parent::getEloquentQuery()->whereRaw('0 = 1');
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Printer Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1)
                            ->helperText('Nama printer, misal: "Kasir Depan" atau "Dapur"'),

                        // Relasi ke Outlet
                        Forms\Components\Select::make('outlet_id')
                            ->relationship('outlet', 'name', function (Builder $query) {
                                // Filter outlet berdasarkan business_id user yg login
                                $user = Auth::user(); // <-- PERBAIKAN (Auth::user() -> auth()->user())
                                if ($user->business_id) {
                                     $query->where('business_id', $user->business_id);
                                }
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpan(1),

                        // Form reaktif
                        Forms\Components\Select::make('connection_type')
                            ->options([
                                'ip' => 'IP / Network',
                                'bluetooth' => 'Bluetooth',
                                'usb' => 'USB',
                            ])
                            ->required()
                            ->live() // <-- Buat form ini reaktif
                            ->columnSpan(1),

                        // Lebar Kertas
                        Forms\Components\Select::make('paper_width')
                            ->options([
                                58 => '58 mm',
                                80 => '80 mm',
                            ])
                            ->required()
                            ->columnSpan(1),

                        // --- Input Kondisional berdasarkan 'connection_type' ---

                        Forms\Components\TextInput::make('ip_address')
                            ->label('IP Address')
                            ->ip() // Validasi IP address
                            ->placeholder('192.168.1.100')
                            ->requiredIf('connection_type', 'ip')
                            // Hanya tampil jika connection_type == 'ip'
                            ->visible(fn (Get $get) => $get('connection_type') === 'ip') // <-- PERUBAHAN 2: Gunakan alias 'FormsGet'
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('mac_address')
                            ->label('MAC Address')
                            ->placeholder('00:1A:2B:3C:4D:5E')
                            ->requiredIf('connection_type', 'bluetooth')
                            // Hanya tampil jika connection_type == 'bluetooth'
                            ->visible(fn (Get $get) => $get('connection_type') === 'bluetooth') // <-- PERUBAHAN 3: Gunakan alias 'FormsGet'
                            ->columnSpan(2),

                        // --- Akhir Input Kondisional ---

                        Forms\Components\Toggle::make('default')
                            ->required()
                            ->columnSpanFull()
                            ->helperText('Set sebagai printer utama/default untuk outlet ini. (Misal: printer kasir untuk invoice).'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('outlet.name') // Tampilkan nama outlet
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('connection_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ip' => 'success',
                        'bluetooth' => 'primary',
                        'usb' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('Address')
                    ->formatStateUsing(fn ($record) => $record->connection_type === 'ip' ? $record->ip_address : $record->mac_address)
                    ->searchable(['ip_address', 'mac_address']),
                Tables\Columns\TextColumn::make('paper_width')
                    ->formatStateUsing(fn (string $state) => "{$state} mm")
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('default') // Bisa ganti default lgsg dari tabel
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('outlet_id')
                    ->label('Outlet')
                    ->relationship('outlet', 'name', function (Builder $query) {
                        // Filter outlet berdasarkan business_id user yg login
                        $user = Auth::user(); // <-- PERBAIKAN (Auth::user() -> auth()->user())
                        if ($user->business_id) {
                                $query->where('business_id', $user->business_id);
                        }
                    })
                    ->searchable()
                    ->preload(),
                Tables\Filters\TrashedFilter::make(), // Filter untuk soft delete
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrinters::route('/'),
            'create' => Pages\CreatePrinter::route('/create'),
            'edit' => Pages\EditPrinter::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQueryBuilder(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
