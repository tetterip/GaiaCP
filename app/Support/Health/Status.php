<?php

namespace App\Support\Health;

enum Status: string
{
    case Ok = 'ok';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::Error => 'Problem',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Info => 'gray',
            self::Warning => 'warning',
            self::Error => 'danger',
        };
    }
}
