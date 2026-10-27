@php /** @var \App\Models\EmailLog $entry */ @endphp
<div style="display: grid; gap: .75rem;">
    <div style="font-size: .875rem; opacity: .75;">
        {{ $entry->created_at->format(\App\Providers\AppServiceProvider::dateFormat().' H:i') }} · {{ __('To') }} {{ $entry->to }}
    </div>

    @if ($entry->template?->holdsSecrets())
        <p>{{ __('This email had a password or a sign-in link, so its text is not shown here. If you need a new one, use "Forgot password?" on the login page or contact us.') }}</p>
    @elseif (filled($entry->body))
        {{-- Sandboxed: the email's own HTML, without scripts; links open in a new tab. --}}
        <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ preg_replace('~<head([^>]*)>~i', '<head$1><base target="_blank">', $entry->body, 1) }}"
            title="{{ $entry->subject }}" style="width: 100%; height: 65vh; border: 1px solid rgb(128 128 128 / .25); border-radius: .5rem; background: #fff;"></iframe>
    @else
        <p>{{ __('This email has no text.') }}</p>
    @endif
</div>
