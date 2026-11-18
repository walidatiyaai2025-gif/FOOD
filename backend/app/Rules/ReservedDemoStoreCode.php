<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ReservedDemoStoreCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_starts_with(strtoupper($value), 'FOODEX-DEMO-B2C-')) {
            $fail('The FOODEX demo store code prefix is reserved for repository-seeded data.');
        }
    }
}
