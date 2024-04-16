<?php

namespace TotalCMS\Domain\Twig;

/**
 * Twig Functions for Total CMS.
 */
final class TotalCMSTwigFilters
{
    public static function wordify(string $slug, string $sep = '-'): string
    {
        return ucwords(str_replace($sep, ' ', $slug));
    }
}
