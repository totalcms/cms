<?php

declare(strict_types=1);

namespace TotalCMS\Action\Download;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\DepotFileFetcher;
use TotalCMS\Domain\Property\Service\DepotPropertyManager;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Handles `/download/{coll}/{id}/{property}/{path:.+}`.
 *
 * The trailing path is one of two things:
 *   - A nested file inside a card or deck-item (`directoryExists` returns true
 *     for `prop/{path}/`). The path becomes the FileFetcher subpath; the
 *     filename is derived from the nested FileData's `name`.
 *   - A depot filename (legacy), with optional folder via `?path=` query.
 *
 * Dispatch happens once at __invoke time so the abstract methods inherited
 * from DownloadAction can use the resolved fetcher transparently.
 */
class DownloadFileFromDepotAction extends DownloadAction
{
	private bool $isNested        = false;
	private string $nestedSubpath = '';

	public function __construct(
		private readonly DepotFileFetcher $depotFetcher,
		private readonly FileFetcher $fileFetcher,
		protected TwigRenderer $twigRenderer,
		protected FileAccessManager $accessManager,
		protected ObjectUpdater $objectUpdater,
		protected PhpSession $session,
		protected Config $config,
		private readonly PropertyFetcher $propFetcher,
		protected TranslationService $translator,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$rawPath   = $args['path'] ?? $args['name'] ?? '';
		$sanitized = PathUtils::sanitizeSubpath($rawPath);

		$this->isNested = $this->fileFetcher->isNestedDirectory(
			$args['collection'],
			$args['id'],
			$args['property'],
			$sanitized,
		);

		if ($this->isNested) {
			// Resolve filename from the nested FileData; the parent's `name` arg
			// (depot semantics) does not apply here. The base __invoke uses
			// `$args['name']` to populate `$this->name`, so set it now.
			$this->nestedSubpath = $sanitized;
			$file                = $this->fileFetcher->fetchFile(
				$args['collection'],
				$args['id'],
				$args['property'],
				$sanitized,
			);
			$args['name']        = $file->name;
		} elseif (!isset($args['name']) && isset($args['path'])) {
			// Depot fall-through: the route now uses `{path:.+}` instead of
			// `{name}`, so populate `name` from `path` for the parent's __invoke.
			$args['name'] = $args['path'];
		}

		return parent::__invoke($request, $response, $args);
	}

	protected function fileExists(): bool
	{
		if ($this->isNested) {
			return $this->fileFetcher->fileExists($this->collection, $this->id, $this->property, $this->nestedSubpath);
		}

		return $this->depotFetcher->fileExists($this->collection, $this->id, $this->property, $this->name, $this->subpath);
	}

	protected function fetchFile(): FileData
	{
		if ($this->isNested) {
			return $this->fileFetcher->fetchFile($this->collection, $this->id, $this->property, $this->nestedSubpath);
		}

		return $this->depotFetcher->fetchFile($this->collection, $this->id, $this->property, $this->name, $this->subpath);
	}

	protected function loadFile(): void
	{
		// Nested files carry their own per-leaf protection (password, groups);
		// pass the subpath so the access manager loads the right FileData.
		if ($this->isNested) {
			$this->accessManager->loadFile($this->collection, $this->id, $this->property, $this->nestedSubpath);

			return;
		}

		$this->accessManager->loadDepotFile($this->collection, $this->id, $this->property);
	}

	protected function incrementCount(FileData $file): void
	{
		// Nested file: bump the count on the leaf FileData via a meta patch so
		// siblings in the parent card/deck stay intact.
		if ($this->isNested) {
			$file->count++;
			$this->objectUpdater->updateNestedProperty(
				$this->collection,
				$this->id,
				$this->property,
				$this->nestedSubpath,
				$file->transform(),
			);

			return;
		}

		$depot = $this->propFetcher->fetchProperty($this->collection, $this->id, $this->property);

		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Expected instance of DepotData');
		}

		$depotManager = new DepotPropertyManager($depot);
		$depotManager->patchMeta($this->name, ['count' => $file->count + 1], $this->subpath);
		$this->objectUpdater->updateObjectProperty($this->collection, $this->id, $this->property, $depot->transform(), silent: true);
	}

	protected function streamFile()
	{
		if ($this->isNested) {
			return $this->fileFetcher->streamFile($this->collection, $this->id, $this->property, $this->nestedSubpath);
		}

		return $this->depotFetcher->streamFile($this->collection, $this->id, $this->property, $this->name, $this->subpath);
	}
}
