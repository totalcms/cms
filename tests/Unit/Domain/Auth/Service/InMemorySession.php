<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use Odan\Session\FlashInterface;
use Odan\Session\SessionInterface;

/**
 * Minimal in-memory SessionInterface stub that keeps state as a plain array
 * on an object, so mutations inside service calls are visible to the test.
 */
final class InMemorySession implements SessionInterface
{
	/** @param array<string, mixed> $data */
	public function __construct(private array $data = [])
	{
	}

	public function get(string $key, mixed $default = null): mixed
	{
		return $this->data[$key] ?? $default;
	}

	public function set(string $key, mixed $value): void
	{
		$this->data[$key] = $value;
	}

	public function has(string $key): bool
	{
		return isset($this->data[$key]);
	}

	public function delete(string $key): void
	{
		unset($this->data[$key]);
	}

	/** @return array<string, mixed> */
	public function all(): array
	{
		return $this->data;
	}

	public function setValues(array $values): void
	{
		foreach ($values as $k => $v) {
			$this->data[$k] = $v;
		}
	}

	public function clear(): void
	{
		$this->data = [];
	}

	public function getFlash(): FlashInterface
	{
		throw new \LogicException('not needed in tests');
	}
}
