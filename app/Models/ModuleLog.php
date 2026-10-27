<?php

namespace App\Models;

use App\Plugins\PluginManager;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One request to a panel, registrar or ΑΑΔΕ and what came back, secrets masked. Written by App\Support\ModuleLogger. */
#[Fillable(['module', 'action', 'url', 'request', 'status', 'response', 'error', 'duration_ms', 'created_at'])]
class ModuleLog extends Model
{
    public const UPDATED_AT = null;

    /** @return array<string, string> module key → label, for the filter and the badge (each plugin's moduleLogs()) */
    public static function modules(): array
    {
        return ['push' => __('Push notifications'), 'domain_search' => __('Domain search'), 'plugin_catalogue' => __('Plugin catalogue'), ...app(PluginManager::class)->moduleLogs()];
    }

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function failed(): bool
    {
        return $this->error !== null || $this->status === null || $this->status >= 400;
    }
}
