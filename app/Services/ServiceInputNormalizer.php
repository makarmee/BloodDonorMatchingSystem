<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

class ServiceInputNormalizer
{
    /**
     * Parse an optional calendar date into `Y-m-d` format or return null.
     *
     * @throws ValidationException
     */
    public function parseOptionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $field => "The {$field} value must be a valid calendar date.",
            ]);
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => "The {$field} value must be a valid calendar date.",
            ]);
        }
    }

    /**
     * Parse a calendar date input or default to today's date string.
     *
     * @throws ValidationException
     */
    public function parseRequiredOrDefaultDate(mixed $value, string $field): string
    {
        return $this->parseOptionalDate($value, $field) ?? CarbonImmutable::today()->toDateString();
    }

    /**
     * Parse an optional datetime value into CarbonImmutable.
     *
     * @throws ValidationException
     */
    public function parseOptionalDateTime(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $field => "The {$field} value must be a valid date and time.",
            ]);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => "The {$field} value must be a valid date and time.",
            ]);
        }
    }

    /**
     * Trim and validate a required text field against max length.
     *
     * @throws ValidationException
     */
    public function normalizeRequiredText(mixed $value, int $maxLength, string $field, string $emptyMessage): string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        if ($trimmed === '' || mb_strlen($trimmed) > $maxLength) {
            throw ValidationException::withMessages([
                $field => $emptyMessage,
            ]);
        }

        return $trimmed;
    }

    /**
     * Normalize an optional text field with optional maximum length guard.
     *
     * @throws ValidationException
     */
    public function normalizeOptionalText(mixed $value, ?int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $field => "The {$field} value must be valid text.",
            ]);
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if ($maxLength !== null && mb_strlen($trimmed) > $maxLength) {
            throw ValidationException::withMessages([
                $field => "The {$field} value may not exceed {$maxLength} characters.",
            ]);
        }

        return $trimmed;
    }
}
