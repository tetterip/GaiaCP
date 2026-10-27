<?php

namespace App\Ai;

/**
 * An AI service the assistant can ask (a plugin such as Claude), with the admin's own API key. It only turns one
 * Prompt into one Answer: App\Ai\Assistant decides what may be sent, checks the switches and the monthly limit and
 * keeps the usage. The HTTP client adds ModuleLogger::middleware() with the key among the secrets.
 */
interface AiModule
{
    /** Stable key, stored in `ai.provider` and `ai_usages.provider`. */
    public function aiKey(): string;

    /** Its name in the panel, e.g. Claude. */
    public function aiLabel(): string;

    /** Whether it has what it needs to answer (an API key). */
    public function aiConfigured(): bool;

    /** @throws AiException with a message for the admin when the service can't be reached or refuses */
    public function complete(Prompt $prompt): Answer;
}
