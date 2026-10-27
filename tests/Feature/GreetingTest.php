<?php

namespace Tests\Feature;

use App\Support\Greeting;
use Tests\TestCase;

class GreetingTest extends TestCase
{
    public function test_it_greets_by_the_time_of_day(): void
    {
        $this->travelTo('2026-10-19 09:00');
        $this->assertSame('Good morning, Jayden', Greeting::for('Jayden'));

        $this->travelTo('2026-10-19 13:00');
        $this->assertSame('Good afternoon, Jayden', Greeting::for('Jayden'));

        $this->travelTo('2026-10-19 21:00');
        $this->assertSame('Good evening, Jayden', Greeting::for('Jayden'));

        $this->travelTo('2026-10-19 03:00');
        $this->assertSame('Good evening, Jayden', Greeting::for('Jayden'));
    }
}
