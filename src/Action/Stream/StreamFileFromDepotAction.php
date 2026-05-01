<?php

declare(strict_types=1);

namespace TotalCMS\Action\Stream;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\DepotFileFetcher;
use TotalCMS\Domain\Property\Service\DepotPropertyManager;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Stream counterpart of {@see \TotalCMS\Action\Download\DownloadFileFromDepotAction}.
 * See that class for the dispatch rationale (nested file vs depot file at the
 * same URL shape).
 */
class StreamFileFromDepotAction extends StreamAction
{
	private bool $isNested = false;
	private string $nestedSubpath = '';

	public function __construct(
		private readonly DepotFileFetcher $depotFetcher,
		private readonly FileFetcher $fileFetcher,
		private readonly PropertyRepository $storage,
		protected FileAccessManager $accessManager,
		protected ObjectUpdater $objectUpdater,
		protected PhpSession $session,
		private readonly PropertyFetcher $propFetcher,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$rawPath  = $args['path'] ?? $args['name'] ?? '';
		$sanitized = PathUtils::sanitizeSubpath($rawPath);

		$this->isNested = $sanitized !== '' && $this->storage->directoryExists(
			$args['collection'],
			$args['id'],
			$args['property'],
			$sanitized,
		);

		if ($this->isNested) {
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
		if ($this->isNested) {
			$this->accessManager->loadFile($this->collection, $this->id, $this->property, $this->nestedSubpath);

			return;
		}

		$this->accessManager->loadDepotFile($this->collection, $this->id, $this->property);
	}

	protected function incrementCount(FileData $file): void
	{
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

	protected function actualFileSize(): int
	{
		if ($this->isNested) {
			return $this->fileFetcher->fileSize($this->collection, $this->id, $this->property, $this->nestedSubpath);
		}

		return $this->depotFetcher->fileSize($this->collection, $this->id, $this->property, $this->name, $this->subpath);
	}

	protected function streamFile()
	{
		if ($this->isNested) {
			return $this->fileFetcher->streamFile($this->collection, $this->id, $this->property, $this->nestedSubpath);
		}

		return $this->depotFetcher->streamFile($this->collection, $this->id, $this->property, $this->name, $this->subpath);
	}
}
