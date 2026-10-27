@extends('install.layout', ['step' => 'admin'])

@section('content')
    <h1>{{ __('Business and admin') }}</h1>
    <p class="lead">{{ __('The tables are ready. Last, your business and the admin you log in with.') }}</p>

    <form method="post" action="{{ route('install.admin') }}">
        @csrf
        <fieldset>
            <legend>{{ __('Business') }}</legend>
            <label for="company">{{ __('Business name') }}</label>
            <input id="company" name="company" required value="{{ old('company') }}">
            <label for="company_email">{{ __('Business email') }}</label>
            <input id="company_email" name="company_email" type="email" value="{{ old('company_email') }}">
            <div class="hint">{{ __('Client emails are sent from it. You can change both later under Settings → Company.') }}</div>
            <label for="url">{{ __('Panel address') }}</label>
            <input id="url" name="url" type="url" value="{{ old('url', $url) }}">
        </fieldset>

        <fieldset>
            <legend>{{ __('Region') }}</legend>
            <label for="timezone">{{ __('Timezone') }}</label>
            <select id="timezone" name="timezone" required data-picked="{{ old('timezone') ? 1 : 0 }}">
                @foreach ($timezones as $zone => $label)
                    <option value="{{ $zone }}" @selected(old('timezone', 'UTC') === $zone)>{{ $label }}</option>
                @endforeach
            </select>
            <label for="date_format">{{ __('Date format') }}</label>
            <select id="date_format" name="date_format" required>
                @foreach ($dateFormats as $format => $example)
                    <option value="{{ $format }}" @selected(old('date_format', 'd/m/Y') === $format)>{{ $example }}</option>
                @endforeach
            </select>
            <label for="country">{{ __('Country') }}</label>
            <select id="country" name="country" data-picked="{{ old('country') !== null ? 1 : 0 }}">
                <option value="">{{ __('None') }}</option>
                @foreach ($countries as $code => $name)
                    <option value="{{ $code }}" @selected(old('country') === $code)>{{ $name }}</option>
                @endforeach
            </select>
            @unless ($hasAdmins)
                <label for="currency">{{ __('Base currency') }}</label>
                <select id="currency" name="currency" required>
                    @foreach ($currencies as $code => $name)
                        <option value="{{ $code }}" @selected(old('currency', $currency) === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            @endunless
            <div class="hint">{{ __('New clients start in the country. Prices are entered in the base currency, which can\'t change once there are prices; more currencies can be added under Settings → Currencies. The rest can be changed later under Settings → General.') }}</div>
        </fieldset>
        <script>
            (() => {
                const select = document.getElementById('timezone');
                const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
                if (select.dataset.picked === '0' && zone && [...select.options].some((option) => option.value === zone)) {
                    select.value = zone; // the browser's own timezone
                }
                const country = document.getElementById('country');
                const region = (() => { try { return new Intl.Locale(navigator.language).maximize().region; } catch { return null; } })();
                if (country.dataset.picked === '0' && region && [...country.options].some((option) => option.value === region)) {
                    country.value = region; // the browser's language's country
                }
            })();
        </script>

        @if ($hasAdmins)
            <p class="hint">{{ __('This database already has admins: log in with one of them.') }}</p>
        @else
            <fieldset>
                <legend>{{ __('Your admin login') }}</legend>
                <label for="name">{{ __('Name') }}</label>
                <input id="name" name="name" required autocomplete="name" value="{{ old('name') }}">
                <label for="email">{{ __('Email') }}</label>
                <input id="email" name="email" type="email" required autocomplete="username" value="{{ old('email') }}">
                <label for="password">{{ __('Password') }}</label>
                <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
                <label for="password_confirmation">{{ __('Confirm password') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password">
            </fieldset>
        @endif

        <div class="actions"><button>{{ __('Finish installing') }}</button></div>
    </form>
@endsection
