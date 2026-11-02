{{-- Profile → Passkeys (ManagesPasskeys, App\SignIn\Passkeys): add one on this device, and the ones added before. --}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Passkey> $passkeys */
    $dateFormat = \App\Providers\AppServiceProvider::dateFormat();
@endphp
<x-filament::section
    :heading="__('Passkeys')"
    :description="__('Log in with your fingerprint, face or screen lock instead of your password. A passkey stays on your device (or in your Google or Apple account) and works only on this site.')"
>
    <div
        x-data="{
            supported: !! window.PublicKeyCredential,
            busy: false,
            bytes(text) {
                const raw = atob((text + '='.repeat((4 - text.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
                return Uint8Array.from(raw, (c) => c.charCodeAt(0));
            },
            text(buffer) {
                return btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
            },
            async add() {
                this.busy = true;
                try {
                    const options = await this.$wire.passkeyCreationOptions();
                    if (options.confirm) {
                        return; // the password first; passkey-confirmed comes back here
                    }
                    options.challenge = this.bytes(options.challenge);
                    options.user.id = this.bytes(options.user.id);
                    options.excludeCredentials = options.excludeCredentials.map((c) => ({ ...c, id: this.bytes(c.id) }));
                    const credential = await navigator.credentials.create({ publicKey: options });
                    await this.$wire.addPasskey({
                        id: credential.id,
                        type: credential.type,
                        response: {
                            clientDataJSON: this.text(credential.response.clientDataJSON),
                            attestationObject: this.text(credential.response.attestationObject),
                        },
                    });
                } catch (e) {
                    this.$wire.passkeyNotAdded(e && e.name ? e.name : 'Error');
                } finally {
                    this.busy = false;
                }
            },
        }"
        x-on:passkey-confirmed.window="add()"
        style="display: flex; flex-direction: column; gap: 0.75rem"
    >
        <p x-show="! supported" x-cloak style="font-size: 0.875rem">{{ __('This browser cannot use passkeys.') }}</p>

        <div x-show="supported" x-cloak>
            <x-filament::button icon="heroicon-o-finger-print" x-on:click="add()" x-bind:disabled="busy">
                {{ __('Add a passkey on this device') }}
            </x-filament::button>
        </div>

        @if ($passkeys->isNotEmpty())
            <ul style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.875rem">
                @foreach ($passkeys as $passkey)
                    <li style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem">
                        <span>
                            {{ $passkey->name }}
                            <span style="opacity: 0.7">
                                · {{ __('added :date', ['date' => $passkey->created_at->format($dateFormat)]) }}
                                @if ($passkey->last_used_at)
                                    · {{ __('last used :date', ['date' => $passkey->last_used_at->format($dateFormat)]) }}
                                @endif
                            </span>
                        </span>
                        <x-filament::link
                            tag="button"
                            color="danger"
                            size="sm"
                            wire:click="removePasskey({{ $passkey->id }})"
                            wire:confirm="{{ __('Remove this passkey? It stops working here; delete it from your device or password manager too.') }}"
                        >
                            {{ __('Remove') }}
                        </x-filament::link>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament::section>
