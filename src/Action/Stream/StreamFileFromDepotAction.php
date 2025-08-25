<?php

namespace TotalCMS\Action\Stream;

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\DepotFileFetcher;
use TotalCMS\Domain\Property\Service\DepotPropertyManager;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

final class StreamFileFromDepotAction extends StreamAction
{
	public function __construct(
		private readonly DepotFileFetcher $fileFetcher,
		protected FileAccessManager $accessManager,
		protected ObjectUpdater $objectUpdater,
		protected PhpSession $session,
		private readonly PropertyFetcher $propFetcher,
	) {
	}

	protected function fileExists(): bool
	{
		return $this->fileFetcher->fileExists($this->collection, $this->id, $this->property, $this->name, $this->subpath);
	}

	protected function fetchFile(): FileData
	{
		return $this->fileFetcher->fetchFile($this->collection, $this->id, $this->property, $this->name, $this->subpath);
	}

	protected function loadFile(): void
	{
		$this->accessManager->loadDepotFile($this->collection, $this->id, $this->property);
	}

	protected function incrementCount(FileData $file): void
	{
		$depot = $this->propFetcher->fetchProperty($this->collection, $this->id, $this->property);

		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Expected instance of DepotData');
		}

		// increment the download count
		$depotManager = new DepotPropertyManager($depot);
		$depotManager->patchMeta($this->name, ['count' => $file->count + 1], $this->subpath);
		$this->objectUpdater->updateObjectProperty($this->collection, $this->id, $this->property, $depot->transform());
	}

	protected function streamFile()
	{
		return $this->fileFetcher->streamFile($this->collection, $this->id, $this->property, $this->name, $this->subpath);
	}
}
