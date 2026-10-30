<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Ai\AiModule;
use App\Ai\Assistant;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Filament\Pages\Plugins;
use App\Models\AiUsage;
use App\Plugins\PluginManager;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Settings → AI assistant (App\Ai\Assistant): which AI plugin answers, what it may do and a monthly limit in tokens.
 * Super admins only, since what it sends leaves the server.
 */
class AiSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'AI assistant';

    protected static ?int $navigationSort = 80;

    protected static ?string $title = 'AI assistant';

    protected static ?string $slug = 'ai';

    public static function settingsDescription(): string
    {
        return __('Buttons that improve, translate and write texts, draft ticket replies and suggest domain names, with an AI service such as Claude.');
    }

    public static function keys(): array
    {
        return ['ai.provider', ...array_map(fn (string $feature) => "ai.features.{$feature}", Assistant::FEATURES), 'ai.monthly_tokens'];
    }

    public function form(Schema $schema): Schema
    {
        $modules = app(PluginManager::class)->aiModules();

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('AI service'))
                    ->description(__('The assistant only suggests: you see its text, change it if you like and decide whether to use it. Nothing goes to a client and nothing is saved until you do.'))
                    ->schema([
                        Text::make(fn () => new HtmlString($modules->isEmpty()
                            ? __('No AI plugin is on, so the assistant is off. Turn on one, such as Claude, under <a href=":url" class="underline">Settings → Plugins</a>, and fill in its API key.', ['url' => Plugins::getUrl()])
                            : e(__('In use: :name.', ['name' => Assistant::provider()?->aiLabel()])).(Assistant::provider()?->aiConfigured() ? '' : ' '.e(__('Fill in its API key on its settings page.'))))),
                        Select::make('ai.provider')
                            ->label('AI service')
                            ->options($modules->map(fn (AiModule $module) => $module->aiLabel())->all())
                            ->placeholder(__('The first one that is on'))
                            ->visible($modules->count() > 1),
                    ]),
                Section::make(__('What it may do'))
                    ->description(__('Each button sends only what its switch says, with fixed instructions; never passwords or anything else from GaiaCP.'))
                    ->schema([
                        Toggle::make('ai.features.improve')
                            ->label('Improve texts')
                            ->helperText(__('Next to replies, emails and descriptions: fix spelling, make it friendlier, clearer, shorter or more formal. Sends the text you wrote.')),
                        Toggle::make('ai.features.translate')
                            ->label('Translate texts')
                            ->helperText(__('Translate a reply into the client\'s language, or fill a text\'s other languages from the one you wrote. Sends the text being translated.')),
                        Toggle::make('ai.features.products')
                            ->label('Write product descriptions')
                            ->helperText(__('On a product\'s description: write it from the product\'s name, type, package and compare table values. Sends those details.')),
                        Toggle::make('ai.features.outage')
                            ->label('Write the server problem notice')
                            ->helperText(__('Under Settings → Client area → Server problems: write the message from what the monitors say now. Sends the servers\' public names, status, since when and how many accounts are on them; never addresses or clients.')),
                        Toggle::make('ai.features.questions')
                            ->label('Questions about your data')
                            ->helperText(__('Reports → Ask in plain words: questions such as "who owes more than €100?". Sends only the question and the list of questions GaiaCP can answer; GaiaCP then answers from its data, which never goes to the AI service.')),
                        Toggle::make('ai.features.help')
                            ->label('Panel help')
                            ->helperText(__('System → Help: Ask the AI assistant answers what you typed in the guide\'s search, such as "how do I add a TLD?", from the closest parts of the admin guide, with links to them. Sends the question and those parts; nothing about clients.')),
                        Toggle::make('ai.features.ticket_reply')
                            ->label('Draft ticket replies')
                            ->helperText(__('In a ticket\'s Reply: Draft a reply from the conversation, the client\'s services (type, name, status, paid until) and the closest knowledge base articles. The client\'s name, email addresses and phone numbers are replaced before it is sent; notes and files are never sent.')),
                        Toggle::make('ai.features.ticket_summary')
                            ->label('Summarise tickets')
                            ->helperText(__('On a ticket: Summarise reads what the client needs in one line, with the priority, department and mood it suggests; you keep what you agree with. Sends the conversation, without the client\'s details.')),
                        Toggle::make('ai.features.kb_article')
                            ->label('Knowledge base articles from tickets')
                            ->helperText(__('On an answered ticket: Write a knowledge base article for anyone with the same question, saved as a draft once you agree. Sends the conversation without the client\'s details or domain names.')),
                        Toggle::make('ai.features.reminders')
                            ->label('Personal payment reminders')
                            ->helperText(__('On an unpaid payment notice: Personal reminder rewrites the reminder email for how the client pays, friendlier for one who is rarely late, firmer for one who often is; you read it and send it. Sends the email template\'s text, the amount, the days and how the client paid before; not who they are.')),
                        Toggle::make('ai.features.clients')
                            ->label('Suggestions about clients')
                            ->helperText(__('On a client\'s page: Suggestions lists what they might buy, signs they may leave and what to look after. Sends their services, prices, payment history and ticket subjects, and the products on offer; not who they are. Only for admins who also see billing.')),
                        Toggle::make('ai.features.language_files')
                            ->label('Translate the panel')
                            ->helperText(__('Settings → Languages → Translate with AI (super admins): the panel\'s texts that a language misses, a batch at a time, saved like uploaded translations. Sends only those texts; a whole new language is about 300,000 tokens.')),
                        Toggle::make('ai.features.domains')
                            ->label('Suggest domain names')
                            ->helperText(__('In the client area\'s domain search, a Suggest other names button that clients and visitors press: names close to the one searched, each checked like a search. Sends only the name searched. Each press uses tokens, so a client or an address gets at most :count presses an hour.', ['count' => SearchDomains::AI_PER_HOUR])),
                    ]),
                Section::make(__('Monthly limit'))
                    ->schema([
                        TextInput::make('ai.monthly_tokens')
                            ->label('Tokens a month')
                            ->integer()
                            ->minValue(1000)
                            ->placeholder(__('No limit'))
                            ->helperText(fn () => __('For every admin together, sent and received; a short reply is about 1,000. Used this month: :used.', ['used' => number_format(AiUsage::tokensThisMonth())])),
                    ]),
            ]);
    }

    protected function forForm(array $values): array
    {
        foreach (array_map(fn (string $feature) => "ai.features.{$feature}", Assistant::FEATURES) as $key) {
            $values[$key] = (bool) $values[$key];
        }

        return $values;
    }

    protected function forStorage(array $values): array
    {
        foreach (array_map(fn (string $feature) => "ai.features.{$feature}", Assistant::FEATURES) as $key) {
            $values[$key] = (bool) $values[$key];
        }
        $values['ai.monthly_tokens'] = filled($values['ai.monthly_tokens'] ?? null) ? (int) $values['ai.monthly_tokens'] : null;

        return $values;
    }
}
