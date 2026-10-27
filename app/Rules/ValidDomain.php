<?php

namespace App\Rules;

use App\Support\DomainName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A domain name such as example.gr; an IDN only when Settings → Domains allows them. */
class ValidDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (($problem = DomainName::problem((string) $value)) !== null) {
            $fail($problem);
        }
    }
}
