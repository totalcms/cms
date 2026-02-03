<?php

namespace TotalCMS\Domain\Collection\Data;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Data object.
 */
class CollectionData
{
	// Reserved names that cannot be used for collections
	public const RESERVED_NAMES = [
		'templates',
		'logs',
		'.schemas',
		'schemas',
	];

	private readonly Serializer $serializer;

	public string $id;                        // collection id
	public string $name;                      // collection name
	public string $schema;                    // schema name
	public string $category         = '';             // collection category for grouping in the admin
	public string $url              = '';                  // collection url to object page minus the slug
	public string $description      = '';          // collection description
	public string $labelPlural      = '';          // custom plural label for collection items
	public string $labelSingular    = '';        // custom singular label for collection items
	public string $sortBy           = 'id';             // the property to sort the collection by
	public bool $reverseSort        = false;         // reverse the sort order
	public bool $queueRebuildOnSave = false;  // queue a rebuild of the collection
	public bool $prettyUrl          = false;           // use pretty URLs for the collection
	public int $count               = 0;                    // total number of objects created in this collection
	public int $totalObjects        = -1;                // current number of objects (-1 = not calculated yet)
	public string $lastUpdated      = '';                // ISO 8601 datetime of last object modification

	/** @var array<string> */
	public array $groups = [];        // access groups that can access this collection

	/** @var array<string> */
	public array $publicOperations = [];  // operations allowed publicly (create, read, update, delete)

	/** @var array<string,array<string,mixed>> */
	public array $properties = [];        // Rules for fields defined in schemaToMetaProps

	/** @var array<string,array<string,mixed>> */
	public array $customProperties = [];  // Custom properties for specific objects

	/** @var array<string,mixed> */
	public array $formSettings = [];  // Custom settings for the object creation/edit forms

	/** @var array<string,array<string>> */
	public array $manualSort = [];  // Manual sort orders keyed by property name

	public function __construct()
	{
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		if (!$this->isValid()) {
			throw new \RuntimeException('CollectionData is not valid.');
		}
		$defaultDescription = "A collection of {$this->id} objects that conform to the {$this->schema} schema.";

		// Get default labels for the schema if not explicitly set
		$defaultLabels = self::getDefaultLabelsForSchema($this->schema);

		$description   = $this->description === '' ? $defaultDescription : $this->description;
		$labelPlural   = $this->labelPlural === '' ? $defaultLabels['labelPlural'] : $this->labelPlural;
		$labelSingular = $this->labelSingular === '' ? $defaultLabels['labelSingular'] : $this->labelSingular;

		$collection         = [
			'id'                 => $this->id,
			'schema'             => $this->schema,
			'name'               => $this->name ?? ucfirst($this->id),
			'description'        => $description,
			'url'                => $this->url ?? '',
			'category'           => $this->category ?? '',
			'labelPlural'        => $labelPlural,
			'labelSingular'      => $labelSingular,
			'groups'             => $this->groups ?? [],
			'publicOperations'   => $this->publicOperations ?? [],
			'sortBy'             => $this->sortBy ?? 'id',
			'reverseSort'        => $this->reverseSort ?? false,
			'prettyUrl'          => $this->prettyUrl ?? false,
			'queueRebuildOnSave' => $this->queueRebuildOnSave ?? false,
			'count'              => $this->count ?? 0,
			'totalObjects'       => max($this->totalObjects, 0),
			'lastUpdated'        => $this->lastUpdated ?? '',
		];

		if ($this->properties !== []) {
			$collection['properties'] = $this->properties;
		}

		if ($this->customProperties !== []) {
			$collection['customProperties'] = $this->customProperties;
		}

		if ($this->formSettings !== []) {
			$collection['formSettings'] = $this->formSettings;
		}

		if ($this->manualSort !== []) {
			$collection['manualSort'] = $this->manualSort;
		}

		return $collection;
	}

	public function isValid(): bool
	{
		return isset($this->id) && isset($this->schema);
	}

	public function toJson(): string
	{
		return $this->serializer->serialize($this->toArray(), 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
	}

	/**
	 * @param array<string,array<string,mixed>> $schema
	 *
	 * @return array<string,mixed>
	 */
	public static function schemaToMetaProps(array $schema): array
	{
		$metaProps = ['label', 'help', 'placeholder', 'field', 'options', 'settings'];

		foreach ($schema as $key => $prop) {
			// Only keep the meta properties that we need from the schema
			$schema[$key] = array_filter($prop, fn ($key): bool => in_array($key, $metaProps), ARRAY_FILTER_USE_KEY);
		}

		return $schema;
	}

	public static function objectUrl(CollectionData $collectionData, string $id): string
	{
		if ($collectionData->url === '') {
			return '';
		}

		if ($collectionData->prettyUrl) {
			$url = rtrim($collectionData->url, '/');

			return sprintf('%s/%s', $url, $id);
		}

		return sprintf('%s?id=%s', $collectionData->url, $id);
	}

	/**
	 * @return array{labelPlural: string, labelSingular: string}
	 */
	public static function getDefaultLabelsForSchema(string $schemaId): array
	{
		$defaults = [
			'auth'       => ['labelPlural' => 'Users', 'labelSingular' => 'User'],
			'blog'       => ['labelPlural' => 'Posts', 'labelSingular' => 'Post'],
			'code'       => ['labelPlural' => 'Snippets', 'labelSingular' => 'Snippet'],
			'playground' => ['labelPlural' => 'Snippets', 'labelSingular' => 'Snippet'],
			'mailer'     => ['labelPlural' => 'Emails', 'labelSingular' => 'Email'],
			'color'      => ['labelPlural' => 'Colors', 'labelSingular' => 'Color'],
			'date'       => ['labelPlural' => 'Dates', 'labelSingular' => 'Date'],
			'depot'      => ['labelPlural' => 'Depots', 'labelSingular' => 'Depot'],
			'email'      => ['labelPlural' => 'Emails', 'labelSingular' => 'Email'],
			'feed'       => ['labelPlural' => 'Posts', 'labelSingular' => 'Post'],
			'file'       => ['labelPlural' => 'Files', 'labelSingular' => 'File'],
			'gallery'    => ['labelPlural' => 'Galleries', 'labelSingular' => 'Gallery'],
			'image'      => ['labelPlural' => 'Images', 'labelSingular' => 'Image'],
			'number'     => ['labelPlural' => 'Numbers', 'labelSingular' => 'Number'],
			'styledtext' => ['labelPlural' => 'Content', 'labelSingular' => 'Styled Text'],
			'svg'        => ['labelPlural' => 'SVGs', 'labelSingular' => 'SVG'],
			'text'       => ['labelPlural' => 'Content', 'labelSingular' => 'Text'],
			'toggle'     => ['labelPlural' => 'Toggles', 'labelSingular' => 'Toggle'],
			'url'        => ['labelPlural' => 'URLs', 'labelSingular' => 'URL'],
		];

		return $defaults[$schemaId] ?? ['labelPlural' => '', 'labelSingular' => ''];
	}

	/**
	 * Normalize a URL to just the path component.
	 * Strips the scheme and domain if present.
	 *
	 * Examples:
	 *   https://example.com/page/ => /page/
	 *   /page/ => /page/
	 *   page/ => /page/
	 */
	public static function normalizeUrlToPath(string $url): string
	{
		if ($url === '') {
			return '';
		}

		// Parse the URL to extract just the path
		$parsed = parse_url($url);

		// If it has a host, it's a full URL - extract just the path
		if (isset($parsed['host'])) {
			$path = $parsed['path'] ?? '/';
		} else {
			// No host means it's already a path (or relative)
			$path = $url;
		}

		// Ensure path starts with /
		if ($path !== '' && $path[0] !== '/') {
			$path = '/' . $path;
		}

		return $path;
	}
}
