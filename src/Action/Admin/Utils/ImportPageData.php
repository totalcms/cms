<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Project Setup (Total CMS 1 detection + default collections), the Total
 * CMS 1 importer page, and the RSS importer's analyze step.
 */
final readonly class ImportPageData implements UtilsPageData
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private CollectionLister $collectionLister,
		private BuilderInstaller $builderInstaller,
		private RssImporter $rssImporter,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		$detection = null;
		if ($page === 'project-setup' || $page === 'import-totalcms-one') {
			$detection = $this->detectTotalCms1Data();

			// Create default collections when requested
			if ($action === 'default-collections') {
				$this->createDefaultCollections();
			}
		}

		$rssAnalysis = null;
		$rssError    = null;
		if ($page === 'import-rss' && $request->getMethod() === 'POST') {
			$post    = (array)$request->getParsedBody();
			$feedUrl = isset($post['url']) ? trim((string)$post['url']) : '';
			if ($feedUrl !== '') {
				try {
					$rssAnalysis = $this->rssImporter->analyze($feedUrl);
				} catch (\Throwable $e) {
					$rssError = $e->getMessage();
				}
			}
		}

		return [
			'totalcms1DetectionData' => $detection,
			'rssAnalysis'            => $rssAnalysis,
			'rssError'               => $rssError,
			'rssCollections'         => $rssAnalysis !== null ? $this->collectionLister->listAllCollections() : null,
		];
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @return array{path:string,source:string}|null
	 */
	private function detectTotalCms1Data(): ?array
	{
		// Check production location first
		$documentRoot   = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$productionPath = $documentRoot . '/cms-data';

		if (is_dir($productionPath)) {
			return [
				'path'   => $productionPath,
				'source' => 'production',
			];
		}

		// Check test data location
		$testPath = __DIR__ . '/../../../../tests/test-data/cms-data';
		$testPath = realpath($testPath);

		if ($testPath && is_dir($testPath)) {
			return [
				'path'   => $testPath,
				'source' => 'test',
			];
		}

		return null;
	}

	/**
	 * Create the default collections.
	 *
	 * Driven by an explicit list rather than every reserved schema: schemas that
	 * exist only to be embedded via `schemaref` (automation triggers, the MCP
	 * sub-objects, sitemap meta) would otherwise each get a junk top-level
	 * collection. See SchemaData::DEFAULT_COLLECTIONS.
	 */
	private function createDefaultCollections(): void
	{
		foreach (SchemaData::DEFAULT_COLLECTIONS as $schemaId) {
			$this->collectionFetcher->fetchOrCreateReserved($schemaId);
		}

		// Builder pages collection uses a different collection ID than schema ID
		$this->builderInstaller->ensurePagesCollection();
	}
}
