<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages;
use App\Filament\Resources\MemberResource\RelationManagers;
use App\Models\Member;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Loyalty Program';
    protected static ?string $navigationLabel = 'Members';

    public static function getEloquentQuery(): Builder
    {
        // Filter hanya member milik business user yang login
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Member Profile')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(20),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('dob')
                            ->label('Date of Birth')
                            ->maxDate(now()),
                    ])->columns(2),

                Forms\Components\Section::make('Loyalty Status')
                    ->schema([
                        Forms\Components\Select::make('tier')
                            ->options([
                                'Silver' => 'Silver',
                                'Gold' => 'Gold',
                                'Platinum' => 'Platinum',
                            ])
                            ->required()
                            ->default('Silver'),

                        Forms\Components\TextInput::make('current_points')
                            ->label('Current Points')
                            ->numeric()
                            ->default(0)
                            // Admin boleh edit poin manual (untuk koreksi), tapi sebaiknya hati-hati
                            ->helperText('Edit manual poin hanya untuk koreksi.'),

                        Forms\Components\TextInput::make('qr_token')
                            ->label('QR Token')
                            ->disabled() // Token digenerate sistem, admin tidak perlu ubah
                            ->dehydrated(false),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable()
                    ->icon('heroicon-m-phone'),
                Tables\Columns\TextColumn::make('tier')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Platinum' => 'info',
                        'Gold' => 'warning',
                        'Silver' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_points')
                    ->numeric()
                    ->sortable()
                    ->label('Points'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tier')
                    ->options([
                        'Silver' => 'Silver',
                        'Gold' => 'Gold',
                        'Platinum' => 'Platinum',
                    ]),
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
            // Kita akan daftarkan Relation Manager Voucher di sini nanti
            RelationManagers\VouchersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'create' => Pages\CreateMember::route('/create'),
            'edit' => Pages\EditMember::route('/{record}/edit'),
        ];
    }
}
