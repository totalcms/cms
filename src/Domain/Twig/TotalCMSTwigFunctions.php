<?php

namespace TotalCMS\Domain\Twig;

use Twig\TwigFunction;

/**
 * Twig Functions for Total CMS.
 */
final class TotalCMSTwigFunctions
{
    public static array $phpFunctions = [
        'uniqid',
        'floor',
        'ceil',
        'addslashes',
        'chr',
        'chunk_split',
        'convert_uudecode',
        'crc32',
        'crypt',
        'hex2bin',
        'md5',
        'sha1',
        'strpos',
        'strrpos',
        'ucwords',
        'wordwrap',
        'gettype',
        'str_contains',
        'str_starts_with',
        'str_ends_with',
        'json_decode',
        'http_build_query',
    ];

    public static array $customFunctions = [
        'selectOptions',
        'istype',
    ];

    public static function getFunctions(): array
    {
        $twigFunctions = [];

        foreach (self::$customFunctions as $function) {
            $twigFunctions[] = new TwigFunction($function, [self::class, $function]);
        }

        foreach (self::$phpFunctions as $function) {
            $twigFunctions[] = new TwigFunction($function, $function);
        }

        return $twigFunctions;
    }

    // -------------------------
    // Custom Functions
    // -------------------------
    public static function selectOptions(array $options): array
    {
        // this takes a normal array and converts it to an array of arrays with label and value keys
        // the resulting array can be used for select options in a form
        return array_map(fn ($value): array => ['label' => $value, 'value' => $value], $options);
    }

    public static function istype(mixed $variable, string $type): bool
    {
        return gettype($variable) === $type;
    }
}
