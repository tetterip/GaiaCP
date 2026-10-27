{{-- A ticket's messages, oldest first, on the admin's and the client's ticket page (and a visitor's, VisitorTicket). The
     client never gets the admins' notes ($panel = 'client'). Styled here with Filament's colours, so it works with any theme or none. --}}
@php
    /** @var \App\Models\Ticket $ticket */
    $ticket = $getRecord();
    $panel = $panel ?? 'admin';
    // A visitor's ticket page links its files by the ticket's secret link instead (VisitorTicket)
    $fileUrl = $fileUrl ?? fn ($file) => route("filament.{$panel}.ticket-file", ['attachment' => $file->id]);
    $messages = $ticket->messages()->with('user', 'attachments')
        ->when($panel === 'client', fn ($query) => $query->public())
        ->get();
    $date = fn ($value) => $value?->timezone(config('app.timezone'))->format(\App\Providers\AppServiceProvider::dateFormat().' H:i');
@endphp

@once
<style>
    .gx-thread { display: flex; flex-direction: column; gap: .9rem; }
    .gx-msg { border: 1px solid var(--gray-200); border-radius: .75rem; padding: .9rem 1.1rem; background: var(--gray-50); min-width: 0; }
    .dark .gx-msg { border-color: rgba(255, 255, 255, .1); background: rgba(255, 255, 255, .03); }
    .gx-msg-staff { background: transparent; border-left: 3px solid var(--primary-500); }
    .dark .gx-msg-staff { background: transparent; border-left-color: var(--primary-400); }
    .gx-msg-note { background: var(--warning-50); border-color: var(--warning-300); border-style: dashed; }
    .dark .gx-msg-note { background: rgba(245, 158, 11, .08); border-color: rgba(245, 158, 11, .4); }
    .gx-msg-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .6rem; margin-bottom: .5rem; font-size: .85rem; }
    .gx-msg-author { font-weight: 600; }
    .gx-msg-tag { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--gray-500); }
    .gx-msg-note .gx-msg-tag { color: var(--warning-700); }
    .dark .gx-msg-note .gx-msg-tag { color: var(--warning-400); }
    .gx-msg-date { margin-left: auto; color: var(--gray-500); font-variant-numeric: tabular-nums; }
    .gx-msg-body { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.55; font-size: .92rem; }
    .gx-msg-files { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .7rem; }
    .gx-msg-file { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .6rem; border: 1px solid var(--gray-200); border-radius: 999px; font-size: .8rem; text-decoration: none; }
    .dark .gx-msg-file { border-color: rgba(255, 255, 255, .12); }
    .gx-msg-file svg { width: 1rem; height: 1rem; flex: none; }
    .gx-msg-file span { color: var(--gray-500); }
</style>
@endonce

<div class="gx-thread">
    @foreach ($messages as $message)
        <article @class(['gx-msg', 'gx-msg-staff' => ! $message->from_client && ! $message->internal, 'gx-msg-note' => $message->internal])>
            <header class="gx-msg-head">
                <span class="gx-msg-author">{{ $message->from_client ? $message->authorName() : ($panel === 'client' ? ($message->user?->name ?? config('gaiacp.company.name') ?: __('Support')) : $message->authorName()) }}</span>
                <span class="gx-msg-tag">
                    @if ($message->internal)
                        {{ __('Internal note') }}
                    @elseif ($message->from_client)
                        {{ __('Client') }}
                    @else
                        {{ __('Support') }}
                    @endif
                </span>
                <time class="gx-msg-date">{{ $date($message->created_at) }}</time>
            </header>
            <div class="gx-msg-body">{{ $message->body }}</div>
            @if ($message->attachments->isNotEmpty())
                <div class="gx-msg-files">
                    @foreach ($message->attachments as $file)
                        <a class="gx-msg-file" href="{{ $fileUrl($file) }}">
                            @svg('heroicon-o-paper-clip')
                            {{ $file->name }} <span>{{ $file->readableSize() }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </article>
    @endforeach
</div>
