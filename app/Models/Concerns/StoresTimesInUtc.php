<?php

namespace App\Models\Concerns;

use App\Support\StoredTime;

/**
 * Every model stores its times (created_at, updated_at and the datetime casts) in UTC and gives them back in the
 * timezone of Settings → General → Region, so the stored history means the same after the timezone changes and
 * across the summer time change. Plain dates (the `date` casts: due dates, expiry dates) are days of the calendar
 * and stay as they are. A value set as a string ('2026-11-06 14:00', from a form) is read as local time.
 * ModelsTest checks that every model uses this.
 */
trait StoresTimesInUtc
{
    public function setAttribute($key, $value)
    {
        if ($value !== null && $value !== '' && $this->storesTimeInUtc($key) && ! $this->hasSetMutator($key) && ! $this->hasAttributeSetMutator($key)) {
            $value = $this->asDateTime($value)->utc();
        }

        return parent::setAttribute($key, $value);
    }

    public function freshTimestampString()
    {
        return $this->fromDateTime($this->freshTimestamp()->utc());
    }

    protected function castAttribute($key, $value)
    {
        return parent::castAttribute($key, $this->storedTimeToLocal($key, $value));
    }

    protected function transformModelValue($key, $value)
    {
        return parent::transformModelValue($key, $this->storedTimeToLocal($key, $value));
    }

    protected function addDateAttributesToArray(array $attributes)
    {
        foreach ($this->getDates() as $key) {
            if ($key !== null && isset($attributes[$key])) {
                $attributes[$key] = $this->serializeDate($this->asDateTime($this->storedTimeToLocal($key, $attributes[$key])));
            }
        }

        return $attributes;
    }

    private function storedTimeToLocal(string $key, mixed $value): mixed
    {
        return is_string($value) && $value !== '' && $this->storesTimeInUtc($key) ? StoredTime::parse($value, $this->getDateFormat()) : $value;
    }

    private function storesTimeInUtc(string $key): bool
    {
        return $this->hasCast($key, ['datetime', 'immutable_datetime', 'custom_datetime', 'immutable_custom_datetime'])
            || (! $this->hasCast($key) && in_array($key, $this->getDates(), true));
    }
}
