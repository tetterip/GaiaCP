<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\Coupons\CouponResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Order;
use App\Support\Money;
use App\Support\TermsOfService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('client.last_name')
                            ->label('Client')
                            ->state(fn (Order $record) => $record->client->displayName()),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('total')
                            ->label('First payment total')
                            ->state(fn (Order $record) => $record->describeTotal()),
                        TextEntry::make('created_at')
                            ->label('Placed')
                            ->date(),
                        TextEntry::make('payment_notice')
                            ->label('Payment notice')
                            ->state(fn (Order $record) => ($notice = $record->invoices()->latest('id')->first()) ? "{$notice->number} ({$notice->status->getLabel()})" : null)
                            ->url(fn (Order $record) => ($id = $record->invoices()->latest('id')->value('id')) ? InvoiceResource::getUrl('view', ['record' => $id]) : null)
                            ->placeholder('—'),
                        TextEntry::make('coupon')
                            ->label('Coupon')
                            ->state(fn (Order $record) => $record->coupon ? __(':code, :amount off the first payment', [
                                'code' => $record->coupon->code,
                                'amount' => Money::format((int) $record->discount_cents, $record->client->currencyCode()),
                            ]) : null)
                            ->url(fn (Order $record) => $record->coupon ? CouponResource::getUrl('index', ['search' => $record->coupon->code]) : null)
                            ->visible(fn (Order $record) => $record->coupon_id !== null),
                        TextEntry::make('terms_accepted_at')
                            ->label('Terms of Service')
                            ->state(fn (Order $record) => TermsOfService::describeAcceptance($record->terms_accepted_at, $record->terms_version))
                            ->visible(fn (Order $record) => $record->terms_accepted_at !== null)
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
