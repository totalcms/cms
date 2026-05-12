<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Domain\Builder\Repository\ReloadPulseRepository;

readonly class BuilderReloadPulseService
{
	public function __construct(private ReloadPulseRepository $repository)
	{
	}

	public function pulse(string $path = ''): void
	{
		$this->repository->pulse($path);
	}

	public function currentTimestamp(): int
	{
		return $this->repository->currentTs();
	}

	/** @return array{ts:int,path:string}|null */
	public function current(): ?array
	{
		return $this->repository->current();
	}
}
