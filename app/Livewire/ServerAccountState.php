<?php

namespace App\Livewire;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use App\Plugins\PluginManager;
use App\Provisioning\AccountState;
use App\Provisioning\ProvisioningException;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "On the server" on the admin's hosting service page: whether the account is active, suspended or missing on its
 * server, read from the panel after the page (lazy, ServiceLifecycle::serverState()), beside the status stored here.
 * When they differ, it says what that means for the client and offers to fix either side.
 */
class ServerAccountState extends Component
{
    #[Locked]
    public Service $service;

    /** Set by Check again for this request only. */
    protected bool $readNow = false;

    /** An active or suspended hosting account with a username, on a server whose panel plugin is on. */
    public static function shows(?Service $service): bool
    {
        return $service !== null
            && $service->type === ServiceType::Hosting
            && in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)
            && $service->server !== null
            && filled($service->username)
            && app(PluginManager::class)->serverModules()->has($service->server->type);
    }

    public function placeholder(): string
    {
        return '<div style="padding: .25rem 0; font-size: .875rem; color: var(--gray-500)">'.e(__('Checking the account on the server…')).'</div>';
    }

    public function checkAgain(): void
    {
        $this->readNow = true;
    }

    public function unsuspendOnServer(): void
    {
        $this->fix(fn (ServiceLifecycle $lifecycle) => $lifecycle->unsuspend($this->service), __('Unsuspended on the server'));
    }

    public function suspendOnServer(): void
    {
        $this->fix(fn (ServiceLifecycle $lifecycle) => $lifecycle->suspend($this->service, $this->service->suspension_reason ?: Service::SUSPENDED_OVERDUE), __('Suspended on the server'));
    }

    public function createOnServer(): void
    {
        $this->fix(fn (ServiceLifecycle $lifecycle) => $lifecycle->create($this->service, sendWelcome: false), __('Account created'));
    }

    public function followServer(): void
    {
        $this->fix(fn (ServiceLifecycle $lifecycle) => $lifecycle->followServer($this->service), __('Status updated to match the server'));
    }

    /** @param  Closure(ServiceLifecycle): void  $call */
    private function fix(Closure $call, string $success): void
    {
        abort_unless(ServiceResource::canAccess() && self::shows($this->service), 403);

        try {
            $call(app(ServiceLifecycle::class));
        } catch (ProvisioningException $e) {
            Notification::make()->title(__('Failed'))->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();

        // The page's form and Account menu show the status too: open it again
        $this->redirect(ServiceResource::getUrl('edit', ['record' => $this->service]));
    }

    public function render(): View
    {
        $state = null;
        $error = null;

        try {
            $state = app(ServiceLifecycle::class)->serverState($this->service, $this->readNow);
        } catch (ProvisioningException $e) {
            $error = $e->getMessage();
        }

        $here = $this->service->status;
        $server = $this->service->server->name;
        $username = $this->service->username;

        return view('livewire.server-account-state', [
            'badge' => match ($state) {
                AccountState::Active => ['label' => __('Active'), 'color' => 'success'],
                AccountState::Suspended => ['label' => __('Suspended'), 'color' => $here === ServiceStatus::Suspended ? 'warning' : 'danger'],
                AccountState::Missing => ['label' => __('Not found'), 'color' => 'danger'],
                null => ['label' => __('Unknown'), 'color' => 'gray'],
            },
            'note' => match (true) {
                $state === null => __('The server did not answer: :error', ['error' => $error]),
                $state === AccountState::Active && $here === ServiceStatus::Active,
                $state === AccountState::Suspended && $here === ServiceStatus::Suspended => __('Same as in GaiaCP'),
                default => null,
            },
            'problem' => match (true) {
                $state === AccountState::Suspended && $here === ServiceStatus::Active => [
                    'title' => __('Does not match GaiaCP.'),
                    'text' => __('In GaiaCP the service is active, but on :server the account :username is suspended: the client\'s website and email do not work.', ['server' => $server, 'username' => $username]),
                    'actions' => [
                        ['method' => 'unsuspendOnServer', 'label' => __('Unsuspend on the server'), 'primary' => true, 'confirm' => __('Unsuspend :username on :server?', ['username' => $username, 'server' => $server])],
                        ['method' => 'followServer', 'label' => __('Mark as suspended in GaiaCP'), 'primary' => false, 'confirm' => null],
                    ],
                ],
                $state === AccountState::Active && $here === ServiceStatus::Suspended => [
                    'title' => __('Does not match GaiaCP.'),
                    'text' => __('In GaiaCP the service is suspended, but on :server the account :username is active: the client still has their website and email.', ['server' => $server, 'username' => $username]),
                    'actions' => [
                        ['method' => 'suspendOnServer', 'label' => __('Suspend on the server'), 'primary' => true, 'confirm' => __('Suspend :username on :server?', ['username' => $username, 'server' => $server])],
                        ['method' => 'followServer', 'label' => __('Mark as active in GaiaCP'), 'primary' => false, 'confirm' => null],
                    ],
                ],
                $state === AccountState::Missing => [
                    'title' => __('The account :username does not exist on :server.', ['username' => $username, 'server' => $server]),
                    'text' => __('It may have been deleted, or moved to another server: then pick that server under Hosting account and save.'),
                    'actions' => [
                        ['method' => 'createOnServer', 'label' => __('Create on server'), 'primary' => false, 'confirm' => __('Create :username on :server with the password stored here?', ['username' => $username, 'server' => $server])],
                    ],
                ],
                default => null,
            },
        ]);
    }
}
