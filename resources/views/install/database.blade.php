@extends('install.layout', ['step' => 'database'])

@section('content')
    <h1>{{ __('Database') }}</h1>
    <p class="lead">{{ __('Create an empty database and a user for it in your hosting panel, then enter them here. GaiaCP creates its tables.') }}</p>

    @php($driver = old('driver', array_key_exists($env['DB_CONNECTION'] ?? '', $drivers) && ($env['DB_CONNECTION'] ?? '') !== 'sqlite' ? $env['DB_CONNECTION'] : array_key_first($drivers)))

    <form method="post" action="{{ route('install.database') }}">
        @csrf
        <label for="driver">{{ __('Type') }}</label>
        <select id="driver" name="driver" onchange="document.getElementById('server').hidden = this.value === 'sqlite'; document.getElementById('file').hidden = this.value !== 'sqlite'">
            @foreach ($drivers as $value => $label)
                <option value="{{ $value }}" @selected($driver === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <div id="server" @if ($driver === 'sqlite') hidden @endif>
            <div class="row">
                <div>
                    <label for="host">{{ __('Host') }}</label>
                    <input id="host" name="host" value="{{ old('host', $env['DB_HOST'] ?? 'localhost') }}">
                </div>
                <div>
                    <label for="port">{{ __('Port') }}</label>
                    <input id="port" name="port" inputmode="numeric" value="{{ old('port', $env['DB_PORT'] ?? '3306') }}">
                </div>
            </div>
            <label for="database">{{ __('Database name') }}</label>
            <input id="database" name="database" value="{{ old('database', ($env['DB_CONNECTION'] ?? '') === 'mysql' ? ($env['DB_DATABASE'] ?? '') : '') }}">
            <label for="username">{{ __('Database user') }}</label>
            <input id="username" name="username" autocomplete="off" value="{{ old('username', $env['DB_USERNAME'] ?? '') }}">
            <label for="password">{{ __('Database password') }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password">
        </div>

        <div id="file" @if ($driver !== 'sqlite') hidden @endif>
            <p class="hint">{{ __('SQLite keeps everything in one file, database/database.sqlite. Fine for trying GaiaCP; use MySQL / MariaDB for real use.') }}</p>
        </div>

        <div class="actions"><button>{{ __('Connect and create the tables') }}</button></div>
    </form>
@endsection
