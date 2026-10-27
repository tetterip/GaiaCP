<?php

namespace App\Filament\Resources\ClientMessages\Schemas;

use App\Enums\ClientMessageKind;
use App\Enums\ServiceType;
use App\Filament\Actions\AiTextActions;
use App\Models\ClientMessage;
use App\Models\Product;
use App\Models\Server;
use App\Models\Tld;
use App\Support\Locales;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ClientMessageForm
{
    public static function configure(Schema $schema): Schema
    {
        // The text and who gets it are fixed once the email went out; when it shows in the client area is not.
        $sent = fn (?ClientMessage $record) => (bool) $record?->isQueued();

        return $schema->columns(1)->components([
            Section::make('Message')
                ->disabled($sent)
                ->schema([
                    Radio::make('kind')
                        ->options(ClientMessageKind::class)
                        ->default(ClientMessageKind::Info)
                        ->required()
                        ->live(),
                    self::texts(),
                ]),
            Section::make('Recipients')
                ->description(__('Active clients; leave a filter empty for everyone. Service filters count active and suspended services.'))
                ->columns(2)
                ->disabled($sent)
                ->schema([
                    Select::make('filters.servers')
                        ->label('On the servers')
                        ->multiple()
                        ->options(fn () => Server::query()->orderBy('name')->pluck('name', 'id'))
                        ->live(),
                    Select::make('filters.products')
                        ->label('With the products')
                        ->multiple()
                        ->options(fn () => Product::query()->orderBy('name')->pluck('name', 'id'))
                        ->live(),
                    TagsInput::make('filters.tlds')
                        ->label('With a domain ending in')
                        ->placeholder('.gr')
                        ->suggestions(fn () => Tld::query()->orderBy('tld')->pluck('tld')->all())
                        ->live(),
                    Select::make('filters.types')
                        ->label('With services of type')
                        ->multiple()
                        ->options(ServiceType::class)
                        ->live(),
                    Select::make('filters.locales')
                        ->label('Email language')
                        ->multiple()
                        ->options(Locales::available())
                        ->visible(Locales::multiple())
                        ->live(),
                    Text::make(fn (Get $get, ?ClientMessage $record) => self::count($get, $record))
                        ->columnSpanFull(),
                ]),
            Section::make('Where it appears')
                ->columns(2)
                ->schema([
                    Toggle::make('send_email')
                        ->label('Email it')
                        ->default(true)
                        ->disabled($sent)
                        ->helperText(__('Sent with Send, on this page once saved.')),
                    Toggle::make('show_in_client_area')
                        ->label('Show in the client area')
                        ->live()
                        ->helperText(__('On Home, for the clients it matches, until the date below.')),
                    DatePicker::make('show_from')
                        ->label('Show from')
                        ->visible(fn (Get $get) => (bool) $get('show_in_client_area')),
                    DatePicker::make('show_until')
                        ->label('Show until')
                        ->afterOrEqual('show_from')
                        ->visible(fn (Get $get) => (bool) $get('show_in_client_area')),
                ]),
        ]);
    }

    /** A subject and text per language; only the default language's is required, the others fall back to it. */
    private static function texts(): Tabs|Section
    {
        $fields = fn (string $locale) => [
            TextInput::make("texts.{$locale}.subject")
                ->label('Subject')
                ->maxLength(255)
                ->required(Locales::isDefault($locale)),
            AiTextActions::forLocale(MarkdownEditor::make("texts.{$locale}.body")
                ->label('Message')
                ->required(Locales::isDefault($locale))
                ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                ->helperText(Locales::isDefault($locale)
                    ? __('Placeholders: {$client_first_name}, {$client_name}, {$company_name}. The same text shows in the client area.')
                    : __('Leave empty to send the text of the first language.')), $locale, fn (string $other) => "texts.{$other}.body"),
        ];

        if (! Locales::multiple()) {
            return Section::make()->contained(false)->schema($fields(Locales::default()));
        }

        return Tabs::make('texts')->tabs(collect(Locales::available())
            ->map(fn (string $name, string $locale) => Tab::make($name)->schema($fields($locale)))
            ->values()->all());
    }

    private static function count(Get $get, ?ClientMessage $record): string
    {
        if ($record?->isQueued()) {
            return __('Emailed to :count client(s).', ['count' => $record->recipients()->count()]);
        }

        $message = ClientMessage::make(['kind' => $get('kind') ?? ClientMessageKind::Info, 'filters' => (array) $get('filters')]);

        return __('Matches :count active client(s); :emails get the email.', [
            'count' => $message->clients()->count(),
            'emails' => $message->emailClients()->count(),
        ]);
    }
}
