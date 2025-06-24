<?php

namespace TotalCMS\Domain\Schema\Data;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Schema Data object.
 */
final class SchemaData
{
	public const SCHEMA_PREFIX    = 'https://www.totalcms.co/schemas/';
	public const SCHEMA_VERSION   = 'https://json-schema.org/draft/2020-12/schema';
	public const RESERVED_SCHEMAS = [
		'auth',
		'blog-legacy',
		'blog',
		'color',
		'collection',
		'date',
		'depot',
		'email',
		'feed',
		'file',
		'gallery',
		'image',
		'new', // not allowed for /admin url routes
		'number',
		'schema',
		'styledtext',
		'svg',
		'text',
		'toggle',
		'url',
	];
	public const PROPERTY_TYPES = [
		// 'array',
		// 'object',
		'boolean',
		'color',
		'date',
		'deck',
		'depot',
		'email',
		'file',
		'gallery',
		'image',
		'list',
		'number',
		'password',
		'phone',
		'slug',
		'string',
		'svg',
		'time',
		'url',
	];
	public const PROPERTY_TYPE_TO_REF = [
		'color'    => 'https://www.totalcms.co/schemas/properties/color.json',
		'date'     => 'https://www.totalcms.co/schemas/properties/date.json',
		'deck'     => 'https://www.totalcms.co/schemas/properties/deck.json',
		'depot'    => 'https://www.totalcms.co/schemas/properties/depot.json',
		'email'    => 'https://www.totalcms.co/schemas/properties/email.json',
		'file'     => 'https://www.totalcms.co/schemas/properties/file.json',
		'gallery'  => 'https://www.totalcms.co/schemas/properties/gallery.json',
		'image'    => 'https://www.totalcms.co/schemas/properties/image.json',
		'list'     => 'https://www.totalcms.co/schemas/properties/list.json',
		'password' => 'https://www.totalcms.co/schemas/properties/password.json',
		'phone'    => 'https://www.totalcms.co/schemas/properties/phone.json',
		'rating'   => 'https://www.totalcms.co/schemas/properties/rating.json',
		'slug'     => 'https://www.totalcms.co/schemas/properties/slug.json',
		'svg'      => 'https://www.totalcms.co/schemas/properties/svg.json',
		'time'     => 'https://www.totalcms.co/schemas/properties/time.json',
		'url'      => 'https://www.totalcms.co/schemas/properties/url.json',
	];

	public string $id;
	public string $formgrid = '';
	public string $description;
	/** @var array<string,mixed> */
	public array $properties;
	/** @var array<string> */
	public array $required;
	/** @var array<string> */
	public array $index;
	protected Serializer $serializer;

	public function __construct()
	{
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		$array = [
			'$schema'     => self::SCHEMA_VERSION,
			'$id'         => self::SCHEMA_PREFIX . $this->id . '.json',
			'type'        => 'object',
			'id'          => $this->id,
			'description' => $this->description,
			'properties'  => $this->properties,
			'required'    => $this->required,
			'index'       => $this->index ?? [],
		];

		// Only include formgrid if it's not empty
		if (!empty($this->formgrid)) {
			$array['formgrid'] = $this->formgrid;
		}

		return $array;
	}

	public function toJson(): string
	{
		return $this->serializer->serialize($this->toArray(), 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
	}

	public function formGridToCss(): string
	{
		$grid = $this->formgrid;

		// Return empty string if no grid is defined
		if (empty($grid)) {
			return '';
		}

		// Split the grid into lines and normalize line endings
		$lines = preg_split('/\r\n|\r|\n/', trim($grid));
		if ($lines === false) {
			return '';
		}

		$columnCount = 1;
		$quotedLines = [];
		foreach ($lines as $line) {
			$trimmed = trim($line);
			// Skip empty lines
			if (empty($trimmed)) {
				continue;
			}

			// Validate grid area names (CSS identifier rules)
			$normalized = (string)preg_replace('/\s+/', ' ', $trimmed);
			$columns = explode(' ', $normalized);

			// Validate each area name
			foreach ($columns as $area) {
				if (!$this->isValidGridAreaName($area)) {
					// Skip invalid lines or throw exception
					continue 2;
				}
			}

			// Escape area names for CSS
			$escapedAreas = array_map(function($area) {
				return htmlspecialchars($area, ENT_QUOTES, 'UTF-8');
			}, $columns);

			$quotedLines[] = "'" . implode(' ', $escapedAreas) . "'";
			$columnCount = max($columnCount, count($columns));
		}

		// Return empty string if no valid lines
		if (empty($quotedLines)) {
			return '';
		}

		// Return the formatted CSS
		$areas = implode("\n\t\t\t", $quotedLines);
		return <<<CSS
		grid-template-areas:
			$areas;
		grid-template-columns: repeat($columnCount, 1fr);
		CSS;
	}

	/**
	 * Validate CSS grid area names according to CSS identifier rules.
	 */
	private function isValidGridAreaName(string $name): bool
	{
		// CSS identifier rules: must start with letter, underscore, or hyphen
		// followed by letters, digits, hyphens, or underscores
		// Also allow the special "." for empty grid cells
		return $name === '.' || preg_match('/^[a-zA-Z_-][a-zA-Z0-9_-]*$/', $name) === 1;
	}
}
