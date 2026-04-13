<?php

namespace App\Filament\Resources\SalesTeamResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';
    protected static ?string $recordTitleAttribute = 'name';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('email'),
                Tables\Columns\TextColumn::make('position.name'),
            ])
            ->headerActions([
    Tables\Actions\AttachAction::make()
        ->form(fn (Tables\Actions\AttachAction $action): array => [
            Forms\Components\Select::make('recordId')
                ->label('Users')
                ->required()

                // --- TAMBAHKAN METHOD INI ---
                ->multiple()
                // ---------------------------

                ->searchable()
                ->options(function () {
                    $users = \App\Models\User::query()
                        ->whereHas('roles', function (Builder $roleQuery) {
                            $roleQuery->whereIn('name', ['Salesman', 'Cashier']);
                        })
                        ->with('position')
                        ->get();

                    return $users->mapWithKeys(function ($user) {
                        return [$user->id => "{$user->name} - {$user->position?->name}"];
                    });
                })
        ]),
])
//              ->headerActions([
//     Tables\Actions\AttachAction::make()
//         ->form(fn (Tables\Actions\AttachAction $action): array => [
//             // --- KITA BUAT SELECT SECARA MANUAL, BUKAN OTOMATIS ---
//             Forms\Components\Select::make('recordId')
//                 ->label('User')
//                 ->required()
//                 ->searchable()
//                 ->preload()
//                 ->options(function () {
//                     // 1. Ambil semua user yang sesuai dengan kriteria
//                     $users = \App\Models\User::query()
//                         ->whereHas('role', function (Builder $roleQuery) {
//                             $roleQuery->whereNotIn('name', ['owner', 'manager', 'head', 'supervisor']);
//                         })
//                         ->with('position') // Eager load relasi 'position' untuk efisiensi
//                         ->get();

//                     // 2. Format hasilnya menjadi array [id => "Nama - Jabatan"]
//                     return $users->mapWithKeys(function ($user) {
//                         return [$user->id => "{$user->name} - {$user->position?->name}"];
//                     });
//                 })
//         ]),
// ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ]);
    }
}
