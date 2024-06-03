<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Property\Data\ColorData;
use Twig\TwigFilter;

/**
 * Twig Functions for Total CMS.
 */
final class TotalCMSTwigFilters
{
    public static array $phpFunctions = [
        'basename',
        'dirname',
        'rtrim',
        'ltrim',
        'trim',
        'ucwords',
        'lcfirst',
        'str_word_count',
        'count',
        'json_decode',
    ];

    public static array $customFunctions = [
        'charcount',
        'wordcount',
        'readtime',
        'humanize',
        'titleize',
        'truncate',
        'truncateWords',
        'ksort',
        'krsort',
        'randomize',
        'print_r',
        'var_dump',
        'typeof',
        'string',
        'int',
        'float',
        'bool',
        'array',
        'hex',
        'rgb',
        'hsl',
        'oklch',
        'lightness',
        'chroma',
        'hue',
        'adjustColor',
    ];

    public static function getFilters(): array
    {
        $twigFunctions = [];

        foreach (self::$customFunctions as $function) {
            $twigFunctions[] = new TwigFilter($function, [self::class, $function]);
        }

        foreach (self::$phpFunctions as $function) {
            $twigFunctions[] = new TwigFilter($function, $function);
        }

        return $twigFunctions;
    }

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

    // -------------------------
    // Total CMS Color Manipulation
    // -------------------------
    public static function hexToColor(string $hex): array
    {
        return [
            'hex'   => $hex,
            'oklch' => ColorData::hexToOklch($hex),
        ];
    }

    public static function hex(?array $color): string
    {
        if ($color === null) {
            return '';
        }

        return $color['hex'] ?? '#000000';
    }

    /** @SuppressWarnings(PHPMD.BooleanArgumentFlag) */
    public static function rgb(?array $color, int $alpha = 100, bool $wrap = true): string
    {
        if ($color === null) {
            return '';
        }

        $hex = self::hex($color);
        $rgb = ColorData::hexToRgb($hex);

        $color = $alpha === 100 ?
            sprintf('%d %d %d', $rgb['r'], $rgb['g'], $rgb['b']) :
            sprintf('%d %d %d / %.2f', $rgb['r'], $rgb['g'], $rgb['b'], $alpha / 100);

        return $wrap ? sprintf('rgb(%s)', $color) : $color;
    }

    /** @SuppressWarnings(PHPMD.BooleanArgumentFlag) */
    public static function hsl(?array $color, int $alpha = 100, bool $wrap = true): string
    {
        if ($color === null) {
            return '';
        }

        $hex = self::hex($color);
        $hsl = ColorData::hexToHsl($hex);

        $color = $alpha === 100 ?
            sprintf('%d %d%% %d%%', $hsl['h'], $hsl['s'], $hsl['l']) :
            sprintf('%d %d%% %d%% / %.2f', $hsl['h'], $hsl['s'], $hsl['l'], $alpha / 100);

        return $wrap ? sprintf('hsl(%s)', $color) : $color;
    }

    /** @SuppressWarnings(PHPMD.BooleanArgumentFlag) */
    public static function oklch(?array $color, int $alpha = 100, bool $wrap = true): string
    {
        if ($color === null) {
            return '';
        }

        $oklch = $color['oklch'] ?? ['l' => 0, 'c' => 0, 'h' => 0];

        $color = $alpha === 100 ?
            sprintf('oklch(%.3f%% %.3f %.3f)', $oklch['l'], $oklch['c'], $oklch['h']) :
            sprintf('oklch(%.3f%% %.3f %.3f / %.2f)', $oklch['l'], $oklch['c'], $oklch['h'], $alpha / 100);

        return $wrap ? sprintf('oklch(%s)', $color) : $color;
    }

    public static function lightness(?array $color, string $lightness): ?array
    {
        return self::adjustColor($color, $lightness);
    }

    public static function chroma(?array $color, string $chroma): ?array
    {
        return self::adjustColor($color, null, $chroma);
    }

    public static function hue(?array $color, string $hue): ?array
    {
        return self::adjustColor($color, null, null, $hue);
    }

    public static function adjustColor(?array $color, ?string $lightness = null, ?string $chroma = null, ?string $hue = null): ?array
    {
        if ($color === null) {
            return null;
        }

        $oklch = $color['oklch'] ?? ['l' => 0, 'c' => 0, 'h' => 0];

        $oklch = ColorData::oklchChange($oklch, [
            'l' => $lightness,
            'c' => $chroma,
            'h' => $hue,
        ]);

        return [
            'oklch' => $oklch,
            'hex'   => ColorData::oklchToHex($oklch),
        ];
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
    public static function var_dump(mixed $variable): string
    {
        return TotalCMSTwigFunctions::var_dump($variable);
    }

    /** @SuppressWarnings(PHPMD.CamelCaseMethodName) */
    public static function print_r(mixed $variable): string
    {
        return TotalCMSTwigFunctions::print_r($variable);
    }
}
