<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Actions\ClientAreaAccess;
use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Filament\Resources\Clients\ClientResource;
use App\Mail\ClientMailer;
use App\Models\User;
use App\Support\ClientFields;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = array_filter(ClientFields::clean(ClientFields::all(), $data['custom_fields']), fn ($value) => $value !== null) ?: null;
        }

        return $data;
    }

    protected function beforeCreate(): void
    {
        // A client user (or an admin already linked to a client) with this email can't be this client's login too.
        if (filled($this->data['client_area_password'] ?? null) && User::query()->where('email', $this->data['email'] ?? '')
            ->where(fn ($q) => $q->where('role', UserRole::Client)->orWhereNotNull('client_id'))->exists()) {
            throw ValidationException::withMessages([
                'data.email' => __('Another user already logs in with :email.', ['email' => $this->data['email']]),
            ]);
        }
    }

    protected function afterCreate(): void
    {
        $password = $this->data['client_area_password'] ?? null;

        if (filled($password)) {
            try {
                $user = app(ClientAreaAccess::class)->createLogin($this->record, $password, (bool) ($this->data['send_login_email'] ?? false));
            } catch (RuntimeException $e) {
                Notification::make()->warning()->title(__('The client is saved, but without a client area login'))->body($e->getMessage())->send();

                return;
            }

            if ($user->role !== UserRole::Client) {
                Notification::make()->success()->title(__('Linked to the admin user :email', ['email' => $user->email]))->send();
            }

            return;
        }

        if ($this->data['send_signup_email'] ?? false) {
            app(ClientMailer::class)->send(MailTemplate::ClientSignup, $this->record, evenIfOff: true);
        }
    }
}
