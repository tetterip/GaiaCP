<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Support tickets (App\Actions\Tickets): what clients may do, the admins' emails, closing by itself, the files, and the knowledge base. */
class SupportSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 50;

    protected static ?string $title = 'Support';

    protected static ?string $slug = 'support';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Support;
    }

    public static function settingsDescription(): string
    {
        return __('Support tickets in the client area, the admins\' emails, closing old tickets and attached files.');
    }

    public static function keys(): array
    {
        return ['support.client_tickets', 'support.client_priority', 'support.admin_emails', 'support.auto_close_days', 'support.attachments_max_kb', 'support.attachment_types', 'support.reply_to', 'support.knowledge_base'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Tickets'))
                    ->schema([
                        Toggle::make('support.client_tickets')
                            ->label('Clients open tickets in the client area')
                            ->helperText(__('Off, the client area has no Tickets page; tickets you open for a client are still emailed to them.')),
                        Toggle::make('support.client_priority')
                            ->label('Clients choose the priority')
                            ->helperText(__('Off, their tickets start as Medium.')),
                        Toggle::make('support.admin_emails')
                            ->label('Email the admins about new tickets and client replies')
                            ->helperText(__('To the admin a ticket is assigned to, else every admin who opens tickets; only admins with Receive system emails on.')),
                        TextInput::make('support.auto_close_days')
                            ->label('Close tickets after')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->suffix(__('days'))
                            ->helperText(__('A ticket waiting for the client\'s answer closes by itself after this many days without one, with the Support Ticket Closed email. Empty: never.')),
                        TextInput::make('support.reply_to')
                            ->label('Replies to ticket emails go to')
                            ->email()
                            ->maxLength(255)
                            ->placeholder('support@example.com')
                            ->helperText(__('The Reply-To of ticket emails to clients, e.g. the mailbox the Email to ticket plugin reads, so their answers join the ticket. A department\'s own email wins. Empty: the usual sender address.')),
                    ]),
                Section::make(__('Knowledge base'))
                    ->schema([
                        Toggle::make('support.knowledge_base')
                            ->label('Knowledge base in the client area')
                            ->helperText(__('The published articles of Support → Knowledge base, with search, and the matching ones offered while a client writes a ticket. The page shows once an article is published.')),
                    ]),
                Section::make(__('Attached files'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('support.attachments_max_kb')
                            ->label('Largest file')
                            ->numeric()
                            ->integer()
                            ->minValue(100)
                            ->maxValue(51200)
                            ->suffix('KB')
                            ->required()
                            ->helperText(__('The server\'s own upload limit (PHP upload_max_filesize) may be lower.')),
                        TextInput::make('support.attachment_types')
                            ->label('Allowed file types')
                            ->required()
                            ->regex('/^\s*\.?[a-z0-9]+(\s*,\s*\.?[a-z0-9]+)*\s*$/i')
                            ->helperText(__('Extensions separated by commas, e.g. jpg, png, pdf, zip')),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        $values['support.client_tickets'] = (bool) ($values['support.client_tickets'] ?? false);
        $values['support.client_priority'] = (bool) ($values['support.client_priority'] ?? false);
        $values['support.admin_emails'] = (bool) ($values['support.admin_emails'] ?? false);
        $values['support.knowledge_base'] = (bool) ($values['support.knowledge_base'] ?? false);
        $values['support.auto_close_days'] = filled($values['support.auto_close_days'] ?? null) ? (int) $values['support.auto_close_days'] : null;
        $values['support.attachments_max_kb'] = (int) ($values['support.attachments_max_kb'] ?? 5120);
        $values['support.reply_to'] = filled($values['support.reply_to'] ?? null) ? strtolower(trim($values['support.reply_to'])) : null;
        $values['support.attachment_types'] = strtolower(preg_replace('/[\s.]+/', '', (string) ($values['support.attachment_types'] ?? '')));

        return $values;
    }
}
