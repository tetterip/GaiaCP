<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Notifications\DatabaseNotification as Base;

/** A bell notification (User::notifications()), with its times stored in UTC like every other model. */
class DatabaseNotification extends Base
{
    use StoresTimesInUtc;
}
