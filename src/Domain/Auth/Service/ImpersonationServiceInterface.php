<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Auth\Exception\ImpersonationException;

/**
 * Contract for user impersonation. Extracted so actions can type-hint against
 * the interface and unit tests can mock it without removing the `final` keyword
 * from the concrete service.
 */
interface ImpersonationServiceInterface
{
	/**
	 * @throws ImpersonationException when a guard check fails
	 */
	public function start(string $collection, string $userId): void;

	public function stop(): void;

	public function isImpersonating(): bool;

	/** @return array{userId: string, collection: string}|null */
	public function impersonator(): ?array;
}
