<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

readonly class DepotFileFetcher
{
	public function __construct(
		private PropertyRepository $storage,
		private PropertyFetcher $propFetcher,
	) {
	}

	public function fetchFile(string $collection, string $id, string $property, string $name, ?string $subpath = null): FileData
	{
		$depot = $this->propFetcher->fetchProperty($collection, $id, $property);

		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Unable to retrieve depot data');
		}

		$depotManager = new DepotPropertyManager($depot);
		$file         = $depotManager->fetchFile($name, $subpath);

		if (!$file instanceof FileData) {
			throw new \RuntimeException('Unable to retrieve file data');
		}

		return $file;
	}

	public function fileExists(string $collection, string $id, string $property, string $name, ?string $subpath = null): bool
	{
		return $this->storage->fileExists($collection, $id, $property, $name, $subpath);
	}

	/** @return resource */
	public function streamFile(string $collection, string $id, string $property, string $name, ?string $subpath = null)
	{
		return $this->storage->streamFile($collection, $id, $property, $name, $subpath);
	}
}
