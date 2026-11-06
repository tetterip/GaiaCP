<?php

namespace App\Database;

use Illuminate\Database\SQLiteConnection as Base;

/** Times sent to the database in UTC (SendsTimesInUtc). */
class SQLiteConnection extends Base
{
    use SendsTimesInUtc;
}
