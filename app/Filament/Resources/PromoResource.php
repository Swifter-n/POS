<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PromoResource\Pages;
use App\Filament\Resources\PromoResource\RelationManagers;
use App\Models\Outlet;
use App\Models\Promo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class PromoResource extends Resource
{
    protected static ?string $model = Promo::class;

    protected static ?string $navigationIcon = 'heroicon-o-percent-badge';
    protected static ?string $navigationLabel = 'Promos';
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
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(255)
                    ->label('Promo Code'),
                Forms\Components\Textarea::make('description')
                            ->required()
                            ->maxLength(1000)
                            ->label('Promo Description'),
                Forms\Components\TextInput::make('discount_amount')
                    ->required()
                    ->numeric()
                    ->maxLength(10)
                    ->minValue(0)
                    ->prefix('IDR ')
                    ->label('Discount Amount'),
                Forms\Components\DatePicker::make('activated_at')
                    ->prefix('Starts')
                    ->suffix('Promo'),
                Forms\Components\DatePicker::make('expired_at')
                    ->prefix('Ends')
                    ->suffix('Promo'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->sortable()
                    ->searchable()
                    ->label('Promo Code'),
                Tables\Columns\TextColumn::make('description')
                    ->label('Promo Description'),
                Tables\Columns\TextColumn::make('discount_amount')
                    ->sortable()
                    ->label('Discount Amount'),
                Tables\Columns\TextColumn::make('activated_at')
                    ->sortable()
                    ->dateTime()
                    ->searchable()
                    ->label('Activated At'),
                Tables\Columns\TextColumn::make('expired_at')
                    ->sortable()
                    ->dateTime()
                    ->searchable()
                    ->label('Expired At'),
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
            'index' => Pages\ListPromos::route('/'),
            'create' => Pages\CreatePromo::route('/create'),
            'edit' => Pages\EditPromo::route('/{record}/edit'),
        ];
    }
}
