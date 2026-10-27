{{-- ClientAreaMaintenance: what visitors see on every client area page while maintenance mode is on (Settings → Client area). --}}
@extends('client-area.page')

@section('title', __('Client area under maintenance'))

@section('card')
    <h1>{{ __('Client area under maintenance') }}</h1>
    <p>{!! nl2br(e($message)) !!}</p>
@endsection
