<?php

namespace App\Auth;

use App\Support\StoredTime;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;

/** Laravel's reset tokens, with created_at (stored in UTC by App\Database\SendsTimesInUtc) read as UTC. */
class UtcTokenRepository extends DatabaseTokenRepository
{
    protected function tokenExpired($createdAt)
    {
        return parent::tokenExpired(StoredTime::parse($createdAt));
    }

    protected function tokenRecentlyCreated($createdAt)
    {
        return parent::tokenRecentlyCreated(StoredTime::parse($createdAt));
    }
}
