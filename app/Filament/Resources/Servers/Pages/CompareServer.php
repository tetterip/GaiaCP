<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Actions\CompareServerAccounts;
use App\Actions\FixServerDifference;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ListsAccounts;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

/**
 * Servers → Compare with the server (CompareServerAccounts): what the panel has that GaiaCP doesn't bill, what GaiaCP
 * bills that the panel doesn't have, and what differs. Read when the page opens, on "Read again" and after each fix
 * (FixServerDifference): one click makes one side like the other, asking first.
 */
class CompareServer extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected string $view = 'filament.servers.compare';

    /** @var array<string, mixed>|null the last reading, as plain arrays (null until read); the fixes take the server's values from it */
    #[Locked]
    public ?array $comparison = null;

    public ?string $error = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->read();
    }

    /** "Compare with the server", on a server whose panel can list its accounts (Servers list, server page). */
    public static function action(): Action
    {
        return Action::make('compare')
            ->label('Compare with the server')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->visible(fn (Server $record) => self::canList($record))
            ->url(fn (Server $record) => ServerResource::getUrl('compare', ['record' => $record]));
    }

    public static function canList(Server $server): bool
    {
        $panel = rescue(fn () => app(ProvisionerFactory::class)->forServer($server), null, report: false);

        return $panel instanceof ListsAccounts;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Compare :server with GaiaCP', ['server' => $this->getRecord()->name]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('readAgain')
                ->label('Read again')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->read()),
        ];
    }

    /** An account GaiaCP should leave alone, e.g. the reseller account the server's owner uses: not shown as a problem again. */
    public function ignoreAction(): Action
    {
        return Action::make('ignore')
            ->label('Not billed on purpose')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('gray')
            ->size('sm')
            ->link()
            ->modalHeading(fn (array $arguments) => __('Not billed on purpose: :username', ['username' => $arguments['username'] ?? '']))
            ->modalDescription(__('The account stays as it is on the server. It is listed apart and the nightly comparison no longer reports it.'))
            ->schema([
                TextInput::make('note')
                    ->maxLength(255)
                    ->placeholder(__('e.g. our own reseller account')),
            ])
            ->action(function (array $data, array $arguments) {
                $this->setIgnored((string) $arguments['username'], (string) ($data['note'] ?? ''));
            });
    }

    public function unignoreAction(): Action
    {
        return Action::make('unignore')
            ->label('Show again')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->size('sm')
            ->link()
            ->action(fn (array $arguments) => $this->setIgnored((string) $arguments['username'], null));
    }

    /** A fix for a service: arguments service (its id) and fix (a FixServerDifference constant). */
    public function fixAction(): Action
    {
        return Action::make('fix')
            ->label(fn (array $arguments) => self::fixLabel((string) ($arguments['fix'] ?? '')))
            ->color(fn (array $arguments) => FixServerDifference::onServer((string) ($arguments['fix'] ?? '')) ? 'primary' : 'gray')
            ->size('sm')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => self::fixLabel((string) ($arguments['fix'] ?? '')))
            ->modalDescription(fn (array $arguments) => self::fixDescription((string) ($arguments['fix'] ?? ''), $this->row((int) ($arguments['service'] ?? 0))))
            ->modalSubmitActionLabel(__('Fix'))
            ->action(function (array $arguments) {
                $row = $this->row((int) ($arguments['service'] ?? 0));
                $service = Service::query()->where('server_id', $this->getRecord()->getKey())->find($row['service']['id'] ?? 0);

                if ($service === null) {
                    Notification::make()->warning()->title(__('Read the server again first'))->send();

                    return;
                }

                $account = isset($row['account']) ? new PanelAccount($row['account']['username'], $row['account']['domain'], $row['account']['package'], $row['account']['suspended']) : null;

                try {
                    app(FixServerDifference::class)->handle($service, (string) $arguments['fix'], $account);
                } catch (ProvisioningException|InvalidArgumentException $e) {
                    Notification::make()->danger()->title(__('Not fixed'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('Fixed'))->send();
                $this->read();
            });
    }

    /** Suspend an account no one pays for, on the server only: argument username. */
    public function suspendAccountAction(): Action
    {
        return Action::make('suspendAccount')
            ->label('Suspend on the server')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            ->size('sm')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => __('Suspend :username on the server?', ['username' => $arguments['username'] ?? '']))
            ->modalDescription(__('The site stops working until it is unsuspended on the server. Nothing is deleted and GaiaCP is not changed.'))
            ->action(function (array $arguments) {
                $row = collect($this->comparison['onServerOnly'] ?? [])->flatten(1)->firstWhere('username', $arguments['username'] ?? null);
                if ($row === null) {
                    Notification::make()->warning()->title(__('Read the server again first'))->send();

                    return;
                }

                try {
                    app(FixServerDifference::class)->suspendUnbilled($this->getRecord(), new PanelAccount($row['username'], $row['domain'], $row['package'], $row['suspended']));
                } catch (ProvisioningException $e) {
                    Notification::make()->danger()->title(__('Not fixed'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('Suspended on the server'))->send();
                $this->read();
            });
    }

    public static function fixLabel(string $fix): string
    {
        return match ($fix) {
            FixServerDifference::UNSUSPEND_ON_SERVER => __('Unsuspend on the server'),
            FixServerDifference::MARK_SUSPENDED => __('Mark suspended in GaiaCP'),
            FixServerDifference::SUSPEND_ON_SERVER => __('Suspend on the server'),
            FixServerDifference::MARK_ACTIVE => __('Mark active in GaiaCP'),
            FixServerDifference::TAKE_PACKAGE => __('Use the server\'s package'),
            FixServerDifference::APPLY_PACKAGE => __('Set GaiaCP\'s package on the server'),
            FixServerDifference::TAKE_DOMAIN => __('Use the server\'s domain'),
            FixServerDifference::CREATE_ON_SERVER => __('Create on server'),
            FixServerDifference::MARK_TERMINATED => __('Mark terminated in GaiaCP'),
            default => $fix,
        };
    }

    /** @param  array<string, mixed>|null  $row */
    private static function fixDescription(string $fix, ?array $row): string
    {
        $service = $row['service'] ?? [];
        $account = $row['account'] ?? [];

        return match ($fix) {
            FixServerDifference::UNSUSPEND_ON_SERVER => __('The account :username works again on the server. GaiaCP already has it active; no email is sent.', ['username' => $account['username'] ?? '']),
            FixServerDifference::MARK_SUSPENDED => __('Only the status in GaiaCP changes, to Suspended. The server and the client are not touched.'),
            FixServerDifference::SUSPEND_ON_SERVER => __('The account :username is suspended on the server too, so the site stops working. No email is sent.', ['username' => $account['username'] ?? '']),
            FixServerDifference::MARK_ACTIVE => __('Only the status in GaiaCP changes, to Active. The server and the client are not touched.'),
            FixServerDifference::TAKE_PACKAGE => __('The service\'s package becomes ":package", as on the server. Its price is not changed.', ['package' => $account['package'] ?? '']),
            FixServerDifference::APPLY_PACKAGE => __('The account gets package ":package" on the server, as in GaiaCP. Its disk and other limits change with it.', ['package' => $service['package'] ?? '']),
            FixServerDifference::TAKE_DOMAIN => __('The service\'s domain becomes :domain, as on the server.', ['domain' => $account['domain'] ?? '']),
            FixServerDifference::CREATE_ON_SERVER => __('Creates the account :username for :domain with package ":package" on this server, with the password stored in GaiaCP. No email is sent to the client.', [
                'username' => ($service['username'] ?? null) ?: __('(generated)'), 'domain' => $service['domain'] ?? '', 'package' => $service['package'] ?? '',
            ]),
            FixServerDifference::MARK_TERMINATED => __('Only the status in GaiaCP changes, to Terminated, and its unpaid payment notice lines are removed. The server is not touched.'),
            default => '',
        };
    }

    /**
     * A service's row in the last reading: a difference (with the account) or a missing one (without).
     *
     * @return array{service: array<string, mixed>, account?: array<string, mixed>}|null
     */
    private function row(int $serviceId): ?array
    {
        foreach ($this->comparison['different'] ?? [] as $row) {
            if ($row['service']['id'] === $serviceId) {
                return $row;
            }
        }

        foreach ($this->comparison['missing'] ?? [] as $service) {
            if ($service['id'] === $serviceId) {
                return ['service' => $service];
            }
        }

        return null;
    }

    private function setIgnored(string $username, ?string $note): void
    {
        /** @var Server $server */
        $server = $this->getRecord();
        $ignored = $server->ignored_accounts ?? [];

        if ($note === null) {
            unset($ignored[$username]);
        } else {
            $ignored[$username] = $note;
        }

        $server->forceFill(['ignored_accounts' => $ignored ?: null])->save();
        ActivityLog::record($note === null ? 'server.account_unignored' : 'server.account_ignored', $note === null
            ? "{$server->name}: {$username} shown again in the comparison"
            : "{$server->name}: {$username} marked not billed on purpose".($note !== '' ? " ({$note})" : ''), properties: ['server_id' => $server->id]);

        $this->read();
    }

    public function read(): void
    {
        /** @var Server $server */
        $server = $this->getRecord();
        $this->error = null;

        try {
            $result = app(CompareServerAccounts::class)->handle($server);
        } catch (ProvisioningException $e) {
            $this->comparison = null;
            $this->error = $e->getMessage();

            return;
        }

        $rows = array_map(fn (array $row) => self::account($row['account']) + [
            'former' => $row['former'] ? self::service($row['former']) : null,
            'elsewhere' => $row['elsewhere'] ? self::service($row['elsewhere']) + ['server' => $row['elsewhere']->server?->name] : null,
            'note' => $row['note'],
            'add_url' => ServiceResource::getUrl('create', array_filter([
                'server' => $server->id,
                'username' => $row['account']->username,
                'domain' => $row['account']->domain,
                'package' => $row['account']->package,
                'suspended' => $row['account']->suspended ? 1 : null,
            ])),
        ], $result['onServerOnly']);

        // Running and billed nowhere first; then suspended leftovers, the ones billed on another server, and the ones
        // marked "Not billed on purpose".
        $group = fn (array $source) => match (true) {
            $source['ignored'] => 'ignored',
            $source['elsewhere'] !== null => 'elsewhere',
            $source['account']->suspended => 'suspended',
            default => 'unbilled',
        };
        $groups = ['unbilled' => [], 'suspended' => [], 'elsewhere' => [], 'ignored' => []];
        foreach ($rows as $i => $row) {
            $groups[$group($result['onServerOnly'][$i])][] = $row;
        }

        $this->comparison = [
            'onServerOnly' => $groups,
            'missing' => array_map(self::service(...), $result['missing']),
            'different' => array_map(fn (array $row) => [
                'account' => self::account($row['account']),
                'service' => self::service($row['service']),
                'differences' => array_map(fn (string $d) => __($d), $row['differences']),
                'fixes' => array_merge(...array_map(FixServerDifference::forDifference(...), $row['differences'])),
            ], $result['different']),
            'matching' => $result['matching'],
            'read_at' => now()->format('H:i'),
        ];
    }

    /** @return array<string, mixed> */
    private static function account(PanelAccount $account): array
    {
        return ['username' => $account->username, 'domain' => $account->domain, 'package' => $account->package, 'suspended' => $account->suspended];
    }

    /** @return array<string, mixed> */
    private static function service(Service $service): array
    {
        return [
            'id' => $service->id,
            'label' => $service->label,
            'domain' => $service->domain,
            'username' => $service->username,
            'package' => $service->package,
            'status' => $service->status->getLabel(),
            'status_color' => $service->status->getColor(),
            'client' => $service->client?->name,
            'url' => ServiceResource::getUrl('edit', ['record' => $service]),
            'client_url' => $service->client_id ? ClientResource::getUrl('edit', ['record' => $service->client_id]) : null,
        ];
    }
}
