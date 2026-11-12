<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ReferenceCodeGenerator
{
    /**
     * Generate a unique human-readable BDMS reference code checked against the target table column.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function generate(string $modelClass, string $column, string $prefix, int $digits = 6): string
    {
        $max = (10 ** $digits) - 1;
        $min = 10 ** ($digits - 1);

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $candidate = $prefix.str_pad((string) random_int($min, $max), $digits, '0', STR_PAD_LEFT);

            if (! $modelClass::query()->where($column, $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException("Unable to generate a unique reference code for {$modelClass}::{$column}.");
    }
}
