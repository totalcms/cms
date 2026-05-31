<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib\Extensions;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;
use TotalCMS\Domain\Sitemap\Lib\Interfaces\VisitorInterface;

/**
 * Class Image.
 */
class News implements VisitorInterface
{
	/**
	 * Location (URL).
	 *
	 * @var string
	 */
	protected $loc;

	/**
	 * Publication name.
	 *
	 * @var string
	 */
	protected $publicationName;

	/**
	 * Publication language.
	 *
	 * @var string
	 */
	protected $publicationLanguage;

	/**
	 * Access.
	 *
	 * @var string
	 */
	protected $access;

	/**
	 * List of genres, comma-separated string values.
	 *
	 * @var string
	 */
	protected $genres;

	/**
	 * Date of publication.
	 *
	 * @var \DateTimeInterface
	 */
	protected $publicationDate;

	/**
	 * Title.
	 *
	 * @var string
	 */
	protected $title;

	/**
	 * Key words, comma-separated string values.
	 *
	 * @var string
	 */
	protected $keywords;

	/**
	 * Publication name.
	 *
	 * @return string
	 */
	public function getPublicationName()
	{
		return $this->publicationName;
	}

	/**
	 * Set the publication name.
	 *
	 * @param string $publicationName
	 *
	 * @return $this
	 */
	public function setPublicationName($publicationName): static
	{
		$this->publicationName = $publicationName;

		return $this;
	}

	/**
	 * Publication language.
	 *
	 * @return string
	 */
	public function getPublicationLanguage()
	{
		return $this->publicationLanguage;
	}

	/**
	 * Set the publication language.
	 *
	 * @param string $publicationLanguage
	 *
	 * @return $this
	 */
	public function setPublicationLanguage($publicationLanguage): static
	{
		$this->publicationLanguage = $publicationLanguage;

		return $this;
	}

	/**
	 * Access.
	 *
	 * @return string
	 */
	public function getAccess()
	{
		return $this->access;
	}

	/**
	 * Set access.
	 *
	 * @param string $access
	 *
	 * @return $this
	 */
	public function setAccess($access): static
	{
		$this->access = $access;

		return $this;
	}

	/**
	 * List of genres, comma-separated string values.
	 *
	 * @return string
	 */
	public function getGenres()
	{
		return $this->genres;
	}

	/**
	 * Set list of genres, comma-separated string values.
	 *
	 * @param string $genres
	 *
	 * @return $this
	 */
	public function setGenres($genres): static
	{
		$this->genres = $genres;

		return $this;
	}

	/**
	 * Date of publication.
	 *
	 * @return \DateTimeInterface
	 */
	public function getPublicationDate()
	{
		return $this->publicationDate;
	}

	/**
	 * Set date of publication.
	 *
	 * @return $this
	 */
	public function setPublicationDate(\DateTimeInterface $publicationDate): static
	{
		$this->publicationDate = $publicationDate;

		return $this;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function getTitle()
	{
		return $this->title;
	}

	/**
	 * Set title.
	 *
	 * @param string $title
	 *
	 * @return $this
	 */
	public function setTitle($title): static
	{
		$this->title = $title;

		return $this;
	}

	/**
	 * Key words, comma-separated string values.
	 *
	 * @return string
	 */
	public function getKeywords()
	{
		return $this->keywords;
	}

	/**
	 * Set key words, comma-separated string values.
	 *
	 * @param string $keywords
	 *
	 * @return $this
	 */
	public function setKeywords($keywords): static
	{
		$this->keywords = $keywords;

		return $this;
	}

	public function accept(DriverInterface $driver): void
	{
		$driver->visitNewsExtension($this);
	}
}
