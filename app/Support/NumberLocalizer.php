<?php

namespace App\Support;

class NumberLocalizer
{
    /**
     * Map of ASCII digits (0-9) to standard Persian digits (۰-۹).
     *
     * @var array<string, string>
     */
    protected const DIGIT_MAP = [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
    ];

    /**
     * Convert Latin digits to Persian digits when active locale is Persian ('fa').
     * Returns the string unchanged when locale is English or other languages.
     */
    public static function toPersianDigits(mixed $value, ?string $locale = null): string
    {
        if ($value === null) {
            return '';
        }

        $string = (string) $value;
        $resolvedLocale = $locale ?? app()->getLocale();

        if ($resolvedLocale !== 'fa') {
            return $string;
        }

        if (self::isExempt($string)) {
            return $string;
        }

        return strtr($string, self::DIGIT_MAP);
    }

    /**
     * Format a numeric value and convert digits to Persian if active locale is Persian.
     */
    public static function format(mixed $value, int $decimals = 0, ?string $locale = null): string
    {
        if (! is_numeric($value)) {
            return self::toPersianDigits($value, $locale);
        }

        $formatted = number_format((float) $value, $decimals);

        return self::toPersianDigits($formatted, $locale);
    }

    /**
     * Determine whether a string value is exempt from Persian digit localization.
     * Exemptions include system codes/IDs (REQ-*, HSP-*, DON-*, etc.), blood groups,
     * emails, phone numbers, and URLs.
     */
    public static function isExempt(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return false;
        }

        // System identifiers and codes: e.g. REQ-2026-0001, HSP-001, DON-1002, APT-10, MED-01
        if (preg_match('/^(REQ|HSP|DON|USR|APT|MED|CAMP|SMP|UNT|BAG)-/i', $trimmed) === 1) {
            return true;
        }

        // Blood group identifiers: A+, A-, B+, B-, AB+, AB-, O+, O-, PRBC, FFP, PLT, CRYO
        if (preg_match('/^(A|B|AB|O)[+-]$/i', $trimmed) === 1 || in_array(strtoupper($trimmed), ['PRBC', 'FFP', 'PLT', 'CRYO', 'WHOLE_BLOOD'], true)) {
            return true;
        }

        // Email address
        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        // Phone numbers (+93 70 123 4567, 0799-123-456, etc.)
        if (preg_match('/^\+?[0-9\s\-()]{7,25}$/', $trimmed) === 1 && preg_match('/[\s\-()+]/', $trimmed) === 1) {
            return true;
        }

        // URLs
        if (preg_match('/^https?:\/\//i', $trimmed) === 1) {
            return true;
        }

        return false;
    }
}
