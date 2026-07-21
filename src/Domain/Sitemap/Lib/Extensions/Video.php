<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib\Extensions;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;
use TotalCMS\Domain\Sitemap\Lib\Interfaces\VisitorInterface;

class Video implements VisitorInterface
{
	/**
	 * URL pointing to the actual media file (mp4).
	 *
	 * @var string
	 */
	protected $contentLoc;

	/**
	 * URL pointing to the player file (normally a SWF).
	 *
	 * @var string
	 */
	protected $playerLoc;

	/**
	 * Indicates whether the video is live.
	 *
	 * @var bool
	 */
	protected $live;

	/**
	 * Duration of the video in seconds.
	 *
	 * @var int
	 */
	protected $duration;

	/**
	 * String of space delimited platform values.
	 *
	 * Allowed values are web, mobile, and tv.
	 *
	 * @var string
	 */
	protected $platform;

	/**
	 * Does the video require a subscription?
	 *
	 * @var bool
	 */
	protected $requiresSubscription;

	/**
	 * The price to download or view the video in ISO 4217 format.
	 *
	 * @see https://en.wikipedia.org/wiki/ISO_4217
	 *
	 * @var string
	 */
	protected $price;

	/**
	 * The currency used for the price.
	 *
	 * @var string
	 */
	protected $currency;

	/**
	 * Link to gallery of which this video appears in.
	 *
	 * @var string
	 */
	protected $galleryLoc;

	/**
	 * A space-delimited list of countries where the video may or may not be played.
	 *
	 * @var string
	 */
	protected $restriction;

	/**
	 * A tag associated with the video.
	 *
	 * @var list<string>
	 */
	protected $tags = [];

	/**
	 * The video's category. For example, cooking.
	 *
	 * @var string
	 */
	protected $category;

	/**
	 * No if the video should be available only to users with SafeSearch turned off.
	 *
	 * @var string
	 */
	protected $familyFriendly;

	/**
	 * The date the video was first published.
	 *
	 * @var \DateTimeInterface
	 */
	protected $publicationDate;

	/**
	 * The number of times the video has been viewed.
	 *
	 * @var int
	 */
	protected $viewCount;

	/**
	 * The video uploader's name. Only one <video:uploader> is allowed per video.
	 *
	 * @var string
	 */
	protected $uploader;

	/**
	 * The rating of the video. Allowed values are float numbers in the range 0.0 to 5.0.
	 *
	 * @var float
	 */
	protected $rating;

	/**
	 * The date after which the video will no longer be available.
	 *
	 * @var \DateTimeInterface
	 */
	protected $expirationDate;

	/**
	 * Video constructor.
	 *
	 * @param string $thumbnailLoc
	 * @param string $title
	 * @param string $description
	 */
	public function __construct(
		/**
		 * URL pointing to an image thumbnail.
		 */
		protected $thumbnailLoc,
		/**
		 * Title of the video, max 100 characters.
		 */
		protected $title,
		/**
		 * Description of the video, max 2048 characters.
		 */
		protected $description,
	) {
	}

	/**
	 * URL pointing to the player file (normally a SWF).
	 *
	 * @return string
	 */
	public function getPlayerLoc()
	{
		return $this->playerLoc;
	}

	/**
	 * URL pointing to the player file (normally a SWF).
	 *
	 * @param string $playerLoc
	 */
	public function setPlayerLoc($playerLoc): static
	{
		$this->playerLoc = $playerLoc;

		return $this;
	}

	/**
	 * URL pointing to an image thumbnail.
	 *
	 * @return string
	 */
	public function getThumbnailLoc()
	{
		return $this->thumbnailLoc;
	}

	/**
	 * Title of the video, max 100 characters.
	 *
	 * @return string
	 */
	public function getTitle()
	{
		return $this->title;
	}

	/**
	 * Description of the video, max 2048 characters.
	 *
	 * @return string
	 */
	public function getDescription()
	{
		return $this->description;
	}

	/**
	 * URL pointing to the actual media file (mp4).
	 *
	 * @return string
	 */
	public function getContentLoc()
	{
		return $this->contentLoc;
	}

	/**
	 * URL pointing to the actual media file (mp4).
	 *
	 * @param string $contentLoc
	 */
	public function setContentLoc($contentLoc): static
	{
		$this->contentLoc = $contentLoc;

		return $this;
	}

	/**
	 * Duration of the video in seconds.
	 *
	 * @return int
	 */
	public function getDuration()
	{
		return $this->duration;
	}

	/**
	 * Duration of the video in seconds.
	 *
	 * @param int $duration
	 */
	public function setDuration($duration): static
	{
		$this->duration = $duration;

		return $this;
	}

	/**
	 * The date after which the video will no longer be available.
	 *
	 * @return \DateTimeInterface
	 */
	public function getExpirationDate()
	{
		return $this->expirationDate;
	}

	/**
	 * The date after which the video will no longer be available.
	 */
	public function setExpirationDate(\DateTimeInterface $expirationDate): static
	{
		$this->expirationDate = $expirationDate;

		return $this;
	}

	/**
	 * The rating of the video. Allowed values are float numbers in the range 0.0 to 5.0.
	 *
	 * @return float
	 */
	public function getRating()
	{
		return $this->rating;
	}

	/**
	 * The rating of the video. Allowed values are float numbers in the range 0.0 to 5.0.
	 *
	 * @param float $rating
	 */
	public function setRating($rating): static
	{
		$this->rating = $rating;

		return $this;
	}

	/**
	 * The number of times the video has been viewed.
	 *
	 * @return int
	 */
	public function getViewCount()
	{
		return $this->viewCount;
	}

	/**
	 * The number of times the video has been viewed.
	 *
	 * @param int $viewCount
	 */
	public function setViewCount($viewCount): static
	{
		$this->viewCount = $viewCount;

		return $this;
	}

	/**
	 * The date the video was first published, in W3C format.
	 *
	 * @return \DateTimeInterface
	 */
	public function getPublicationDate()
	{
		return $this->publicationDate;
	}

	/**
	 * The date the video was first published, in W3C format.
	 */
	public function setPublicationDate(\DateTimeInterface $publicationDate): static
	{
		$this->publicationDate = $publicationDate;

		return $this;
	}

	/**
	 * No if the video should be available only to users with SafeSearch turned off.
	 *
	 * @return string
	 */
	public function getFamilyFriendly()
	{
		return $this->familyFriendly;
	}

	/**
	 * No if the video should be available only to users with SafeSearch turned off.
	 *
	 * @param string $familyFriendly
	 */
	public function setFamilyFriendly($familyFriendly): static
	{
		$this->familyFriendly = $familyFriendly;

		return $this;
	}

	/**
	 * A tag associated with the video.
	 *
	 * @return list<string>
	 */
	public function getTags()
	{
		return $this->tags;
	}

	/**
	 * A tag associated with the video.
	 *
	 * @param list<string> $tags
	 */
	public function setTags($tags): static
	{
		$this->tags = $tags;

		return $this;
	}

	/**
	 * The video's category. For example, cooking.
	 *
	 * @return string
	 */
	public function getCategory()
	{
		return $this->category;
	}

	/**
	 * The video's category. For example, cooking.
	 *
	 * @param string $category
	 */
	public function setCategory($category): static
	{
		$this->category = $category;

		return $this;
	}

	/**
	 * A space-delimited list of countries where the video may or may not be played.
	 *
	 * @return string
	 */
	public function getRestriction()
	{
		return $this->restriction;
	}

	/**
	 * A space-delimited list of countries where the video may or may not be played.
	 *
	 * @param string $restriction
	 */
	public function setRestriction($restriction): static
	{
		$this->restriction = $restriction;

		return $this;
	}

	/**
	 * Link to gallery of which this video appears in.
	 *
	 * @return string
	 */
	public function getGalleryLoc()
	{
		return $this->galleryLoc;
	}

	/**
	 * Link to gallery of which this video appears in.
	 *
	 * @param string $galleryLoc
	 */
	public function setGalleryLoc($galleryLoc): static
	{
		$this->galleryLoc = $galleryLoc;

		return $this;
	}

	/**
	 * The price to download or view the video in ISO 4217 format.
	 *
	 * @return string
	 */
	public function getPrice()
	{
		return $this->price;
	}

	/**
	 * The price to download or view the video in ISO 4217 format.
	 *
	 * @param string $price
	 */
	public function setPrice($price): static
	{
		$this->price = $price;

		return $this;
	}

	/**
	 * The currency used for the price.
	 *
	 * @return string
	 */
	public function getCurrency()
	{
		return $this->currency;
	}

	/**
	 * The currency used for the price.
	 *
	 * @param string $currency
	 */
	public function setCurrency($currency): void
	{
		$this->currency = $currency;
	}

	/**
	 * Does the video require a subscription?
	 *
	 * @return bool
	 */
	public function getRequiresSubscription()
	{
		return $this->requiresSubscription;
	}

	/**
	 * Does the video require a subscription?
	 *
	 * @param bool $requiresSubscription
	 */
	public function setRequiresSubscription($requiresSubscription): static
	{
		$this->requiresSubscription = $requiresSubscription;

		return $this;
	}

	/**
	 * The video uploader's name. Only one <video:uploader> is allowed per video.
	 *
	 * @return string
	 */
	public function getUploader()
	{
		return $this->uploader;
	}

	/**
	 * The video uploader's name. Only one <video:uploader> is allowed per video.
	 *
	 * @param string $uploader
	 */
	public function setUploader($uploader): static
	{
		$this->uploader = $uploader;

		return $this;
	}

	/**
	 * String of space delimited platform values.
	 *
	 * Allowed values are web, mobile, and tv.
	 *
	 * @return string
	 */
	public function getPlatform()
	{
		return $this->platform;
	}

	/**
	 * String of space delimited platform values.
	 *
	 * Allowed values are web, mobile, and tv.
	 *
	 * @param string $platform
	 */
	public function setPlatform($platform): static
	{
		$this->platform = $platform;

		return $this;
	}

	/**
	 * Indicates whether the video is live.
	 *
	 * @return bool
	 */
	public function getLive()
	{
		return $this->live;
	}

	/**
	 * Indicates whether the video is live.
	 *
	 * @param bool $live
	 */
	public function setLive($live): static
	{
		$this->live = $live;

		return $this;
	}

	public function accept(DriverInterface $driver): void
	{
		$driver->visitVideoExtension($this);
	}
}
