<?php

namespace App\Actions;

use App\Enums\AdminArea;
use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Mail\ClientMailer;
use App\Mail\NewClientMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\User;
use App\Support\ClientFields;
use App\Support\Locales;
use App\Support\TermsOfService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

/**
 * Sign up in the client area (Settings → Client area → New clients can sign up): a new client with its own login.
 * With a password, the login must confirm its email address (Email Address Verification) before it opens anything;
 * through a sign-in provider such as Google, which has confirmed the address already, it is confirmed at once.
 * The admins get the New client email. An address that is already a client or a login here can't sign up.
 */
class ClientSignup
{
    /** The client fields the form fills in. */
    public const FIELDS = [
        'first_name', 'last_name', 'company', 'email', 'phone',
        'address1', 'address2', 'city', 'state', 'postcode', 'country',
        'vat_number', 'tax_office', 'profession', 'custom_fields',
    ];

    /** Session key: a sign-in provider's confirmed address waiting for the rest of the sign up form (provider, email, names). */
    public const PENDING = 'client_signup.pending';

    public function __construct(private ClientMailer $mailer) {}

    /**
     * Sends someone a sign-in provider confirmed, but who is not a client here yet, to the sign up form with their
     * address fixed and their name filled in.
     *
     * @param  array{email: string, first_name?: string|null, last_name?: string|null}  $profile
     */
    public static function continueWith(string $provider, array $profile): void
    {
        session()->put(self::PENDING, ['provider' => $provider] + $profile);
    }

    /** @return array{provider: string, email: string, first_name?: string|null, last_name?: string|null}|null */
    public static function pending(): ?array
    {
        $pending = session(self::PENDING);

        return is_array($pending) && filled($pending['email'] ?? null) && filled($pending['provider'] ?? null) ? $pending : null;
    }

    public static function enabled(): bool
    {
        return (bool) config('gaiacp.client_area.signup');
    }

    /** Whether a client or a login (admin or client) already uses this address. */
    public static function emailTaken(string $email): bool
    {
        $email = Str::lower(trim($email));

        return Client::query()->whereRaw('lower(email) = ?', [$email])->exists()
            || User::query()->whereRaw('lower(email) = ?', [$email])->exists();
    }

    /**
     * @param  array<string, mixed>  $details  the FIELDS
     * @param  string|null  $password  null: a random one (they log in through the provider, or set one with "Forgot password?")
     * @param  string|null  $provider  the sign-in provider that confirmed the email address (e.g. Google), null = not confirmed yet
     * @param  bool  $acceptedTerms  ticked "I accept the Terms of Service" (TermsOfService), refused without it while they are asked for
     */
    public function register(array $details, #[SensitiveParameter] ?string $password, ?string $provider = null, bool $acceptedTerms = false): User
    {
        if (TermsOfService::required() && ! $acceptedTerms) {
            throw ValidationException::withMessages(['accept_terms' => __('Please accept the Terms of Service to continue.')]);
        }

        $terms = TermsOfService::required() ? TermsOfService::acceptance() : [];

        $details = array_intersect_key($details, array_flip(self::FIELDS));
        $details['email'] = Str::lower(trim((string) $details['email']));
        $details['custom_fields'] = ClientFields::clean(ClientFields::editable(), $details['custom_fields'] ?? []) ?: null; // only the ones clients fill in

        $user = DB::transaction(function () use ($details, $password, $provider, $terms): User {
            $client = (new Client($details + ['locale' => Locales::multiple() ? app()->getLocale() : null]))->forceFill($terms);
            $client->save();

            $user = new User([
                'name' => $client->name,
                'email' => $client->email,
                'role' => UserRole::Client,
                'client_id' => $client->id,
                'locale' => Locales::multiple() ? app()->getLocale() : null,
                'password' => $password ?? Str::password(32),
            ]);
            $user->email_verified_at = $provider !== null ? now() : null;
            $user->save();

            ActivityLog::record('client.signed_up', "{$client->name} ({$client->email}) signed up in the client area".($provider ? " with {$provider}" : ''), client: $client, properties: array_filter(['provider' => $provider, 'terms_version' => $client->terms_version]));

            return $user;
        });

        $this->notifyAdmins($user->client, $provider);

        return $user;
    }

    /** The Email Address Verification email with a signed link of the client area (Filament's verify route). */
    public function sendVerification(User $user): bool
    {
        if ($user->client === null) {
            return false;
        }

        return $this->mailer->send(MailTemplate::EmailVerification, $user->client, [
            'verification_url' => Filament::getPanel('client')->getVerifyEmailUrl($user),
        ], evenIfOff: true);
    }

    /** Settings → Automation → New client email, to the admins with Receive system emails on who open clients. */
    private function notifyAdmins(Client $client, ?string $provider): void
    {
        if (! config('gaiacp.automation.new_client_email')) {
            return;
        }

        $admins = User::query()->admins()->where('active', true)->where('system_emails', true)->get()
            ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Clients));

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->locale(Locales::normalize($admin->locale))->send(new NewClientMail($client, $provider));
            } catch (Throwable $e) {
                Log::error('New client email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "New client email to {$admin->email} failed: {$e->getMessage()}", client: $client);
            }
        }
    }
}
