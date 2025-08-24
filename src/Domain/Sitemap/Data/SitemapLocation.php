<?php

namespace TotalCMS\Domain\Sitemap\Data;

use Thepixeldeveloper\Sitemap\Extensions\Image;
use Thepixeldeveloper\Sitemap\Extensions\Link;
use Thepixeldeveloper\Sitemap\Extensions\Video;
use Thepixeldeveloper\Sitemap\Url;

final readonly class SitemapLocation
{
	private Url $location;

	/** @param array<string,string> $options */
	public function __construct(string $url, array $options = [])
	{
		if (strlen($url) === 0) {
			return;
		}
		$this->location = new Url(trim($url));

		if (!empty($options['date'])) {
			$this->location->setLastMod(new \DateTime($options['date']));
		}

		if (!empty($options['frequency'])) {
			$this->location->setChangeFreq($options['frequency']);
		}

		if (isset($options['priority'])) {
			$this->location->setPriority($options['priority']);
		}
	}

	public function location(): Url
	{
		return $this->location;
	}

	public function addImage(string $imageUrl): void
	{
		$imageUrl = trim($imageUrl);
		if (empty($imageUrl)) {
			return;
		}

		$image = new Image($imageUrl);
		$this->location->addExtension($image);
	}

	public function addLink(string $lang, string $href): void
	{
		$link = new Link($lang, $href);
		$this->location->addExtension($link);
	}

	public function addHostedVideo(string $location, string $thumb, string $title, string $description, int $duration = 0): void
	{
		$video = new Video($thumb, $title, $description);
		$video->setPlayerLoc($location);
		if ($duration > 0) {
			$video->setDuration($duration);
		}
		$this->location->addExtension($video);
	}

	public function addVideo(string $location, string $thumb, string $title, string $description, int $duration = 0): void
	{
		$video = new Video($thumb, $title, $description);
		$video->setContentLoc($location);
		if ($duration > 0) {
			$video->setDuration($duration);
		}
		$this->location->addExtension($video);
	}
}
