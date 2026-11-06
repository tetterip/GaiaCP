<?php

namespace App\Database;

use Illuminate\Database\MariaDbConnection as Base;

/** Times sent to the database in UTC (SendsTimesInUtc). */
class MariaDbConnection extends Base
{
    use SendsTimesInUtc;
}
