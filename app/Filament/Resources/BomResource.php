<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BomResource\Pages;
use App\Filament\Resources\BomResource\RelationManagers;
use App\Models\Bom;
use App\Traits\HasPermissionChecks;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BomResource extends Resource
{
    use HasPermissionChecks;
    protected static ?string $model = Bom::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 10; // Sesuaikan urutan

    // ==========================================================
    // HELPERS PERMISSION (Sesuai pola Anda)
    // ==========================================================
    private static function userHasPermission(string $permissionName): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        if (self::userHasRole('Owner')) return true;

        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)->where('model_id', $user->id)
            ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.name', $permissionName)
            ->exists();
    }
    private static function userHasRole(string $roleName): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)->where('model_id', $user->id)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', $roleName)
            ->exists();
    }
    // ==========================================================

    public static function canViewAny(): bool
    {
        // Ganti 'view boms' dengan nama permission Anda
        return self::userHasPermission('view boms');
    }
    public static function canCreate(): bool
    {
        return self::userHasPermission('create boms');
    }
    // Override canEdit untuk logika read-only jika perlu
    // public static function canEdit(Model $record): bool
    // {
    //     return self::userHasPermission('edit boms');
    // }

    // Filter BOM berdasarkan business_id
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Bill of Materials (Header)')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2)
                            ->helperText('Nama resep (misal "Resep Espresso Cafe", "Resep Biji Sangrai Pabrik")'),
                        Forms\Components\Select::make('product_id')
                            ->label('Output Product (Finished Good)')
                            ->relationship(
                                name: 'product',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) =>
                                    $query->where('business_id', Auth::user()->business_id)
                                          // Hanya izinkan membuat BOM untuk produk yang kita produksi/jual
                                          ->whereIn('product_type', ['finished_good', 'merchandise'])
                            )
                            ->searchable()->preload()->required()
                             // Pastikan 1 produk jadi hanya punya 1 BOM (sesuai migrasi)
                            ->unique(ignoreRecord: true, table: 'boms')
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('code')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true, table: 'boms'),
                        Forms\Components\Toggle::make('status')
                            ->default(true),
                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull(),
                    ])->columns(4),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('product.name') // Produk JADI (output)
                    ->label('Output Product')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('status')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(), // Tambahkan View
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
            // ==========================================================
            // --- INI ADALAH KUNCINYA ---
            // ==========================================================
            // Menghubungkan ke Relation Manager tempat Anda mengisi
            // 'usage_type' (RAW_MATERIAL vs RAW_MATERIAL_STORE)
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBoms::route('/'),
            'create' => Pages\CreateBom::route('/create'),
            'edit' => Pages\EditBom::route('/{record}/edit'),
            //'view' => Pages\ViewBom::route('/{record}'), // Tambahkan View
        ];
    }
}
