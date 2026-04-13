<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Models\DiscountRule;
use App\Services\VoucherService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class VouchersRelationManager extends RelationManager
{
    protected static string $relationship = 'vouchers';

    protected static ?string $title = 'Member Vouchers';

    public function form(Form $form): Form
    {
        // Form ini hanya untuk EDIT voucher (misal ubah masa berlaku)
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->disabled(),
                Forms\Components\DateTimePicker::make('valid_until')
                    ->label('Valid Until')
                    ->required(),
                Forms\Components\Toggle::make('is_used')
                    ->label('Already Used?'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('discountRule.name')
                    ->label('Promo Name')
                    ->limit(30),

                Tables\Columns\TextColumn::make('discountRule.discount_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'percentage' ? 'info' : 'success'),

                Tables\Columns\TextColumn::make('valid_until')
                    ->dateTime('d M Y')
                    ->label('Expires')
                    ->color(fn ($record) => $record->isValid() ? 'success' : 'danger'),

                Tables\Columns\IconColumn::make('is_used')
                    ->boolean()
                    ->label('Used'),
            ])
            ->headerActions([
                // === AKSI CUSTOM: ISSUE VOUCHER ===
                Tables\Actions\Action::make('issue_voucher')
                    ->label('Issue New Voucher')
                    ->icon('heroicon-o-ticket')
                    ->form([
                        Forms\Components\Select::make('discount_rule_id')
                            ->label('Select Promo / Discount Rule')
                            ->options(function () {
                                // Ambil Discount Rule yang aktif milik bisnis ini
                                return DiscountRule::where('business_id', Auth::user()->business_id)
                                    ->where('is_active', true)
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->required(),

                        Forms\Components\TextInput::make('valid_days')
                            ->label('Valid For (Days)')
                            ->numeric()
                            ->default(30)
                            ->helperText('Kosongkan untuk mengikuti aturan master (jika ada).'),
                    ])
                    ->action(function (array $data, RelationManager $livewire) {
                        $member = $livewire->getOwnerRecord();
                        $rule = DiscountRule::find($data['discount_rule_id']);
                        $days = !empty($data['valid_days']) ? (int)$data['valid_days'] : null;

                        if ($member && $rule) {
                            // Panggil Service kita!
                            VoucherService::issueVoucher($member, $rule, $days);

                            Notification::make()
                                ->title('Voucher Issued Successfully')
                                ->success()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                // Tables\Actions\EditAction::make(), // Bolehkan edit jika admin perlu perpanjang masa berlaku
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
