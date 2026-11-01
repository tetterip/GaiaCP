{{-- A sign-in link was opened (QuickSignIn): nobody is logged in until Log in is pressed here. --}}
<div class="gcp-signin-pending">
    <p>{{ __('Log in as :email?', ['email' => $email]) }}</p>
    <x-filament::button wire:click="authenticate" size="lg">{{ __('Log in') }}</x-filament::button>
    <div>
        <x-filament::link tag="button" color="gray" wire:click="cancelPendingSignIn">{{ __('Use another account') }}</x-filament::link>
    </div>
</div>
