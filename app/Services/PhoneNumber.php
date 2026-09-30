<?php

namespace App\Services;

/**
 * Single source of truth for Indonesian phone numbers in BRTHub.
 *
 * The canonical stored/compared format is `+62xxxxxxxxxx`, identical to
 * auth-service `ValidationService::normalizePhone()`, so phone numbers coming
 * from the IdP always match rows mirrored into the local users table.
 */
class PhoneNumber
{
    /** Canonical E.164 pattern for Indonesian numbers. */
    public const PATTERN = '/^\+62\d{9,13}$/';

    /**
     * Normalize any user input (0812…, 62812…, +62 812-3456-7890) into +62xxxxxxxxxx.
     * Returns null when the input contains no usable digits.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (! str_starts_with($digits, '62')) {
            $digits = '62'.$digits;
        }

        return '+'.$digits;
    }

    /**
     * Validate that the given value is a normalized Indonesian phone number.
     */
    public static function isValid(?string $phone): bool
    {
        if ($phone === null) {
            return false;
        }

        return preg_match(self::PATTERN, self::normalize($phone) ?? '') === 1;
    }

    /**
     * Human readable form, e.g. +6281234567890 => +62 812-3456-7890.
     */
    public static function format(?string $phone): string
    {
        $normalized = self::normalize($phone);

        if ($normalized === null) {
            return '';
        }

        $national = substr($normalized, 3);

        return '+62 '.implode('-', str_split($national, 4));
    }
}
