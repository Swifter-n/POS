<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SalesTeamResource\Pages;
use App\Filament\Resources\SalesTeamResource\RelationManagers;
use App\Models\SalesTeam;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SalesTeamResource extends Resource
{
    protected static ?string $model = SalesTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Sales Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required(),
                                Forms\Components\Select::make('team_leader_id')
                    ->label('Team Leader')
                    ->options(
                        // Opsi hanya user yang rolenya bukan owner/staff biasa
                        User::whereHas('roles', function (Builder $query) {
                            $query->whereIn('name', ['Supervisor Sales']);
                        })->pluck('name', 'id')
                    )
                    ->searchable()
                    ->preload(),

                Forms\Components\Select::make('areas')
                    ->relationship('areas', 'name') // 'areas' adalah nama method relasi di model SalesTeam
                    ->multiple() // Memungkinkan pemilihan lebih dari satu
                    ->preload()  // Memuat data yang sudah terpilih saat edit
                    ->searchable()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('teamLeader.name')->label('Team Leader'),
            Tables\Columns\TextColumn ::make('areas.name')->badge()
                    ->label('Coverage Areas'),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            RelationManagers\UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesTeams::route('/'),
            'create' => Pages\CreateSalesTeam::route('/create'),
            'edit' => Pages\EditSalesTeam::route('/{record}/edit'),
        ];
    }
}
