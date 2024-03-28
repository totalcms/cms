<?php

namespace TotalCMS\Domain\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Twig Integration with Total CMS.
 */
final class TotalCMSTwigExtension extends AbstractExtension implements GlobalsInterface
{
    private TotalCMSTwigAdapter $adapter;

    public function __construct(TotalCMSTwigAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    public function getGlobals(): array
    {
        return [
            'totalcms'   => $this->adapter,
            'getParams'  => $_GET,
            'postParams' => $_POST,
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('uniqid', fn () => uniqid()),
            // new TwigFunction('uniqid', [TotalCMSTwigFunctions::class, 'uniqid']),
        ];
    }
}
