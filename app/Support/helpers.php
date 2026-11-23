<?php

use App\Support\NumberLocalizer;

if (! function_exists('persian_digits')) {
    /**
     * Convert Latin digits to Persian digits when active locale is Persian.
     */
    function persian_digits(mixed $value, ?string $locale = null): string
    {
        return NumberLocalizer::toPersianDigits($value, $locale);
    }
}
