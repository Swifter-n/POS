<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Models\Channel;
use App\Models\Customer;
use App\Models\District;
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

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';
    protected static ?string $navigationGroup = 'Sales Management';

    public static function getEloquentQuery(): Builder
    {
        // Filter agar setiap bisnis hanya melihat customernya sendiri
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Customer Details')
                    ->schema([
                        Forms\Components\Select::make('area_id')
                            ->relationship('area', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('name')
                            ->required()->maxLength(255),
                        Forms\Components\Select::make('channel_id')
                            ->label('Channel')
                            // Ganti 'relationship()' dengan 'options()'
                            ->options(
                                Channel::where('business_id', Auth::user()->business_id)->pluck('name', 'id')
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('min_sled_days')
                            ->label('Minimum SLED Request (Days)')
                            ->numeric()
                            ->helperText('Isi hanya untuk customer Modern Trade. Contoh: 120 (untuk 120 hari).'),
                        Forms\Components\Select::make('sales_team_id')
                            ->relationship(
                                name: 'salesTeam',
                                titleAttribute: 'name',
                                // Filter tim sales hanya dari bisnis yang sama
                                modifyQueryUsing: fn (Builder $query) => $query->where('business_id', Auth::user()->business_id)
                            )
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('price_list_id')
                        ->relationship('priceList', 'name')
                        ->label('Special Price List')
                        ->helperText('Biarkan kosong untuk menggunakan harga default outlet.'),
                        Forms\Components\Toggle::make('status')->required()->default(true),
                    ])->columns(2),

                    // --- BAGIAN KREDIT & PRIORITAS ---
            Forms\Components\Section::make('Credit & Loyalty')
                ->schema([
                    Forms\Components\TextInput::make('credit_limit')
                        ->label('Credit Limit')->numeric()->prefix('Rp')->default(0),
                    Forms\Components\TextInput::make('current_balance')
                        ->label('Current Balance')->numeric()->prefix('Rp')->readOnly(),
                    Forms\Components\Placeholder::make('priority_level')
                        ->label('Current Priority Level')
                        ->content(function ($record) {
                            // Menampilkan level prioritas secara dinamis
                            return $record?->priorityLevel?->name ?? 'Not Set';
                        }),
                ])->columns(3),

                Forms\Components\Section::make('Sales & Payment Terms')
                ->schema([
                    Forms\Components\Select::make('customer_service_level_id')
                        ->relationship('customerServiceLevel', 'name')
                        ->label('Customer Service Level (CSL)')
                        ->searchable()->preload(),

                    Forms\Components\Select::make('terms_of_payment_id')
                        ->relationship('termsOfPayment', 'name')
                        ->label('Default Terms of Payment (TOP)')
                        ->searchable()->preload(),
                ])->columns(2),

                Forms\Components\Section::make('Contact Information')
                    ->schema([
                        Forms\Components\TextInput::make('contact_person')->maxLength(255),
                        Forms\Components\TextInput::make('email')->email()->maxLength(255),
                        Forms\Components\TextInput::make('phone')->tel()->maxLength(255),
                        Forms\Components\TextArea::make('address')->label('Detail Alamat (Jalan, Nomor, dll'),
                        Forms\Components\Select::make('province_id')
                            ->options(Province::pluck('name', 'id'))
                            ->searchable()
                            ->live(), // Pemicu untuk dropdown di bawahnya

                        Forms\Components\Select::make('regency_id')
                            ->options(fn (Get $get) => Regency::where('province_id', $get('province_id'))->pluck('name', 'id'))
                            ->searchable()
                            ->live(), // Pemicu untuk dropdown di bawahnya

                        Forms\Components\Select::make('district_id')
                            ->options(fn (Get $get) => District::where('regency_id', $get('regency_id'))->pluck('name', 'id'))
                            ->searchable()
                            ->live(), // Pemicu untuk dropdown di bawahnya

                        Forms\Components\Select::make('village_id')
                            ->options(fn (Get $get) => Village::where('district_id', $get('district_id'))->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])->columns(2),


            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('channel.name')->searchable(),
                Tables\Columns\TextColumn::make('salesTeam.name')->searchable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\ToggleColumn::make('status')
                    ->onIcon('heroicon-o-check-circle')
                    ->offIcon('heroicon-o-x-circle')
                    ->sortable()
                    ->label('Status'),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
