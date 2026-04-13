<?php

namespace App\Filament\Resources\PlantResource\RelationManagers;

use App\Filament\Resources\LocationResource;
use App\Models\Area;
use App\Models\District;
use App\Models\Province;
use App\Models\Regency;
use App\Models\Village;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class WarehousesRelationManager extends RelationManager
{
    protected static string $relationship = 'warehouses';

    public function form(Form $form): Form
    {
        // Form ini mirip dengan WarehouseResource, tapi tanpa Plant ID & Business ID
        return $form
             ->schema([
                Select::make('parent_id')
                    ->label('Parent Warehouse (Optional)')
                    ->relationship(
                        name: 'parent',
                        titleAttribute: 'name',
                        // Filter parent hanya dari plant yang sama
                        modifyQueryUsing: fn (Builder $query) => $query->where('plant_id', $this->ownerRecord->id)
                    )
                    ->helperText('Pilih gudang lain di plant ini sebagai induk (jika struktur hierarkis).'),
                TextInput::make('code')->required()->unique(ignoreRecord: true, table: Warehouse::class), // Pastikan unique di tabel warehouse
                TextInput::make('name')->required(),
                Select::make('type') // Field Tipe Gudang (PENTING)
                    ->options([
                        'RAW_MATERIAL' => 'Raw Material',
                        'FINISHED_GOOD' => 'Finished Good',
                        'DISTRIBUTION' => 'Distribution',
                        'COLD_STORAGE' => 'Cold Storage',
                        'MAIN' => 'Main/Central',
                        'OTHER' => 'Other',
                    ])
                    ->required(),
                Toggle::make('is_main_warehouse')
                    ->helperText('Aktifkan jika ini adalah gudang pusat di dalam plant.'),
                Toggle::make('status')->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('type') // Tampilkan tipe
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucwords(strtolower(str_replace('_', ' ', $state))) : '-')
                    ->sortable(),
                Tables\Columns\TextColumn::make('area.name'),
                Tables\Columns\IconColumn::make('is_main_warehouse')->boolean(),
                Tables\Columns\ToggleColumn::make('status'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'RAW_MATERIAL' => 'Raw Material',
                        'FINISHED_GOOD' => 'Finished Good',
                        'DISTRIBUTION' => 'Distribution',
                        'COLD_STORAGE' => 'Cold Storage',
                        'MAIN' => 'Main/Central',
                        'OTHER' => 'Other',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('manage_locations')
                    ->label('Locations')
                    ->icon('heroicon-o-map-pin')
                    ->color('gray')
                    ->url(fn (Warehouse $record): string => LocationResource::getUrl('index', ['tableFilters[warehouse][value]' => $record->id])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
