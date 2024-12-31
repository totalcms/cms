<?php

namespace TotalCMS\Domain\Property\Data;

use Cocur\Slugify\Slugify;

/**
 * Slug type property data.
 */
class SlugData extends PropertyData
{
	// Regular expression for allowed characters in slug.
	private const SLUGREGEX = '/([^A-Za-z0-9])+/';

	public string $slug;

	/** @param array<string,mixed> $settings */
	public function __construct(string $slug, array $settings = [])
	{
		$this->slug     = self::slugify($slug);
		$this->settings = $settings;
	}

	static public function slugify(string $slug): string
	{
		$slugify = new Slugify(['regexp' => self::SLUGREGEX]);
		$slugify->addRule("@", "-at-");
		return $slugify->slugify($slug);
	}

	public function transform(): string
	{
		return (string)$this;
	}

	public function __toString(): string
	{
		return $this->slug;
	}
}
