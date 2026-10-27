<?php

namespace App\Rules;

use App\Support\DomainName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A list of nameservers: each a host name like ns1.example.com, and two or more of them (or none, unless required). */
class ValidNameservers implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $list = array_filter(array_map(fn ($ns) => trim((string) $ns), (array) $value), 'strlen');

        if ($list === []) {
            return;
        }

        foreach ($list as $nameserver) {
            if (! DomainName::isNameserver($nameserver)) {
                $fail(__('":nameserver" is not a nameserver. Enter names like ns1.example.com.', ['nameserver' => $nameserver]));

                return;
            }
        }

        if (count(DomainName::nameservers($list)) < 2) {
            $fail(__('Enter at least two different nameservers.'));
        }
    }
}
