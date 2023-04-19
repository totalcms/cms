<?php

namespace TotalCMS\Domain\Twig;

/**
 * Twig Adapter with Total CMS.
 */
final class TotalCMSTwigAdapter
{
    public function test(string $string): string
    {
        return "Test String $string";
    }
}
