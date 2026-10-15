<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

use Cocur\Slugify\Slugify;

/**
 * Slug type property data.
 */
class SlugData extends PropertyData implements \Stringable
{
	// Regular expression for allowed characters in slug.
	private const SLUGREGEX = '/([^A-Za-z0-9_])+/';

	private static ?Slugify $slugifier = null;

	public string $slug;

	public function __construct(string $slug = '', public array $settings = [])
	{
		$this->slug = self::slugify($slug);
	}

	public static function slugify(string $slug): string
	{
		return self::slugifier()->slugify($slug);
	}

	/**
	 * One Slugify per process. Its constructor activates all 21 bundled rule
	 * sets (thousands of addRule calls), and every ObjectData slugifies its id,
	 * so building one per call cost a listing page a fifth of its request.
	 */
	private static function slugifier(): Slugify
	{
		if (self::$slugifier === null) {
			self::$slugifier = new Slugify(['regexp' => self::SLUGREGEX]);
			self::$slugifier->addRule('@', '-at-');
		}

		return self::$slugifier;
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
