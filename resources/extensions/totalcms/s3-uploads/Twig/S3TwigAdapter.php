<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Twig;

use Psr\Log\LoggerInterface;
use TotalCMS\Bundled\S3Uploads\S3\S3Settings;
use TotalCMS\Bundled\S3Uploads\Serving\DownloadCounter;
use TotalCMS\Bundled\S3Uploads\Serving\UrlResolver;
use TotalCMS\Bundled\S3Uploads\Storage\KeyBuilder;
use TotalCMS\Bundled\S3Uploads\Storage\Pointer;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Bundled\S3Uploads\Upload\UploadRules;
use TotalCMS\Domain\Object\Data\ObjectData;
use Twig\Error\RuntimeError;

/**
 * The `s3` Twig global.
 *
 *     {{ s3.url(post, {collection: 'blog', property: 'brochure'}) }}
 *     {{ s3.download(post, {collection: 'blog', property: 'brochure'}) }}
 *     {{ s3.size(post, {collection: 'blog', property: 'brochure'}) }}
 *     {{ s3.downloads(post, {collection: 'blog', property: 'brochure'}) }}
 *     {{ s3.totalDownloads(post, {collection: 'blog', property: 'brochure'}) }}
 *     {% for f in s3.files(doc, {collection: 'docs', property: 'files'}) %}…{% endfor %}
 *
 * The first argument is the object or its id, as with `cms.media.download()`:
 * the counted link needs the collection, the object's id and the property,
 * which the stored value alone does not carry. Pass the object when the
 * template already has it; an id costs a lookup.
 *
 *     {{ s3.url('spring-brochure', {collection: 'blog', property: 'brochure'}) }}
 *
 * Options: `collection` and `property` (both required), `file` (the id of one
 * file in an s3depot), `direct` (link to the bucket instead of the counted
 * route), `expires` (seconds a signed link lasts).
 */
final readonly class S3TwigAdapter
{
	/** @param \Closure(string, string): (array<string,mixed>|null) $fetchObject (collection, id): the object, or null when there is none */
	public function __construct(
		private UrlResolver $urls,
		private DownloadCounter $counter,
		private S3Settings $settings,
		private KeyBuilder $keys,
		private bool $dev,
		private LoggerInterface $logger,
		private \Closure $fetchObject,
	) {
	}

	/**
	 * A link to the file: the counted route on this site, or with `direct` the
	 * bucket itself (a signed link when the bucket is private).
	 *
	 * @param array<string,mixed> $options
	 */
	public function url(mixed $object, array $options = []): string
	{
		$file = $this->file($object, $options, 'url');
		if ($file === null) {
			return '';
		}

		return $this->link($file['pointer'], $file, $options);
	}

	/**
	 * The counted link that makes the browser save the file under its original name.
	 *
	 * @param array<string,mixed> $options
	 */
	public function download(mixed $object, array $options = []): string
	{
		$file = $this->file($object, $options, 'download');

		return $file === null ? '' : $this->urls->countedUrl($file['collection'], $file['id'], $file['property'], $file['fileId'], true);
	}

	/**
	 * The file's size for people: "46 MB".
	 *
	 * @param array<string,mixed> $options
	 */
	public function size(mixed $object, array $options = []): string
	{
		$file = $this->file($object, $options, 'size');

		return $file === null ? '' : UploadRules::formatSize($file['pointer']->size);
	}

	/**
	 * How many times the file was downloaded through its counted link.
	 *
	 * @param array<string,mixed> $options
	 */
	public function downloads(mixed $object, array $options = []): int
	{
		$file = $this->file($object, $options, 'downloads');

		return $file === null ? 0 : $this->counter->count($file['collection'], $file['id'], $this->counterKey($file['property'], $file['fileId']));
	}

	/**
	 * Every download the property has had through its counted links, across
	 * all the files it has held. Replacing or removing the file does not reset
	 * it, where `downloads()` counts the current file only.
	 *
	 * @param array<string,mixed> $options
	 */
	public function totalDownloads(mixed $object, array $options = []): int
	{
		$context = $this->context($object, $options, 'totalDownloads');

		return $context === null ? 0 : $this->counter->total($context['collection'], $context['id'], $context['property']);
	}

	/**
	 * Every file of the property, in stored order: all of an s3depot's, or an s3file's one.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return list<array{id:string, name:string, size:int, sizeLabel:string, mime:string, duration:float|null, url:string, download:string, downloads:int}>
	 */
	public function files(mixed $object, array $options = []): array
	{
		$context = $this->context($object, $options, 'files');
		if ($context === null) {
			return [];
		}

		$counts = $this->counter->forObject($context['collection'], $context['id']);
		$files  = [];
		foreach ($this->pointers($context['value']) as $pointer) {
			$fileId  = $context['depot'] ? $pointer->id : null;
			$file    = ['fileId' => $fileId] + $context;
			$files[] = [
				'id'        => $pointer->id,
				'name'      => $pointer->name,
				'size'      => $pointer->size,
				'sizeLabel' => UploadRules::formatSize($pointer->size),
				'mime'      => $pointer->mime,
				'duration'  => $pointer->duration,
				'url'       => $this->link($pointer, $file, $options),
				'download'  => $this->urls->countedUrl($context['collection'], $context['id'], $context['property'], $fileId, true),
				'downloads' => $counts[$this->counterKey($context['property'], $fileId)] ?? 0,
			];
		}

		return $files;
	}

	/**
	 * @param array{collection:string, id:string, property:string, fileId:string|null} $file
	 * @param array<string,mixed>                                                      $options
	 */
	private function link(Pointer $pointer, array $file, array $options): string
	{
		$counted = $this->urls->countedUrl($file['collection'], $file['id'], $file['property'], $file['fileId']);
		if (($options['direct'] ?? false) !== true) {
			return $counted;
		}
		if (!$this->settings->isPrivate()) {
			return $this->urls->publicUrl($pointer->key);
		}

		if (($options['podcast'] ?? false) === true) {
			// Podcast apps keep an enclosure URL for years; a signed link dies in hours.
			$this->complain('Podcast enclosures need a URL that does not expire. Remove direct: true or make the bucket public.');

			return $counted;
		}

		// A signed link expires. Nothing tells `{% cache %}` so: a template that
		// caches one keeps the block for less time than the link lasts (see the docs).
		return $this->urls->directUrl($pointer, is_numeric($options['expires'] ?? null) ? (int)$options['expires'] : null);
	}

	/**
	 * The one file the options name: an s3file's pointer, or the depot item whose id is `file`.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return array{collection:string, id:string, property:string, fileId:string|null, pointer:Pointer}|null
	 */
	private function file(mixed $object, array $options, string $method): ?array
	{
		$context = $this->context($object, $options, $method);
		if ($context === null) {
			return null;
		}

		$wanted = is_scalar($options['file'] ?? null) ? (string)$options['file'] : '';
		foreach ($this->pointers($context['value']) as $pointer) {
			if ($context['depot'] ? $pointer->id === $wanted : true) {
				return [
					'collection' => $context['collection'],
					'id'         => $context['id'],
					'property'   => $context['property'],
					'fileId'     => $context['depot'] ? $pointer->id : null,
					'pointer'    => $pointer,
				];
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array{collection:string, id:string, property:string, value:mixed, depot:bool}|null
	 */
	private function context(mixed $object, array $options, string $method): ?array
	{
		$collection = is_string($options['collection'] ?? null) ? trim($options['collection']) : '';
		$property   = is_string($options['property'] ?? null) ? trim($options['property']) : '';
		if ($collection === '' || $property === '') {
			$this->complain("s3.{$method}() needs the collection and property options, like {collection: 'blog', property: 'brochure'}.");

			return null;
		}

		if ($object instanceof ObjectData) {
			$object = $object->toArray();
		}
		if (is_string($object) || is_int($object)) {
			// An id, as core's media helpers take. An empty one is an unset relation, not a mistake.
			$wanted = trim((string)$object);
			$object = $wanted === '' ? null : ($this->fetchObject)($collection, $wanted);
			if ($wanted !== '' && $object === null) {
				$this->complain("s3.{$method}() found no object '{$wanted}' in the {$collection} collection.");
			}
		}
		if (!is_array($object) && !$object instanceof \ArrayAccess) {
			return null;
		}

		$id = $object['id'] ?? null;
		if (!is_scalar($id) || (string)$id === '') {
			return null;
		}
		$value = $object[$property] ?? null;

		return [
			'collection' => $collection,
			'id'         => (string)$id,
			'property'   => $property,
			'value'      => $value,
			// The stored shape says which field this is: a depot is a list.
			'depot'      => is_array($value) && array_is_list($value) && $value !== [],
		];
	}

	/**
	 * The valid pointers in a stored value that are under this install's prefix.
	 *
	 * @return list<Pointer>
	 */
	private function pointers(mixed $value): array
	{
		$field    = is_array($value) && array_is_list($value) ? S3Fields::DEPOT : S3Fields::FILE;
		$pointers = S3Fields::pointers($value, $field);

		return array_values(array_filter($pointers, fn (Pointer $p): bool => $this->keys->isInsidePrefix($p->key)));
	}

	private function counterKey(string $property, ?string $fileId): string
	{
		return $fileId === null ? $property : $property . '/' . $fileId;
	}

	/**
	 * A template mistake: loud while developing, quiet on a live site.
	 *
	 * @throws RuntimeError in dev
	 */
	private function complain(string $message): void
	{
		if ($this->dev) {
			throw new RuntimeError($message);
		}
		$this->logger->warning('S3 Uploads: ' . $message);
	}
}
