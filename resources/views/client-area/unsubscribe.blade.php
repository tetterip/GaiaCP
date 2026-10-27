{{-- UnsubscribeController: the link at the end of an informational message to clients. --}}
@extends('client-area.page')

@section('title', __('Informational emails'))

@section('card')
    <h1>{{ __('Informational emails') }}</h1>
    @if ($done)
        <p>{{ __('Done: :email will not receive informational emails from us. Emails about your services, payment notices and reminders still arrive.', ['email' => $client->email]) }}</p>
    @else
        <p>{{ __('Stop news and offers to :email? Emails about your services, payment notices and reminders still arrive.', ['email' => $client->email]) }}</p>
        <form method="post" action="{{ $action }}">
            @csrf
            <button type="submit">{{ __('Stop informational emails') }}</button>
        </form>
    @endif
@endsection
