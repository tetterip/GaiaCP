<x-admin-mail :message="$message ?? null">
@if (! $result->created())
# {{ __('Backup failed') }}

{{ __('No backup was made: :error', ['error' => $result->errors['backup']]) }}

{{ __('Run `php artisan gaiacp:backup` on the server to see the error, or use Back up now under Settings → Backups.') }}
@else
# {{ __('Database backup') }}

**{{ $file }}** ({{ $result->sizeForHumans() }}){{ $encrypted ? ', '.__('protected with the zip password from Settings → Backups') : '' }}.

@if ($attached)
{{ __('The zip is attached.') }}
@else
{{ __('The zip is too big to attach (:size at most).', ['size' => \Illuminate\Support\Number::fileSize(\App\Backups\RunBackup::MAX_ATTACHMENT_BYTES)]) }} {{ in_array('FTP', $result->copies) ? __('It is kept on the server and on the FTP server.') : __('It is kept on the server.') }}
@endif

@foreach ($result->errors as $copy => $error)
**{{ $copy === 'ftp' ? __('FTP copy failed') : __('Failed') }}:** {{ $error }}

@endforeach
@endif
</x-admin-mail>
