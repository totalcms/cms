<?php

namespace TotalCMS\Domain\Twig;

/**
 * Twig Functions for Total CMS.
 */
final class TotalCMSTwigFilters
{
    // -------------------------
    // Text Manipulation
    // -------------------------
    public static function humanize(string $slug, string $sep = '-'): string
    {
        return ucfirst(str_replace($sep, ' ', $slug));
    }

    public static function titleize(string $slug, string $sep = '-'): string
    {
        return ucwords(str_replace($sep, ' ', $slug));
    }

    public static function basename(string $file): string
    {
        return basename($file);
    }

    public static function dirname(string $file): string
    {
        return dirname($file);
    }

    public static function rtrim(string $string): string
    {
        return rtrim($string);
    }

    public static function ltrim(string $string): string
    {
        return ltrim($string);
    }

    // -------------------------
    // Total CMS Color Manipulation
    // -------------------------
    public static function hex(array $color): string
    {
        return $color['hex'] ?? '#000000';
    }

    public static function oklch(array $color, int $alpha = 100): string
    {
        $oklch = $color['oklch'] ?? ['l' => 0, 'c' => 0, 'h' => 0];

        return sprintf('oklch(%d%% %f %f / %f)', $oklch['l'], $oklch['c'], $oklch['h'], $alpha / 100);
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.ElseExpression)
     *
     * @param string $string
     * @param int $length
     * @param bool $keepWords
     */
    public static function truncate(string $string, int $length, bool $keepWords = false): string
    {
        if (strlen($string) > $length) {
            if ($keepWords) {
                $string  = substr($string, 0, $length);
                $space   = strrpos($string, ' ') ?: null;
                $string  = substr($string, 0, $space);
            } else {
                $string = substr($string, 0, $length);
            }
            $string .= '&hellip';
        }

        return $string;
    }

    public static function truncateWords(string $string, int $length): string
    {
        $words = explode(' ', $string);

        if (count($words) > $length) {
            $string = implode(' ', array_slice($words, 0, $length));
            $string .= '&hellip';
        }

        return $string;
    }

    // -------------------------
    // Counters
    // -------------------------
    public static function charcount(string $text): int
    {
        $text = strip_tags($text); // strip HTML
        $text = preg_replace('/\s+/', ' ', $text); // replace multiple spaces with a single space

        return mb_strlen($text ?? '');
    }

    public static function wordcount(string $text): int
    {
        $text  = strip_tags($text); // strip HTML
        $words = preg_split('/[\s,:?!]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? sizeof($words) : 0;
    }

    public static function readtime(string $text, int $wpm = 180): float
    {
        $wordCount = self::wordcount($text);

        return ceil($wordCount / $wpm);
    }

    public static function count(array $array): int
    {
        return count($array);
    }

    // -------------------------
    // Array Manipulation
    // -------------------------
    public static function ksort(array $array): array
    {
        ksort($array);

        return $array;
    }

    public static function krsort(array $array): array
    {
        krsort($array);

        return $array;
    }

    public static function randomize(array $array): array
    {
        shuffle($array);

        return $array;
    }

    // -------------------------
    // Type Casting
    // -------------------------

    public static function typeof(mixed $variable): string
    {
        return gettype($variable);
    }

    public static function string(mixed $variable): string
    {
        return (string)$variable;
    }

    public static function int(mixed $variable): int
    {
        return (int)$variable;
    }

    public static function float(mixed $variable): float
    {
        return (float)$variable;
    }

    public static function bool(mixed $variable): bool
    {
        return (bool)$variable;
    }

    public static function array(mixed $variable): array
    {
        return (array)$variable;
    }

    // -------------------------
    // Utilities
    // -------------------------

    /** @SuppressWarnings(PHPMD.CamelCaseMethodName) */
    public static function print_r(mixed $variable): string
    {
        return '<pre>' . (string)print_r($variable, true) . '</pre>';
    }

    /** @SuppressWarnings(PHPMD.CamelCaseMethodName) */
    public static function json_decode(mixed $variable): array
    {
        return json_decode($variable);
    }
}
