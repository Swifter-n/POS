<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BrandResource\Pages;
use App\Filament\Resources\BrandResource\RelationManagers;
use App\Models\Brand;
use App\Models\Outlet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    //protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Brands';
    protected static ?string $navigationGroup = 'Product Management';


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
                //
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Brand Name')
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('logo')
                    ->image()
                    ->label('Logo Brand')
                    ->columnSpanFull()
                    ->required(),
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
                    ->label('Name Brand'),
                Tables\Columns\ImageColumn::make('logo')
                    ->label('Logo Brand')
                    ->circular(),
                Tables\Columns\ToggleColumn::make('status')
                    ->onIcon('heroicon-o-check-circle')
                    ->offIcon('heroicon-o-x-circle')
                    ->sortable()
                    ->label('Status'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Created Date')

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
            'index' => Pages\ListBrands::route('/'),
            'create' => Pages\CreateBrand::route('/create'),
            'edit' => Pages\EditBrand::route('/{record}/edit'),
        ];
    }
}
