<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Concerns\KeepsDemoLogin;
use App\Filament\Concerns\ManagesPasskeys;
use App\Models\PushSubscription;
use App\Push\WebPush;
use App\Support\Locales;
use App\Themes\Themes;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;

/**
 * Filament's profile page plus the admin's own panel language and theme (each shown once there is more than one), their
 * bell, push notifications on their devices (App\Push\WebPush) and passkeys (ManagesPasskeys).
 */
class EditProfile extends BaseEditProfile
{
    use KeepsDemoLogin;
    use ManagesPasskeys;

    /** On the right like every other form's (BasePage::alignFormActionsEnd()); Filament pins the profile's to the left. */
    public function getFormActionsAlignment(): string|Alignment
    {
        return static::$formActionsAlignment;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getLocaleFormComponent(),
                $this->getThemeFormComponent(),
                $this->getNotificationsFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                $this->getPushFormComponent(),
                $this->getPasskeysFormComponent(),
            ]);
    }

    protected function getLocaleFormComponent(): Component
    {
        return Select::make('locale')
            ->label('Panel language')
            ->options(Locales::available())
            ->placeholder(fn () => __('Default (:language)', ['language' => Locales::available()[Locales::default()]]))
            ->visible(Locales::multiple());
    }

    protected function getThemeFormComponent(): Component
    {
        return Select::make('theme')
            ->label('Panel theme')
            ->options(fn () => Themes::options())
            ->placeholder(fn () => __('Default (:theme)', ['theme' => Themes::default()?->themeLabel()]))
            ->visible(fn () => count(Themes::options()) > 1);
    }

    protected function getNotificationsFormComponent(): Component
    {
        return Toggle::make('panel_notifications')
            ->label('Notifications in the panel')
            ->helperText(__('The bell in the top bar: new orders and sign-ups, new payment notices, online payments, failed panel or registrar calls, domains about to expire and similar.'));
    }

    protected function getPushFormComponent(): Component
    {
        return View::make('filament.push-devices')
            ->viewData(fn () => [
                'publicKey' => app(WebPush::class)->publicKey(),
                'devices' => $this->getUser()->pushSubscriptions()->latest()->get(),
                'bellOn' => (bool) $this->getUser()->panel_notifications,
            ]);
    }

    /**
     * This browser's push subscription (PushSubscription.toJSON()). Only the browsers' own push services are accepted,
     * so GaiaCP never posts to an address an admin made up.
     *
     * @param  array<string, mixed>  $subscription
     */
    public function savePushSubscription(array $subscription): void
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        $key = (string) ($subscription['keys']['p256dh'] ?? '');
        $auth = (string) ($subscription['keys']['auth'] ?? '');

        if (! WebPush::isPushService($endpoint) || strlen(WebPush::decode($key)) !== 65 || strlen(WebPush::decode($auth)) < 16) {
            $this->pushFailed(__('Not a push subscription this browser can use'));

            return;
        }

        PushSubscription::query()->updateOrCreate(['endpoint_hash' => hash('sha256', $endpoint)], [
            'user_id' => $this->getUser()->getKey(), // a shared browser belongs to whoever turned it on last
            'endpoint' => $endpoint,
            'public_key' => $key,
            'auth_token' => $auth,
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
        ]);
    }

    public function deletePushSubscription(string $endpoint): void
    {
        $this->getUser()->pushSubscriptions()->where('endpoint_hash', hash('sha256', $endpoint))->delete();
    }

    public function removePushDevice(int $id): void
    {
        $this->getUser()->pushSubscriptions()->whereKey($id)->delete();
    }

    public function sendTestPush(): void
    {
        $sent = rescue(fn () => app(WebPush::class)->sendTo($this->getUser(), [
            'title' => __('Test notification from GaiaCP'),
            'body' => __('If you see this, push notifications work on this device.'),
            'url' => static::getUrl(),
            'tag' => 'gaiacp-test',
        ]), 0);

        $sent > 0
            ? Notification::make()->success()->title(trans_choice('{1} Sent to :count device|[2,*] Sent to :count devices', $sent, ['count' => $sent]))->send()
            : Notification::make()->danger()->title(__('No device took it'))->body(__('The module log (Settings → Logging) has what the push service answered.'))->send();
    }

    public function pushFailed(string $error): void
    {
        Notification::make()->danger()->title(__('Push notifications could not be turned on'))->body(mb_substr($error, 0, 300))->send();
    }

    protected function getRedirectUrl(): ?string
    {
        // Reload so a new language, theme or the bell shows on the whole page, not only on the parts that re-render.
        return $this->getUser()->wasChanged(['locale', 'theme', 'panel_notifications']) ? static::getUrl() : parent::getRedirectUrl();
    }
}
