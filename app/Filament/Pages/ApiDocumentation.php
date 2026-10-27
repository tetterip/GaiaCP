<?php

namespace App\Filament\Pages;

use App\Api\OpenApi;
use App\Filament\Clusters\Settings\Pages\ApiKeySettings;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → API keys → Documentation: every REST API call (App\Api\OpenApi::endpoints()), with the permission it
 * needs and its fields, and the openapi.json to download for Postman, Insomnia or a code generator. The calls'
 * texts are for developers and stay in English, as in openapi.json.
 */
class ApiDocumentation extends Page
{
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $cluster = SettingsCluster::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'API documentation';

    protected static ?string $slug = 'api-keys/documentation';

    protected string $view = 'filament.settings.api-documentation';

    public function getSubheading(): ?string
    {
        return __('Base URL: :url. Send the key as Authorization: Bearer gcp_… with each call; answers are JSON.', ['url' => url('api/v1')]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('keys')
                ->label('API keys')
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->url(fn () => ApiKeySettings::getUrl()),
            Action::make('download')
                ->label('Download openapi.json')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => response()->streamDownload(
                    fn () => print (json_encode(OpenApi::spec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                    'gaiacp-openapi.json',
                    ['Content-Type' => 'application/json'],
                )),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> the calls by area, each with its body's fields */
    public function getEndpointsProperty(): array
    {
        $schemas = OpenApi::spec()['components']['schemas'];

        return collect(OpenApi::endpoints())
            ->map(fn (array $endpoint) => $endpoint + [
                'fields' => isset($endpoint['body']) ? collect($schemas[$endpoint['body']]['properties'] ?? [])
                    ->map(fn (array $field, string $name) => [
                        'name' => $name,
                        'type' => self::type($field),
                        'required' => in_array($name, $schemas[$endpoint['body']]['required'] ?? [], true),
                        'description' => $field['description'] ?? null,
                    ])->values()->all() : [],
            ])
            ->groupBy('area')
            ->map(fn ($endpoints) => $endpoints->values()->all())
            ->all();
    }

    /** @param  array<string, mixed>  $field */
    private static function type(array $field): string
    {
        $type = collect((array) ($field['type'] ?? 'object'))->reject(fn ($type) => $type === 'null')->join(' | ');

        if (isset($field['enum'])) {
            return collect($field['enum'])->filter()->join(' | ');
        }

        return $type.(isset($field['format']) ? " ({$field['format']})" : '');
    }
}
