<?php

declare(strict_types=1);

namespace Tests\Fakes;

use TotalCMS\Domain\Commerce\Contract\DownloadSource;
use TotalCMS\Domain\Commerce\Download\Deliverable;
use TotalCMS\Domain\Commerce\Download\Delivery;

/**
 * A download source as an extension would add one: it has a file for the
 * products it was told about, kept somewhere else.
 */
final class FakeDownloadSource implements DownloadSource
{
	public bool $broken = false;

	/**
	 * @param array<string,string> $links product id => where its file is
	 * @param string               $type  the field type it delivers; `file` takes the place of the store's own
	 */
	public function __construct(public array $links = [], private readonly string $type = 'file')
	{
	}

	public function fieldType(): string
	{
		return $this->type;
	}

	public function id(): string
	{
		return 'fake';
	}

	public function find(string $collection, array $product): ?Deliverable
	{
		if ($this->broken) {
			throw new \RuntimeException('The fake source is down.');
		}
		$link = $this->links[$product['id'] ?? ''] ?? null;

		return $link === null ? null : new Deliverable(basename($link), 4096, 'fake');
	}

	public function deliver(string $collection, array $product): ?Delivery
	{
		$link = $this->links[$product['id'] ?? ''] ?? null;

		return $link === null ? null : Delivery::redirect(basename($link), $link);
	}
}
