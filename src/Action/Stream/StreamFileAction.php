<?php

namespace TotalCMS\Action\Stream;

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\FileFetcher;

final class StreamFileAction extends StreamAction
{
	public function __construct(
		protected FileFetcher $fileFetcher,
		protected FileAccessManager $accessManager,
		protected ObjectUpdater $objectUpdater,
		protected PhpSession $session,
	) {
	}

	protected function fetchFile(): FileData
	{
		return $this->fileFetcher->fetchFile($this->collection, $this->id, $this->property);
	}

	protected function fileExists(): bool
	{
		return $this->fileFetcher->fileExists($this->collection, $this->id, $this->property);
	}

	protected function loadFile(): void
	{
		$this->accessManager->loadFile($this->collection, $this->id, $this->property);
	}

	protected function incrementCount(FileData $file): void
	{
		$file->count = $file->count + 1;
		$this->objectUpdater->updateObjectProperty($this->collection, $this->id, $this->property, $file->transform());
	}

	protected function streamFile()
	{
		return $this->fileFetcher->streamFile($this->collection, $this->id, $this->property);
	}
}