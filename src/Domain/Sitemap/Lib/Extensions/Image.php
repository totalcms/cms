<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib\Extensions;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;
use TotalCMS\Domain\Sitemap\Lib\Interfaces\VisitorInterface;

class Image implements VisitorInterface
{
	/**
	 * The caption of the image.
	 *
	 * @var string
	 */
	protected $caption;

	/**
	 * The geographic location of the image.
	 *
	 * @var string
	 */
	protected $geoLocation;

	/**
	 * The title of the image.
	 *
	 * @var string
	 */
	protected $title;

	/**
	 * A URL to the license of the image.
	 *
	 * @var string
	 */
	protected $license;

	/**
	 * Image constructor.
	 *
	 * @param string $loc
	 */
	public function __construct(
		/**
		 * Location (URL).
		 */
		protected $loc,
	) {
	}

	/**
	 * Location (URL).
	 *
	 * @return string
	 */
	public function getLoc()
	{
		return $this->loc;
	}

	/**
	 * The caption of the image.
	 *
	 * @return string
	 */
	public function getCaption()
	{
		return $this->caption;
	}

	/**
	 * Set the caption of the image.
	 *
	 * @param string $caption
	 */
	public function setCaption($caption): static
	{
		$this->caption = $caption;

		return $this;
	}

	/**
	 * The geographic location of the image.
	 *
	 * @return string
	 */
	public function getGeoLocation()
	{
		return $this->geoLocation;
	}

	/**
	 * Set the geographic location of the image.
	 *
	 * @param string $geoLocation
	 */
	public function setGeoLocation($geoLocation): static
	{
		$this->geoLocation = $geoLocation;

		return $this;
	}

	/**
	 * The title of the image.
	 *
	 * @return string
	 */
	public function getTitle()
	{
		return $this->title;
	}

	/**
	 * Set the title of the image.
	 *
	 * @param string $title
	 */
	public function setTitle($title): static
	{
		$this->title = $title;

		return $this;
	}

	/**
	 * A URL to the license of the image.
	 *
	 * @return string
	 */
	public function getLicense()
	{
		return $this->license;
	}

	/**
	 * Set a URL to the license of the image.
	 *
	 * @param string $license
	 */
	public function setLicense($license): static
	{
		$this->license = $license;

		return $this;
	}

	public function accept(DriverInterface $driver): void
	{
		$driver->visitImageExtension($this);
	}
}
