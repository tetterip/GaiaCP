<?php

namespace App\Support\Health;

/** One line of the System health page: what was checked, what was found and, when it is not OK, what to do. */
final readonly class Check
{
    public function __construct(
        public string $group,
        public string $name,
        public Status $status,
        public string $value,
        public ?string $hint = null,
    ) {}
}
