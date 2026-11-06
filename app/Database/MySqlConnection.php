<?php

namespace App\Database;

use Illuminate\Database\MySqlConnection as Base;

/** Times sent to the database in UTC (SendsTimesInUtc). */
class MySqlConnection extends Base
{
    use SendsTimesInUtc;
}
