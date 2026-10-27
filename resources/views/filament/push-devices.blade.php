{{-- Profile → Push notifications: turn them on or off on this device (App\Push\WebPush), and the devices that have them on. --}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\PushSubscription> $devices */
@endphp
<x-filament::section
    :heading="__('Push notifications')"
    :description="__('The bell\'s notifications on your phone or computer, even when GaiaCP is closed. Turn them on on each device you want them on.')"
>
    <div
        x-data="{
            state: 'loading',
            key: @js($publicKey),
            worker: @js(route('pwa.service-worker')),
            scope: @js(url('/admin').'/'),
            bytes(text) {
                const raw = atob((text + '='.repeat((4 - text.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
                return Uint8Array.from(raw, (c) => c.charCodeAt(0));
            },
            text(buffer) {
                return btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
            },
            registration() {
                return navigator.serviceWorker.register(this.worker, { scope: this.scope });
            },
            async init() {
                const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
                if (! ('serviceWorker' in navigator) || ! ('PushManager' in window) || ! ('Notification' in window)) {
                    this.state = /iPhone|iPad/.test(navigator.userAgent) && ! standalone ? 'install' : 'unsupported';
                    return;
                }
                if (Notification.permission === 'denied') {
                    this.state = 'denied';
                    return;
                }
                try {
                    const subscription = await (await this.registration()).pushManager.getSubscription();
                    if (subscription && subscription.options.applicationServerKey && this.text(subscription.options.applicationServerKey) !== this.key) {
                        await subscription.unsubscribe(); // made for keys GaiaCP no longer has
                        this.state = 'off';
                        return;
                    }
                    if (subscription) {
                        await this.$wire.savePushSubscription(subscription.toJSON());
                    }
                    this.state = subscription ? 'on' : 'off';
                } catch (e) {
                    this.state = 'off';
                }
            },
            async turnOn() {
                this.state = 'busy';
                try {
                    if (await Notification.requestPermission() !== 'granted') {
                        this.state = Notification.permission === 'denied' ? 'denied' : 'off';
                        return;
                    }
                    const subscription = await (await this.registration()).pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.bytes(this.key) });
                    await this.$wire.savePushSubscription(subscription.toJSON());
                    this.state = 'on';
                } catch (e) {
                    this.state = 'off';
                    this.$wire.pushFailed(String(e && e.message ? e.message : e));
                }
            },
            async turnOff() {
                this.state = 'busy';
                try {
                    const subscription = await (await this.registration()).pushManager.getSubscription();
                    if (subscription) {
                        await this.$wire.deletePushSubscription(subscription.endpoint);
                        await subscription.unsubscribe();
                    }
                } finally {
                    this.state = 'off';
                }
            },
        }"
        style="display: flex; flex-direction: column; gap: 0.75rem"
    >
        <p x-show="state === 'unsupported'" x-cloak style="font-size: 0.875rem">{{ __('This browser cannot receive push notifications.') }}</p>
        <p x-show="state === 'install'" x-cloak style="font-size: 0.875rem">{{ __('On an iPhone or iPad, first add GaiaCP to the home screen (Share → Add to Home Screen), open it from there and turn them on here.') }}</p>
        <p x-show="state === 'denied'" x-cloak style="font-size: 0.875rem">{{ __('Notifications are blocked for this site in the browser\'s settings. Allow them there, then reload this page.') }}</p>
        @unless ($bellOn)
            <p style="font-size: 0.875rem">{{ __('Notifications in the panel are off, so nothing is sent. Turn them on above.') }}</p>
        @endunless

        <div style="display: flex; gap: 0.5rem; overflow-x: auto; white-space: nowrap">
            <span x-show="state === 'off' || state === 'busy'" x-cloak>
                <x-filament::button icon="heroicon-o-bell-alert" x-on:click="turnOn()" x-bind:disabled="state === 'busy'">
                    {{ __('Turn on on this device') }}
                </x-filament::button>
            </span>
            <span x-show="state === 'on'" x-cloak>
                <x-filament::button color="gray" icon="heroicon-o-bell-slash" x-on:click="turnOff()">
                    {{ __('Turn off on this device') }}
                </x-filament::button>
            </span>
            @if ($devices->isNotEmpty())
                <x-filament::button color="gray" icon="heroicon-o-paper-airplane" wire:click="sendTestPush">
                    {{ __('Send a test') }}
                </x-filament::button>
            @endif
        </div>

        @if ($devices->isNotEmpty())
            <ul style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.875rem">
                @foreach ($devices as $device)
                    <li style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem">
                        <span>
                            {{ $device->device() }}
                            <span style="opacity: 0.7">· {{ __('since :date', ['date' => $device->created_at->format(\App\Providers\AppServiceProvider::dateFormat())]) }}</span>
                        </span>
                        <x-filament::link tag="button" color="danger" size="sm" wire:click="removePushDevice({{ $device->id }})">
                            {{ __('Remove') }}
                        </x-filament::link>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament::section>
