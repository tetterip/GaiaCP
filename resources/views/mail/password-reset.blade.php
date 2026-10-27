{{-- The Forgot password? email: the company's look for the client area, GaiaCP's for /admin. Not indented: Markdown. --}}
@if ($client)
<x-client-mail :message="$message ?? null">
@include('mail.partials.password-reset-text')
</x-client-mail>
@else
<x-admin-mail :message="$message ?? null">
@include('mail.partials.password-reset-text')
</x-admin-mail>
@endif
