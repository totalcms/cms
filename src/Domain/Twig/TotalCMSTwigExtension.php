<?php

namespace TotalCMS\Domain\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig Integration with Total CMS.
 */
final class TotalCMSTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function getGlobals(): array
    {
        return [
            'totalcms' => new TotalCMSTwigAdapter(),
        ];
    }

    // ...
}
