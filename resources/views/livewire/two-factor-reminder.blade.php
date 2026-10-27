<div>
    @unless ($dismissed)
        {{-- No custom theme is compiled, so the notice carries its own CSS on Filament's color variables. --}}
        <style>
            .gcp-2fa { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem; margin: 1.5rem 0 0; padding: .875rem 1rem; border-radius: .75rem; border: 1px solid var(--primary-200); background: var(--primary-50); }
            .gcp-2fa-text { flex: 1 1 18rem; font-size: .875rem; color: var(--gray-700); }
            .gcp-2fa-text strong { display: block; color: var(--gray-950); font-weight: 600; }
            .gcp-2fa-actions { display: flex; gap: .5rem; align-items: center; }
            .dark .gcp-2fa { border-color: rgb(255 255 255 / .1); background: rgb(255 255 255 / .05); }
            .dark .gcp-2fa-text { color: var(--gray-300); }
            .dark .gcp-2fa-text strong { color: #fff; }
        </style>

        <div class="gcp-2fa" role="status">
            <x-filament::icon icon="heroicon-o-shield-check" class="h-6 w-6" style="color: var(--primary-600)" />
            <div class="gcp-2fa-text">
                @if ($client)
                    <strong>{{ __('Protect your account with two-factor authentication') }}</strong>
                    {{ __('After your password you also enter a code from an app on your phone, so nobody else can log in with your password alone.') }}
                @else
                    <strong>{{ __('Turn on two-factor authentication') }}</strong>
                    {{ __('After your password you also enter a code from an app on your phone, so a stolen password alone can\'t open the panel.') }}
                @endif
            </div>
            <div class="gcp-2fa-actions">
                <x-filament::button tag="a" :href="$profileUrl" size="sm">
                    {{ __('Set it up') }}
                </x-filament::button>
                <x-filament::button color="gray" size="sm" outlined wire:click="dismiss">
                    {{ __('Not now') }}
                </x-filament::button>
            </div>
        </div>
    @endunless
</div>
