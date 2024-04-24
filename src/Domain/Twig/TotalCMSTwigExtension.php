<?php

namespace TotalCMS\Domain\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
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

    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function getGlobals(): array
    {
        return [
            'totalcms'   => $this->adapter,
            'getParams'  => $_GET,
            'postParams' => array_filter($_POST),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('uniqid', [TotalCMSTwigFunctions::class, 'uniqid']),
            new TwigFunction('contains', [TotalCMSTwigFunctions::class, 'contains']),
            new TwigFunction('startsWith', [TotalCMSTwigFunctions::class, 'startsWith']),
            new TwigFunction('endsWith', [TotalCMSTwigFunctions::class, 'endsWith']),
            new TwigFunction('istype', [TotalCMSTwigFunctions::class, 'istype']),
            new TwigFunction('json_decode', [TotalCMSTwigFilters::class, 'jsonDecode']),
            new TwigFunction('print_r', [TotalCMSTwigFilters::class, 'print_r']),
        ];
    }

    public function getFilters()
    {
        return [
            new TwigFilter('charcount', [TotalCMSTwigFilters::class, 'charcount']),
            new TwigFilter('wordcount', [TotalCMSTwigFilters::class, 'wordcount']),
            new TwigFilter('readtime', [TotalCMSTwigFilters::class, 'readtime']),
            new TwigFilter('humanize', [TotalCMSTwigFilters::class, 'humanize']),
            new TwigFilter('titleize', [TotalCMSTwigFilters::class, 'titleize']),
            new TwigFilter('basename', [TotalCMSTwigFilters::class, 'basename']),
            new TwigFilter('dirname', [TotalCMSTwigFilters::class, 'dirname']),
            new TwigFilter('rtrim', [TotalCMSTwigFilters::class, 'rtrim']),
            new TwigFilter('ltrim', [TotalCMSTwigFilters::class, 'ltrim']),
            new TwigFilter('truncate', [TotalCMSTwigFilters::class, 'truncate']),
            new TwigFilter('truncateWords', [TotalCMSTwigFilters::class, 'truncateWords']),
            new TwigFilter('count', [TotalCMSTwigFilters::class, 'count']),
            new TwigFilter('ksort', [TotalCMSTwigFilters::class, 'ksort']),
            new TwigFilter('krsort', [TotalCMSTwigFilters::class, 'krsort']),
            new TwigFilter('randomize', [TotalCMSTwigFilters::class, 'randomize']),
            new TwigFilter('json_decode', [TotalCMSTwigFilters::class, 'jsonDecode']),
            new TwigFilter('print_r', [TotalCMSTwigFilters::class, 'print_r']),
            new TwigFilter('typeof', [TotalCMSTwigFilters::class, 'typeof']),
            new TwigFilter('string', [TotalCMSTwigFilters::class, 'string']),
            new TwigFilter('int', [TotalCMSTwigFilters::class, 'int']),
            new TwigFilter('float', [TotalCMSTwigFilters::class, 'float']),
            new TwigFilter('bool', [TotalCMSTwigFilters::class, 'bool']),
            new TwigFilter('array', [TotalCMSTwigFilters::class, 'array']),
            new TwigFilter('hex', [TotalCMSTwigFilters::class, 'hex']),
            new TwigFilter('rgb', [TotalCMSTwigFilters::class, 'rgb']),
            new TwigFilter('hsl', [TotalCMSTwigFilters::class, 'hsl']),
            new TwigFilter('oklch', [TotalCMSTwigFilters::class, 'oklch']),
            new TwigFilter('lightness', [TotalCMSTwigFilters::class, 'lightness']),
            new TwigFilter('chroma', [TotalCMSTwigFilters::class, 'chroma']),
            new TwigFilter('hue', [TotalCMSTwigFilters::class, 'hue']),
            new TwigFilter('adjustColor', [TotalCMSTwigFilters::class, 'adjustColor']),
        ];
    }
}
