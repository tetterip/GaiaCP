<?php

namespace App\Auth;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * The users provider of both panels: Laravel's own, plus a login imported from another system (WHMCS) whose old
 * password hash is still in users.imported_password. The first login with that old password stores it with GaiaCP's
 * hash and empties imported_password, so the old hash is checked at most once per user. Rate limits, captcha and
 * banned IPs are the login page's, as for any other login.
 */
class UserProvider extends EloquentUserProvider
{
    /** @param  array<string, mixed>  $credentials */
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        if (parent::validateCredentials($user, $credentials)) {
            return true;
        }

        $password = $credentials['password'] ?? null;

        if (! $user instanceof User || ! is_string($password) || blank($user->imported_password)
            || ! ImportedPassword::check($password, $user->imported_password)) {
            return false;
        }

        $user->password = $password; // hashed by the model's cast; saving empties imported_password (User::booted())
        $user->save();
        ActivityLog::record('user.imported_password_replaced', "First login of {$user->email} with their imported password: stored with GaiaCP's hash", client: $user->client);

        return true;
    }
}
