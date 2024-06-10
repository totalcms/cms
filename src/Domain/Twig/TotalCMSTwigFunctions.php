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
        'chunk_split',
        'md5',
        'sha1',
        'explode',
        'strpos',
        'similar_text',
        'str_pad',
        'strlen',
        'strrpos',
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
        'var_dump',
        'print_r',
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
    public static function selectOptions(array $data, string $label = '', string $value = ''): array
    {
        // this takes a normal array and converts it to an array of arrays with label and value keys
        // the resulting array can be used for select options in a form
        if (empty($value) || empty($label)) {
            return array_map(fn ($value): array => ['label' => $value, 'value' => $value], $data);
        }

        return array_map(fn ($item): array => ['label' => $item[$label], 'value' => $item[$value]], $data);
    }

    public static function istype(mixed $variable, string $type): bool
    {
        return gettype($variable) === $type;
    }

    // -------------------------
    // Utilities
    // -------------------------

    /** @SuppressWarnings(PHPMD.CamelCaseMethodName) */
    public static function var_dump(mixed $variable): string
    {
        ob_start();
        var_dump($variable);
        $content = ob_get_contents();
        ob_end_clean();

        return "<pre>$content</pre>";
    }

    /** @SuppressWarnings(PHPMD.CamelCaseMethodName) */
    public static function print_r(mixed $variable): string
    {
        return '<pre>' . (string)print_r($variable, true) . '</pre>';
    }
}
