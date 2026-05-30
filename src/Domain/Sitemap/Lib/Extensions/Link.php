<?php

namespace TotalCMS\Domain\Sitemap\Lib\Extensions;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;
use TotalCMS\Domain\Sitemap\Lib\Interfaces\VisitorInterface;

/**
 * Class Link
 *
 * @package TotalCMS\Domain\Sitemap\Lib\Subelements
 */
class Link implements VisitorInterface
{
    /**
     * Language code for the page.
     *
     * @var string
     */
    protected $hrefLang;

    /**
     * Location of the translated page.
     *
     * @var string
     */
    protected $href;

    /**
     * Link constructor.
     *
     * @param string $hrefLang
     * @param string $href
     */
    public function __construct(string $hrefLang, string $href)
    {
        $this->hrefLang = $hrefLang;
        $this->href = $href;
    }

    /**
     * Location of the translated page.
     *
     * @return string
     */
    public function getHref(): string
    {
        return $this->href;
    }

    /**
     * Language code for the page.
     * 
     * @return string
     */
    public function getHrefLang(): string
    {
        return $this->hrefLang;
    }

    public function accept(DriverInterface $driver): void
    {
        $driver->visitLinkExtension($this);
    }
}
