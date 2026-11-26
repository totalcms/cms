<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use TotalCMS\Domain\Commerce\Contract\DownloadSource;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\FileFetcher;

/**
 * A download kept on the site: the product's Download file. The site sends
 * its bytes itself, so the buyer is never given an address that lasts.
 */
final readonly class FileDownloadSource implements DownloadSource
{
	public const PROPERTY = 'download';

	public function __construct(private FileFetcher $files)
	{
	}

	public function id(): string
	{
		return 'file';
	}

	public function find(string $collection, array $product): ?Deliverable
	{
		$file = $this->file($collection, $product);

		return $file instanceof FileData ? new Deliverable($file->download !== '' ? $file->download : $file->name, $file->size, self::PROPERTY) : null;
	}

	public function deliver(string $collection, array $product): ?Delivery
	{
		$file = $this->file($collection, $product);
		if (!$file instanceof FileData) {
			return null;
		}

		return Delivery::stream(
			$file->download !== '' ? $file->download : $file->name,
			$file->mime,
			$this->files->streamFile($collection, $this->productId($product), self::PROPERTY),
		);
	}

	/** @param array<string,mixed> $product */
	private function file(string $collection, array $product): ?FileData
	{
		$id = $this->productId($product);
		if ($id === '' || !$this->files->fileExists($collection, $id, self::PROPERTY)) {
			return null;
		}

		$file = $this->files->fetchFile($collection, $id, self::PROPERTY);

		return $file->name !== '' ? $file : null;
	}

	/** @param array<string,mixed> $product */
	private function productId(array $product): string
	{
		return is_string($product['id'] ?? null) ? $product['id'] : '';
	}
}
