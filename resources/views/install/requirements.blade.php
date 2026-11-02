@extends('install.layout', ['step' => 'requirements'])

@section('content')
    <h1>{{ __('Install GaiaCP') }}</h1>
    <p class="lead">{{ __('First, a check that this server can run GaiaCP.') }}</p>

    <table>
        @foreach ($checks as $check)
            <tr>
                <td class="state {{ match ($check->status) { \App\Support\Health\Status::Error => 'err', \App\Support\Health\Status::Warning => 'warn', default => 'ok' } }}">
                    {{ match ($check->status) { \App\Support\Health\Status::Error => '✕', \App\Support\Health\Status::Warning => '!', default => '✓' } }}
                </td>
                <td>
                    {{ $check->name }}: {{ $check->value }}
                    @if ($check->hint && $check->status !== \App\Support\Health\Status::Ok)
                        <div class="hint">{{ $check->hint }}</div>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    <p class="hint">{!! __('GaiaCP is free software under the :licence and comes with no warranty: you use it at your own risk, and its authors are not liable for any loss or damage it causes, such as wrong amounts, missed reminders or changes on your servers. Check what it does and keep backups.', ['licence' => '<a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank" rel="noopener">'.e(__('GNU General Public License, version 3 or later')).'</a>']) !!}</p>

    @if ($ready && ! $unlocked)
        @if ($codeError)
            <div class="errors">{{ $codeError }}</div>
        @endif
        <form method="post" action="{{ route('install.unlock') }}">
            @csrf
            <label for="code">{{ __('Setup code') }}</label>
            <input id="code" name="code" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
            <p class="hint">{{ __('To show that this is your site, open :file on the server (in your hosting panel\'s File Manager or by FTP) and copy the code in it. Anyone can open this page until GaiaCP is installed; only you can read that file.', ['file' => 'storage/app/private/install-code.txt']) }}</p>
            <div class="actions">
                <button type="submit">{{ __('Next') }}</button>
            </div>
        </form>
    @else
        <div class="actions">
            @if ($ready)
                <a class="button" href="{{ route('install.database') }}">{{ __('Next') }}</a>
            @else
                <button disabled>{{ __('Fix the problems above, then reload this page') }}</button>
            @endif
        </div>
    @endif
@endsection
