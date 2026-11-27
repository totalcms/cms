<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Contract\DownloadSource;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * The places a product's download can be kept. A product has one Download
 * field, and the type of that field in the products collection's schema
 * picks the source: a file field is the product's own file, a url field is
 * its link, and an extension adds a source for a type of its own. A source
 * an extension adds takes the place of the store's own for the same type.
 */
final class DownloadSources
{
	/** The property a product's download is kept in, whatever its type. */
	public const PROPERTY = 'download';

	/** @var list<DownloadSource> */
	private array $added = [];

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly FileDownloadSource $file,
		private readonly UrlDownloadSource $url,
		LoggerFactory $loggerFactory,
		private readonly SchemaFetcher $schemas,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	public function add(DownloadSource $source): void
	{
		$this->added[] = $source;
	}

	/**
	 * The source that has this product's download, and what it has. A
	 * product whose schema has no Download field has none, and says nothing.
	 * A Download of a type no source handles has none either, and is logged:
	 * the store was set up for something it cannot deliver. A source that
	 * fails is passed over and logged, and there is no other for its type. A
	 * schema that cannot be read is logged and has none.
	 *
	 * @param array<string,mixed> $product the stored product
	 *
	 * @return array{0: DownloadSource, 1: Deliverable}|null
	 */
	public function for(string $collection, array $product): ?array
	{
		return $this->look($collection, $product)[0];
	}

	/**
	 * What {@see for()} finds, and whether that is known. Not known when the
	 * schema could not be read, nothing handles the Download field's type,
	 * or the source failed: the product may well have a download that could
	 * not be reached, which is not the same as having none.
	 *
	 * @param array<string,mixed> $product the stored product
	 *
	 * @return array{0: array{0: DownloadSource, 1: Deliverable}|null, 1: bool} what was found, and whether the answer can be trusted
	 */
	public function look(string $collection, array $product): array
	{
		try {
			$type = $this->fieldType($collection);
		} catch (\Throwable $e) {
			$this->logger->error(sprintf('The schema of the %s collection could not be read, so its products have no download', $collection), ['collection' => $collection, 'exception' => $e]);

			return [null, false];
		}

		if ($type === '') {
			return [null, true];
		}

		$source = $this->sourceFor($type);
		if (!$source instanceof DownloadSource) {
			$this->logger->error(sprintf('No download source handles the Download field type %s in the %s collection', $type, $collection), ['collection' => $collection, 'fieldType' => $type]);

			return [null, false];
		}

		try {
			$found = $source->find($collection, $product);
		} catch (\Throwable $e) {
			$this->logger->error('A download source failed and was passed over', ['source' => $source::class, 'exception' => $e]);

			return [null, false];
		}

		return [$found instanceof Deliverable && $found->name !== '' ? [$source, $found] : null, true];
	}

	/** The source for a field type: one an extension added, else the store's own. */
	private function sourceFor(string $type): ?DownloadSource
	{
		foreach ([...array_reverse($this->added), $this->file, $this->url] as $source) {
			if ($source->fieldType() === $type) {
				return $source;
			}
		}

		return null;
	}

	/**
	 * The field type of the collection's Download property, or '' when it has
	 * none. Throws when the collection's schema cannot be read.
	 */
	public function fieldType(string $collection): string
	{
		$property = $this->schemas->fetchSchemaForCollection($collection)->properties[self::PROPERTY] ?? null;
		if (!is_array($property)) {
			return '';
		}

		$field = $property['field'] ?? null;
		if (is_string($field) && $field !== '') {
			return $field;
		}

		// A property that gives no field takes the one its type's reference names.
		$ref = $property['$ref'] ?? null;

		return is_string($ref) ? basename($ref, '.json') : '';
	}
}
