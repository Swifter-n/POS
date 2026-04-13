<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BusinessSettingResource\Pages;
use App\Filament\Resources\BusinessSettingResource\RelationManagers;
use App\Models\BusinessSetting;
use App\Models\Outlet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class BusinessSettingResource extends Resource
{
    protected static ?string $model = BusinessSetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-8-tooth';
    protected static ?string $navigationLabel = 'Business Settings';
    protected static ?string $navigationGroup = 'Business Management';

    public static function getEloquentQuery(): Builder
{
    $user = Auth::user();

    // 1. Principal (Super Admin) - business_id-nya NULL, bisa melihat semua produk.
    if (is_null($user->business_id)) {
        return parent::getEloquentQuery();
    }

    // Ambil query dasar untuk kita modifikasi
    $query = parent::getEloquentQuery();

    // 2. Staf Outlet - punya outlet_id, hanya bisa melihat produk dari bisnis tempat outlet-nya berada.
    if (!is_null($user->outlet_id)) {
        // Cari dulu outlet tempat user ini bekerja
        $outlet = Outlet::find($user->outlet_id);

        // Filter produk berdasarkan business_id dari outlet tersebut
        // Tanda tanya (?) adalah null-safe operator, untuk mencegah error jika outlet tidak ditemukan
        return $query->where('business_id', $outlet?->business_id);
    }

    // 3. Pemilik Bisnis - punya business_id tapi tidak punya outlet_id.
    // Bisa melihat semua produk di dalam bisnisnya.
    return $query->where('business_id', $user->business_id);
}

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Business Setting Name')
                    ->columnSpanFull(),
                Forms\Components\Select::make('type')
                    ->label('Type Adjustment')
                    ->options([
                        'tax' => 'Tax',
                        'discount' => 'Discount',
                        'welcome_voucher_rule' => 'Welcome Voucher',
                        'welcome_voucher_days' => 'Welcome Voucher Validity Days',
                        'birthday_voucher_rule' => 'Birthday Voucher',
                        'birthday_voucher_days' => 'Birthday Voucher Validity Days',
                        'winback_voucher_rule' => 'WinBack Voucher Rule',
                        'winback_threshold_days' => 'WinBack Threshold Days',
                    ])
                    ->live()
                    ->preload()
                    ->columnspanFull(),
                Forms\Components\Select::make('charge_type')
                    ->label('Type Value')
                    ->options([
                        'percent' => 'Percent',
                        'fixed' => 'Fixed',
                    ])
                    ->live()
                    ->preload()
                    ->columnspanFull(),
                Forms\Components\TextInput::make('value')
                    ->required()
                    ->numeric()
                    ->label('Value')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('status')
                    ->label('Status')
                    ->columnSpanFull()
                    ->live(),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->sortable()
                    ->searchable()
                    ->label('Name'),
                Tables\Columns\TextColumn::make('type')
                    ->sortable()
                    ->searchable()
                    ->label('Type Adjustment'),
                Tables\Columns\TextColumn::make('charge_type')
                    ->sortable()
                    ->searchable()
                    ->label('Type Value'),
                Tables\Columns\TextColumn::make('value')
                    ->sortable()
                    ->label('Value'),
                Tables\Columns\ToggleColumn::make('status')
                    ->onIcon('heroicon-o-check-circle')
                    ->offIcon('heroicon-o-x-circle')
                    ->sortable()
                    ->label('Status'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d-M-Y')
                    ->label('Created Date')
                    ->sortable(),
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
            'index' => Pages\ListBusinessSettings::route('/'),
            'create' => Pages\CreateBusinessSetting::route('/create'),
            'edit' => Pages\EditBusinessSetting::route('/{record}/edit'),
        ];
    }
}
