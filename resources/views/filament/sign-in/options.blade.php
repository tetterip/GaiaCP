{{-- Under the login form (QuickSignIn): the sign-in plugins' buttons, a passkey (App\SignIn\Passkeys) and a link by
     email (App\SignIn\EmailLinks). Styled by filament.themes.client and filament.sign-in.admin-styles. --}}
@php
    use App\SignIn\SignIn;
@endphp
@if ($buttons !== [] || $passkeys || $emailLink)
    <div class="gcp-signin">
        <div class="gcp-signin-or">{{ __('or') }}</div>
        @foreach ($buttons as $button)
            {!! $button->toHtml() !!}
        @endforeach
        @if ($passkeys)
            <button
                type="button"
                class="gcp-signin-button"
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
                    async logIn() {
                        this.busy = true;
                        try {
                            const options = await this.$wire.passkeyOptions();
                            const credential = await navigator.credentials.get({ publicKey: { ...options, challenge: this.bytes(options.challenge) } });
                            await this.$wire.passkeyLogin({
                                id: credential.id,
                                type: credential.type,
                                response: {
                                    clientDataJSON: this.text(credential.response.clientDataJSON),
                                    authenticatorData: this.text(credential.response.authenticatorData),
                                    signature: this.text(credential.response.signature),
                                    userHandle: credential.response.userHandle ? this.text(credential.response.userHandle) : null,
                                },
                            });
                        } catch (e) {
                            this.$wire.passkeyFailed(e && e.name ? e.name : 'Error');
                        } finally {
                            this.busy = false;
                        }
                    },
                }"
                x-show="supported"
                x-cloak
                x-on:click="logIn()"
                x-bind:disabled="busy"
            >
                {!! svg('heroicon-o-finger-print')->toHtml() !!}
                <span>{{ __('Log in with a passkey') }}</span>
                @include('filament.sign-in.last-used', ['method' => SignIn::PASSKEY])
            </button>
        @endif
        @if ($emailLink)
            <button type="button" class="gcp-signin-button" wire:click="mountAction('emailLink')">
                {!! svg('heroicon-o-envelope')->toHtml() !!}
                <span>{{ __('Email me a sign-in link') }}</span>
                @include('filament.sign-in.last-used', ['method' => SignIn::EMAIL_LINK])
            </button>
        @endif
    </div>
@endif
