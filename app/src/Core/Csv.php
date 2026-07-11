<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Thin wrappers around fputcsv()/fgetcsv() passing the $escape parameter
 * explicitly. PHP 8.4 deprecates relying on its default (which will change
 * from backslash to empty); these keep the current behaviour everywhere
 * without repeating four positional arguments at every call site.
 */
class Csv
{
    /**
     * Write a row to an open stream as CSV.
     *
     * @param resource $stream
     * @return int|false Bytes written, or false on failure
     */
    public static function put($stream, array $fields): int|false
    {
        return fputcsv($stream, $fields, ',', '"', '\\');
    }

    /**
     * Read a row of CSV from an open stream.
     *
     * @param resource $stream
     * @return array|false The row, or false at EOF/error
     */
    public static function get($stream): array|false
    {
        return fgetcsv($stream, null, ',', '"', '\\');
    }
}
