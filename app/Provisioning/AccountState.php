<?php

namespace App\Provisioning;

/** What the panel reports about a hosting account. */
enum AccountState
{
    case Missing;
    case Active;
    case Suspended;
}
