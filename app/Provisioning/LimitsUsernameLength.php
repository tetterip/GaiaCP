<?php

namespace App\Provisioning;

/**
 * A ServerModule whose panel takes shorter usernames than UsernameGenerator::MAX_LENGTH (DirectAdmin: 10 by default).
 * UsernameGenerator and the service form keep generated and typed usernames within it.
 */
interface LimitsUsernameLength
{
    public function maxUsernameLength(): int;
}
