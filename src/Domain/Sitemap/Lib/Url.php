<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;
use TotalCMS\Domain\Sitemap\Lib\Interfaces\VisitorInterface;

class Url implements VisitorInterface
{
	/**
	 * Last modified time.
	 */
	private ?\DateTimeInterface $lastMod = null;

	/**
	 * Change frequency of the location.
	 */
	private ?string $changeFreq = null;

	/**
	 * Priority of page importance.
	 */
	private ?string $priority = null;

	/**
	 * Array of sub-elements.
	 *
	 * @var VisitorInterface[]
	 */
	private array $extensions = [];

	public function __construct(
		/**
		 * Location (URL).
		 */
		private readonly string $loc,
	) {
	}

	public function getLoc(): string
	{
		return $this->loc;
	}

	public function getLastMod(): ?\DateTimeInterface
	{
		return $this->lastMod;
	}

	public function setLastMod(\DateTimeInterface $lastMod): void
	{
		$this->lastMod = $lastMod;
	}

	/**
	 * @return string
	 */
	public function getChangeFreq(): ?string
	{
		return $this->changeFreq;
	}

	public function setChangeFreq(string $changeFreq): void
	{
		$this->changeFreq = $changeFreq;
	}

	/**
	 * @return string
	 */
	public function getPriority(): ?string
	{
		return $this->priority;
	}

	public function setPriority(string $priority): void
	{
		$this->priority = $priority;
	}

	public function addExtension(VisitorInterface $extension): void
	{
		$this->extensions[] = $extension;
	}

	/**
	 * @return VisitorInterface[]
	 */
	public function getExtensions(): array
	{
		return $this->extensions;
	}

	public function accept(DriverInterface $driver): void
	{
		$driver->visitUrl($this);
	}
}
