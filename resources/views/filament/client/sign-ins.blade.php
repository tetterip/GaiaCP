{{-- App\Filament\Client\Pages\SignIns: the signed-in devices, then the recent sign-ins. --}}
<x-filament-panels::page>
    {{ $this->table }}

    <x-filament::section :heading="__('Recent sign-ins')" :description="__('If you don\'t recognise one, change your password and log out the other devices.')">
        @forelse ($this->recent() as $login)
            <div style="display: flex; gap: 1rem; justify-content: space-between; align-items: flex-start; padding: .5rem 0; @if (! $loop->last) border-bottom: 1px solid color-mix(in srgb, currentColor 12%, transparent); @endif">
                <div style="min-width: 0;">
                    <div>{{ $login->device() }}</div>
                    {{-- a long IPv6 address wraps here, so the date and badge stay on the right as for IPv4 --}}
                    <div style="font-size: .875rem; opacity: .7; overflow-wrap: anywhere;">{{ $login->ip_address ?? '—' }}</div>
                </div>
                <div style="text-align: end; flex-shrink: 0; white-space: nowrap;">
                    <div>{{ $login->created_at->format(\App\Providers\AppServiceProvider::dateFormat().' H:i') }}</div>
                    <x-filament::badge :color="$login->succeeded ? 'success' : 'danger'" style="display: inline-flex;">
                        {{ $login->succeeded ? __('Signed in') : __('Wrong password') }}
                    </x-filament::badge>
                </div>
            </div>
        @empty
            <p style="opacity: .7;">{{ __('No sign-ins yet.') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
