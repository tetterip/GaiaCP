<?php

namespace App\Ai;

/** What the assistant asks an AI module: its instructions, the admin's text, and how long the answer may be. */
final readonly class Prompt
{
    public function __construct(
        public string $feature,
        public string $instructions,
        public string $text,
        public int $maxTokens = 4000,
    ) {}
}
