@php /** @var \App\Models\EmailLog $entry */ @endphp
@include('filament.logs.styles')
<div class="gaiacp-log">
    <div class="gaiacp-log-meta">
        {{ $entry->created_at->format(\App\Providers\AppServiceProvider::dateFormat().' H:i:s') }}
        · {{ __('To') }} {{ $entry->to }}
        @if ($entry->cc) · Cc {{ $entry->cc }} @endif
        @if ($entry->bcc) · Bcc {{ $entry->bcc }} @endif
        @if ($entry->template) · {{ $entry->template->label() }} @endif
    </div>

    @if ($entry->error)
        <div class="gaiacp-log-error">{{ __('Not sent: :error', ['error' => $entry->error]) }}</div>
    @endif

    @if (filled($entry->body))
        {{-- Sandboxed: the email's own HTML, without scripts. --}}
        <iframe sandbox srcdoc="{{ $entry->body }}"></iframe>
    @elseif (! $entry->error)
        <div class="gaiacp-log-meta">{{ __('The text of this email is not kept: its link logs in whoever opens it.') }}</div>
    @endif
</div>
