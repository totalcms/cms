<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Auth\Exception\AccessDeniedException;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Media\Exception\UploadRejectedException;
use TotalCMS\Domain\Media\Service\PropertyFileStore;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Service\DepotPropertyManager;
use TotalCMS\Domain\Property\Service\MediaMetaPolicy;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\RemoverFactory;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\RemoteFileDownloader;

/**
 * `attach_file_from_url` and `remove_file`: an agent puts a file on an
 * object's image, file, gallery or depot field, or takes one off, the same
 * way the admin's upload and delete buttons do ({@see PropertyFileStore},
 * {@see RemoverFactory}), so the same events fire and the same caches clear.
 *
 * Only top-level fields: an image inside a card or deck is out of reach. The
 * object must exist (create_object first). Access follows patch_object: the
 * caller needs update on the collection, the collection must be exposed to
 * MCP, and a member changes only objects they may change.
 *
 * The URL is the agent's choice, not the operator's, so it is fetched with
 * the public-only client: no private, loopback or link-local address, no
 * redirect into one. Each caller may attach {@see self::ATTACH_PER_HOUR}
 * files an hour.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") It fronts the upload and
 * delete pipelines and names their exceptions; splitting the two tools apart
 * would duplicate the shared access checks instead.
 */
final readonly class FileTools
{
	private const FILE_FIELDS = ['image', 'file', 'gallery', 'depot'];

	private const ATTACH_PER_HOUR = 60;

	private const RATE_PREFIX = 'mcp-attach-';

	public function __construct(
		private PropertyFileStore $store,
		private RemoverFactory $removers,
		private PropertyFetcher $propertyFetcher,
		private ObjectFetcher $objectFetcher,
		private ObjectPatcher $patcher,
		private SchemaFetcher $schemaFetcher,
		private PersonaContext $personaContext,
		private CacheManager $cache,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'attach_file_from_url',
			description: 'Download a file from a public URL onto an EXISTING object\'s image, file, gallery or depot field, as an upload in the admin does. An image or file field is replaced; a gallery gains the image at the end; a depot gains the file in `path` (a folder; a file of the same name there is refused unless replace is true). Optional meta sets descriptive keys on the new file in the same call: for an image or gallery alt, link, tags, featured, focalpoint, exif, credit ({author, authorUrl, source, sourceUrl, license}); for a file or depot download, comments, tags. Always send alt for an image. Only top-level fields; images inside cards or decks are not reachable. The URL must be public http(s). Returns the stored name, the field type and a preview URL.',
			access: 'admin',
			handler: $this->attachHandler(...),
			inputSchema: [
				'type'                 => 'object',
				'required'             => ['collection', 'id', 'property', 'url'],
				'additionalProperties' => false,
				'properties'           => [
					'collection' => ['type' => 'string', 'description' => 'Collection id containing the object.', 'examples' => ['blog']],
					'id'         => ['type' => 'string', 'description' => 'Id of an existing object. Use create_object first for a new one.', 'examples' => ['my-first-post']],
					'property'   => ['type' => 'string', 'description' => 'A top-level image, file, gallery or depot field. Use describe_collection to find them.', 'examples' => ['image', 'gallery']],
					'url'        => ['type' => 'string', 'description' => 'Public http(s) URL of the file to download.', 'examples' => ['https://images.pexels.com/photos/25650514/pexels-photo-25650514.jpeg']],
					'path'       => ['type' => 'string', 'description' => 'Depot only: the folder to put the file in, e.g. "docs/2026". Omit for the top level.'],
					'replace'    => ['type' => 'boolean', 'description' => 'Depot only: replace a file of the same name in that folder instead of refusing.'],
					// oneOf: the SDK hands an empty {} over as [], which a bare `type: object` refuses.
					'meta'       => ['oneOf' => [['type' => 'object'], ['type' => 'array', 'maxItems' => 0]], 'description' => 'Descriptive keys to set on the new file, e.g. {"alt": "Burrata on a plate", "credit": {"author": "Nadin Sh", "source": "Pexels"}}.'],
				],
			],
			annotations: new ToolAnnotations(
				title: 'Attach File From URL',
				readOnlyHint: false,
				destructiveHint: false,
				idempotentHint: false,
				openWorldHint: true,
			),
			requires: new ToolRequirement(domain: 'objects', operation: 'update', collectionArg: 'collection'),
		));

		$registry->register(new McpToolDefinition(
			name: 'remove_file',
			description: 'Delete ONE uploaded file from an object\'s image, file, gallery or depot field, as the admin\'s delete button does. The file is deleted permanently; confirm with the user first. Image or file field: just collection, id and property. Gallery: name is the image\'s stored name (see get_object). Depot: path is the file\'s path, "folder/name.pdf" or just "name.pdf"; folders cannot be removed this way. Only top-level fields.',
			access: 'admin',
			handler: $this->removeHandler(...),
			inputSchema: [
				'type'                 => 'object',
				'required'             => ['collection', 'id', 'property'],
				'additionalProperties' => false,
				'properties'           => [
					'collection' => ['type' => 'string', 'description' => 'Collection id containing the object.'],
					'id'         => ['type' => 'string', 'description' => 'Id of the object.'],
					'property'   => ['type' => 'string', 'description' => 'A top-level image, file, gallery or depot field.'],
					'name'       => ['type' => 'string', 'description' => 'Gallery only: the stored name of the image to remove.'],
					'path'       => ['type' => 'string', 'description' => 'Depot only: the file\'s path, "folder/name.pdf" or "name.pdf".'],
				],
			],
			annotations: new ToolAnnotations(
				title: 'Remove File',
				readOnlyHint: false,
				destructiveHint: true,
				idempotentHint: false,
				openWorldHint: false,
			),
			requires: new ToolRequirement(domain: 'objects', operation: 'update', collectionArg: 'collection'),
		));
	}

	/**
	 * @param array<mixed>|null $meta
	 *
	 * @return array{collection: string, id: string, property: string, type: string, name: string, preview: string}
	 */
	public function attachHandler(string $collection, string $id, string $property, string $url, ?string $path = null, bool $replace = false, ?array $meta = null): array
	{
		$tool    = 'attach_file_from_url';
		$type    = $this->fileField($tool, $collection, $id, $property);
		$subpath = $type === 'depot' ? $this->folder($path) : null;
		$meta ??= [];

		// Refused before anything is downloaded, so a bad edit leaves no file behind.
		$refused = array_is_list($meta) ? array_map(strval(...), array_keys($meta)) : MediaMetaPolicy::refusedKeys($type, [], $meta);
		if ($refused !== []) {
			throw new ToolCallException(sprintf('%s: meta for a %s field is an object of %s; refused: %s.', $tool, $type, implode(', ', MediaMetaPolicy::editableKeys($type)), implode(', ', $refused)));
		}

		$name = RemoteFileDownloader::filenameFor($url);
		if ($type === 'depot' && $this->depotHasFile($collection, $id, $property, $name, $subpath) && !$replace) {
			throw new ToolCallException(sprintf('%s: "%s" is already in that folder of %s. Pass replace: true to replace it, or remove_file first.', $tool, $name, $property));
		}

		$this->spendAttach($tool);

		try {
			$local = $this->store->fetch($url);
			if ($type === 'depot' && $replace && $this->depotHasFile($collection, $id, $property, $name, $subpath)) {
				$this->removers->generateRemoverService($collection, $property)->deleteFile($collection, $id, $property, $name, $subpath);
			}
			$stored = $this->store->store($collection, $id, $property, $local, $subpath);
		} catch (UploadRejectedException $e) {
			throw new ToolCallException(sprintf('%s: %s', $tool, $e->getMessage()), $e->getCode(), $e);
		} catch (AccessDeniedException|\RuntimeException|\InvalidArgumentException|\UnexpectedValueException $e) {
			throw new ToolCallException(sprintf('%s: could not attach %s: %s', $tool, $url, $e->getMessage()), $e->getCode(), $e);
		}

		if ($meta !== []) {
			$this->applyMeta($tool, $collection, $id, $property, $type, $this->metaEdit($type, $stored->name, $subpath, $meta));
		}

		return [
			'collection' => $collection,
			'id'         => $id,
			'property'   => $property,
			'type'       => $type,
			'name'       => $stored->name,
			'preview'    => $stored->preview,
		];
	}

	/**
	 * @return array{removed: bool, collection: string, id: string, property: string, name: string}
	 */
	public function removeHandler(string $collection, string $id, string $property, ?string $name = null, ?string $path = null): array
	{
		$tool    = 'remove_file';
		$type    = $this->fileField($tool, $collection, $id, $property);
		$stored  = $this->objectFetcher->fetchObject($collection, $id)->toArray()[$property] ?? [];
		$stored  = is_array($stored) ? $stored : [];
		$subpath = null;

		switch ($type) {
			case 'image':
			case 'file':
				$name = (string)($stored['name'] ?? '');
				if ($name === '') {
					throw new ToolCallException(sprintf('%s: %s has no file to remove.', $tool, $property));
				}
				break;
			case 'gallery':
				$names = array_map(static fn (mixed $image): string => is_array($image) ? (string)($image['name'] ?? '') : '', $stored);
				if ($name === null || !in_array($name, $names, true)) {
					throw new ToolCallException(sprintf('%s: give name, one of the images in %s: %s', $tool, $property, implode(', ', $names) ?: '(none)'));
				}
				break;
			default:
				$path    = trim((string)$path, '/');
				$subpath = $this->folder(str_contains($path, '/') ? dirname($path) : null);
				$name    = basename($path);
				if ($path === '' || !$this->depotHasFile($collection, $id, $property, $name, $subpath)) {
					throw new ToolCallException(sprintf('%s: give path, a file in %s ("folder/name.pdf" or "name.pdf"). Folders cannot be removed.', $tool, $property));
				}
		}

		try {
			$this->removers->generateRemoverService($collection, $property)->deleteFile($collection, $id, $property, $name, $subpath);
		} catch (AccessDeniedException|\RuntimeException|\InvalidArgumentException|\UnexpectedValueException $e) {
			throw new ToolCallException(sprintf('%s: could not remove "%s": %s', $tool, $name, $e->getMessage()), $e->getCode(), $e);
		}

		return ['removed' => true, 'collection' => $collection, 'id' => $id, 'property' => $property, 'name' => $subpath !== null ? $subpath . '/' . $name : $name];
	}

	/**
	 * The field's type, after the checks every call shares: the collection
	 * exists and is exposed, the object exists and the caller may change it,
	 * and the property is a top-level file field.
	 */
	private function fileField(string $tool, string $collection, string $id, string $property): string
	{
		try {
			$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
			$this->personaContext->exposedCollection($collection, 'access');
		} catch (\UnexpectedValueException $e) {
			throw new ToolCallException(sprintf('%s: collection "%s" not found. Use list_collections to discover available collections.', $tool, $collection), $e->getCode(), $e);
		} catch (ToolCallException $e) {
			throw new ToolCallException($tool . ': ' . lcfirst($e->getMessage()), $e->getCode(), $e);
		}

		if (!$this->objectFetcher->existsObject($collection, $id) || !$this->personaContext->mayChangeObject($collection, $id)) {
			throw new ToolCallException(sprintf('%s: object "%s/%s" not found. Use query_collection to discover ids, or create_object to create it.', $tool, $collection, $id));
		}

		$type = (string)($schema->properties[$property]['field'] ?? '');
		if (!in_array($type, self::FILE_FIELDS, true)) {
			throw new ToolCallException(sprintf('%s: "%s" is not an image, file, gallery or depot field of %s. Use describe_collection to find them; fields inside cards and decks are not reachable.', $tool, $property, $collection));
		}

		return $type;
	}

	/** A depot folder as storage expects it, or null for the top level. */
	private function folder(?string $path): ?string
	{
		$folder = PathUtils::sanitizeSubpath(trim((string)$path, '/.'));

		return $folder === '' ? null : $folder;
	}

	private function depotHasFile(string $collection, string $id, string $property, string $name, ?string $subpath): bool
	{
		$depot = $this->propertyFetcher->fetchProperty($collection, $id, $property);

		return $depot instanceof DepotData && (new DepotPropertyManager($depot))->fileExists($name, $subpath);
	}

	/**
	 * The meta as patch_object's media edit for this field type: one object
	 * for an image or file, one named item for a gallery or depot.
	 *
	 * @param array<mixed> $meta
	 *
	 * @return array<mixed>
	 */
	private function metaEdit(string $type, string $name, ?string $subpath, array $meta): array
	{
		return match ($type) {
			'gallery' => [[...$meta, 'name' => $name]],
			'depot'   => [[...$meta, 'path' => $subpath !== null ? $subpath . '/' . $name : $name]],
			default   => $meta,
		};
	}

	/** @param array<mixed> $edit */
	private function applyMeta(string $tool, string $collection, string $id, string $property, string $type, array $edit): void
	{
		$stored = $this->objectFetcher->fetchObject($collection, $id)->toArray()[$property] ?? null;
		$value  = (new MediaMetaEdit($tool, $collection))->apply($property, $type, $stored, $edit);

		try {
			$this->patcher->patchObject($collection, $id, ['id' => $id, $property => $value]);
		} catch (\DomainException|\UnexpectedValueException|AccessDeniedException $e) {
			throw new ToolCallException(sprintf('%s: the file was attached but its meta was not saved: %s', $tool, $e->getMessage()), $e->getCode(), $e);
		}
	}

	/**
	 * Count one attach against the caller's hourly allowance, or refuse.
	 * Keyed by the OAuth user, else the client, else the persona.
	 */
	private function spendAttach(string $tool): void
	{
		$caller = $this->personaContext->getUserId()
			?: $this->personaContext->getClientId()
			?: ($this->personaContext->isResolved() ? $this->personaContext->current()->value : 'anonymous');
		$key   = self::RATE_PREFIX . md5($caller);
		$count = $this->cache->getData($key);
		$count = is_int($count) ? $count : 0;

		if ($count >= self::ATTACH_PER_HOUR) {
			throw new ToolCallException(sprintf('%s: limit of %d files an hour reached. Try again later.', $tool, self::ATTACH_PER_HOUR));
		}

		$this->cache->storeData($key, $count + 1, 3600);
	}
}
