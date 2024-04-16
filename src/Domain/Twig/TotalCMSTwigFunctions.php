<?php

namespace TotalCMS\Domain\Twig;

/**
 * Twig Functions for Total CMS.
 */
final class TotalCMSTwigFunctions
{
    public static function uniqid(): string
    {
        return uniqid();
    }

    // -------------------------
    // Tests
    // -------------------------
    public static function contains(string $string, string $contains): bool
    {
        return str_contains($string, $contains);
    }

    public static function startsWith(string $string, string $starts): bool
    {
        return str_starts_with($string, $starts);
    }

    public static function endsWith(string $string, string $ends): bool
    {
        return str_ends_with($string, $ends);
    }

    public static function istype(mixed $variable, string $type): bool
    {
        return gettype($variable) === $type;
    }
}
