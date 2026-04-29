<?php

namespace TotalCMS\Action\Download;

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

class DownloadFileAction extends DownloadAction
{
	public function __construct(
		protected FileFetcher $fileFetcher,
		protected TwigRenderer $twigRenderer,
		protected FileAccessManager $accessManager,
		protected ObjectUpdater $objectUpdater,
		protected PhpSession $session,
		protected Config $config,
		protected TranslationService $translator,
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
		$file->count++;
		$this->objectUpdater->updateObjectPropertyQuietly($this->collection, $this->id, $this->property, $file->transform());
	}

	protected function streamFile()
	{
		return $this->fileFetcher->streamFile($this->collection, $this->id, $this->property);
	}
}
