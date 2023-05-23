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
}
