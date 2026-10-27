{{-- App\Filament\Client\Pages\SignIns: the signed-in devices, then the recent sign-ins. --}}
<x-filament-panels::page>
    {{ $this->table }}

    <x-filament::section :heading="__('Recent sign-ins')" :description="__('If you don\'t recognise one, change your password and log out the other devices.')">
        @forelse ($this->recent() as $login)
            <div style="display: flex; flex-wrap: wrap; gap: .25rem 1rem; justify-content: space-between; padding: .5rem 0; @if (! $loop->last) border-bottom: 1px solid color-mix(in srgb, currentColor 12%, transparent); @endif">
                <div>
                    <div>{{ $login->device() }}</div>
                    <div style="font-size: .875rem; opacity: .7;">{{ $login->ip_address ?? '—' }}</div>
                </div>
                <div style="text-align: end;">
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
