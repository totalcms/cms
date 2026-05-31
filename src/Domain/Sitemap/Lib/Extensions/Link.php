<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib\Extensions;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;
use TotalCMS\Domain\Sitemap\Lib\Interfaces\VisitorInterface;

/**
 * Class Link.
 */
class Link implements VisitorInterface
{
	/**
	 * Link constructor.
	 */
	public function __construct(protected string $hrefLang, protected string $href)
	{
	}

	/**
	 * Location of the translated page.
	 */
	public function getHref(): string
	{
		return $this->href;
	}

	/**
	 * Language code for the page.
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
