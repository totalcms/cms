<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Migration;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Migration\Contract\MigrationInterface;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectFileCodec;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Moves the pre-3.5.1 builder-page `description` and `image` values onto the
 * page's `seo` card, where they now live exclusively.
 *
 * The schema has already dropped both properties, so every read through
 * ObjectFactory would discard them — the stored file is read raw instead,
 * through ObjectFileCodec so a markdown-format pages collection migrates too.
 * The card's value always wins: a page that already filled in the card keeps
 * it and only the stale top-level copy is dropped (which the schema-driven
 * re-save does on its own).
 *
 * Image FILES move with the value: a top-level image lives in
 * `{pages}/{id}/image/`, the card's in `{pages}/{id}/seo/image/`. The value
 * only moves when the files did — a card pointing at an empty directory is
 * worse than a page left for the next run.
 *
 * A failed move is the one condition that makes the whole migration THROW,
 * after the pages that did migrate have been written and indexed: the runner
 * leaves a throwing migration unrecorded, so the next request retries the pages
 * that were left behind. Everything else that stops a page — an unreadable or
 * undecodable file — is a logged skip, because retrying it forever would keep
 * the ledger open on a page no amount of retries will fix.
 *
 * Writes are SILENT and the index is rebuilt ONCE at the end. A migration runs
 * inside an ordinary request, and a non-silent write per page would fire
 * `object.updated` n times — n full index rebuilds (IndexBuildListener), n
 * search-provider pushes and n MCP subscription pulses for what is one bulk
 * edit. Dates are preserved so migrating does not look like an author edit.
 */
readonly class BuilderPageSeoFieldsMigration implements MigrationInterface
{
	public function __construct(
		private BuilderConfigService $builderConfig,
		private IndexReader $indexReader,
		private IndexBuilder $indexBuilder,
		private ObjectRepository $objectRepository,
		private ObjectFileCodec $codec,
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		private Config $config,
		private LoggerInterface $logger,
	) {
	}

	public function id(): string
	{
		return 'builder-page-seo-fields';
	}

	public function description(): string
	{
		return 'Move builder-page description and image onto the page SEO card.';
	}

	public function run(): int
	{
		if (!$this->builderConfig->pagesCollectionExists()) {
			return 0;
		}

		$collection = $this->builderConfig->getPagesCollectionId();
		$changed    = 0;
		/** @var list<string> $failed */
		$failed = [];

		foreach ($this->indexReader->fetchIndex($collection)->objects as $row) {
			$id = trim((string)($row['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			if ($this->migratePage($collection, $id, $failed)) {
				$changed++;
			}
		}

		// One rebuild for the whole batch — the index carries the `seo` block,
		// and the silent writes above deliberately skipped the per-page cascade.
		if ($changed > 0) {
			$this->indexBuilder->buildIndex($collection);
		}

		// Throw only AFTER the successful pages are safely written and indexed:
		// the runner records nothing for a migration that throws, so the next
		// request retries — and the pages above are already idempotent no-ops.
		if ($failed !== []) {
			throw new \RuntimeException(sprintf(
				'builder-page-seo-fields: could not move the image files for page(s) %s; fix permissions on %s/{id}/image and the migration will retry on the next request',
				implode(', ', $failed),
				$collection,
			));
		}

		return $changed;
	}

	/**
	 * True when the page carried a legacy value that has now moved onto the
	 * card. A page whose image files could not be moved is added to $failed and
	 * left untouched — see run(), which turns that into a retry.
	 *
	 * @param list<string> $failed
	 */
	private function migratePage(string $collection, string $id, array &$failed): bool
	{
		$stored = $this->readStored($collection, $id);
		if ($stored === null) {
			return false;
		}

		$seo   = is_array($stored['seo'] ?? null) ? $stored['seo'] : [];
		$moved = false;

		$description = (string)($stored['description'] ?? '');
		if (trim($description) !== '' && trim((string)($seo['description'] ?? '')) === '') {
			$seo['description'] = $description;
			$moved              = true;
		}

		$image = is_array($stored['image'] ?? null) ? $stored['image'] : [];
		if (trim((string)($image['name'] ?? '')) !== '' && !$this->cardHasImage($seo)) {
			// Metadata only moves if the files did. A card pointing at an empty
			// directory renders a broken og:image and orphans the real files;
			// leaving the page untouched lets run() throw so the next request
			// retries it.
			if ($this->moveImageFiles($collection, $id)) {
				$seo['image'] = $image;
				$moved        = true;
			} else {
				$failed[] = $id;

				return false;
			}
		}

		if (!$moved) {
			return false;
		}

		$this->writeCard($collection, $id, $seo);

		return true;
	}

	/**
	 * Merge the card in and re-save silently, keeping the page's own `updated`
	 * date. The re-save runs through the schema, which no longer declares
	 * either top-level property — so the stale copies leave the stored record
	 * here too.
	 *
	 * @param array<string,mixed> $seo
	 */
	private function writeCard(string $collection, string $id, array $seo): void
	{
		$merged        = $this->objectFetcher->fetchObject($collection, $id)->toArray();
		$merged['seo'] = $seo;

		$this->objectUpdater->updateObject($collection, $id, $merged, true, true);
	}

	/** @param array<string,mixed> $seo */
	private function cardHasImage(array $seo): bool
	{
		$image = is_array($seo['image'] ?? null) ? $seo['image'] : [];

		return trim((string)($image['name'] ?? '')) !== '';
	}

	/**
	 * The stored record as written on disk, before ObjectFactory strips the
	 * keys the schema no longer declares. Format follows the file's extension,
	 * the way ObjectRepository::formatOf() does. The body property is
	 * irrelevant here — only `description`, `image` and `seo` are read, and the
	 * re-save re-reads the object properly through the repository.
	 *
	 * @return array<string,mixed>|null
	 */
	private function readStored(string $collection, string $id): ?array
	{
		$relative = $this->objectRepository->objectPath($collection, $id);
		if ($relative === null) {
			$this->logger->warning('Builder page SEO migration: no stored file for page', [
				'collection' => $collection,
				'id'         => $id,
			]);

			return null;
		}

		$contents = @file_get_contents(PathUtils::absolutePath($this->config->datadir, $relative));
		if ($contents === false || $contents === '') {
			$this->logger->warning('Builder page SEO migration: page file is unreadable or empty', [
				'collection' => $collection,
				'id'         => $id,
				'path'       => $relative,
			]);

			return null;
		}

		$format = str_ends_with($relative, '.md') ? CollectionData::FORMAT_MARKDOWN : CollectionData::FORMAT_JSON;

		try {
			return $this->codec->decode($contents, $format, null);
		} catch (\UnexpectedValueException $e) {
			$this->logger->warning('Builder page SEO migration: page file could not be decoded', [
				'collection' => $collection,
				'id'         => $id,
				'path'       => $relative,
				'error'      => $e->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * Move `{pages}/{id}/image/` to `{pages}/{id}/seo/image/`.
	 *
	 * Returns true when the files are where the card expects them: moved now,
	 * no source directory to move (a record with metadata but no files), or a
	 * target that already exists (a half-finished earlier run). False means the
	 * move was attempted and failed, and the caller must leave the page alone
	 * for run() to report as a retry.
	 */
	private function moveImageFiles(string $collection, string $id): bool
	{
		$base = PathUtils::absolutePath($this->config->datadir, PathUtils::buildPath($collection, $id));
		$from = $base . '/image';
		$to   = $base . '/seo/image';

		if (!is_dir($from) || is_dir($to)) {
			return true;
		}

		$parent = dirname($to);
		// Clear first: error_get_last() is process-wide, so without this the
		// warning below can report a stale error from anywhere earlier in the
		// request as the reason this call failed.
		error_clear_last();
		if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
			$this->logger->warning('Builder page SEO migration: could not create the card image directory', [
				'collection' => $collection,
				'id'         => $id,
				'path'       => $parent,
				'error'      => error_get_last()['message'] ?? '',
			]);

			return false;
		}

		error_clear_last();
		if (!@rename($from, $to)) {
			$this->logger->warning('Builder page SEO migration: could not move the page image into the SEO card', [
				'collection' => $collection,
				'id'         => $id,
				'from'       => $from,
				'to'         => $to,
				'error'      => error_get_last()['message'] ?? '',
			]);

			return false;
		}

		return true;
	}
}
