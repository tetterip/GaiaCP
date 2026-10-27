<?php

namespace App\Support;

/** "Good morning / afternoon / evening, :name" by the hour, the dashboards' heading (admin and client). */
final class Greeting
{
    public static function for(string $name): string
    {
        $hour = now()->hour;

        return match (true) {
            $hour >= 5 && $hour < 12 => __('Good morning, :name', ['name' => $name]),
            $hour >= 12 && $hour < 18 => __('Good afternoon, :name', ['name' => $name]),
            default => __('Good evening, :name', ['name' => $name]),
        };
    }
}
