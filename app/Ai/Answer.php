<?php

namespace App\Ai;

/** An AI module's answer and what it cost. */
final readonly class Answer
{
    public function __construct(
        public string $text,
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
