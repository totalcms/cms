<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface;

/**
 * Curated allow-list of per-page middleware names → container service IDs.
 *
 * Pages declare their middleware by NAME (e.g. `auth`, `internal-only`),
 * not by class. The registry resolves names → instances via the container,
 * so middleware can have proper DI without exposing arbitrary class
 * instantiation through page records.
 *
 * Core middleware is registered in `config/container.php` boot. Extensions
 * register via `$context->addPageMiddleware($name, $serviceId)`.
 */
class PageMiddlewareRegistry
{
	/** @var array<string,string> name => container service ID */
	private array $services = [];

	public function __construct(private readonly ContainerInterface $container)
	{
	}

	/**
	 * Register a middleware under a stable name. Names must be lower-case
	 * letters, digits, and hyphens — the same alphabet used in URL paths
	 * and config keys, picked to keep the schema field human-friendly.
	 *
	 * Last-write-wins on collisions, matching how core/extension load order
	 * resolves: core registers first, extensions can override.
	 *
	 * @throws \InvalidArgumentException When the name is malformed.
	 */
	public function register(string $name, string $serviceId): void
	{
		if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
			throw new \InvalidArgumentException(
				"Invalid page middleware name '{$name}' — use lowercase letters, digits, and hyphens"
			);
		}

		$this->services[$name] = $serviceId;
	}

	public function has(string $name): bool
	{
		return isset($this->services[$name]);
	}

	/**
	 * Resolve a middleware instance by name. Returns null when the name is
	 * unknown or the container cannot produce an instance — callers (the
	 * runner) log + skip rather than failing the request, since a typo in
	 * a page record shouldn't bring the whole page down.
	 */
	public function resolve(string $name): ?PageMiddlewareInterface
	{
		$id = $this->services[$name] ?? null;
		if ($id === null) {
			return null;
		}

		try {
			$instance = $this->container->get($id);
		} catch (\Throwable) {
			return null;
		}

		return $instance instanceof PageMiddlewareInterface ? $instance : null;
	}

	/**
	 * @return list<string> sorted alphabetically — drives the schema picker
	 *                      and any human-readable listing.
	 */
	public function availableNames(): array
	{
		$names = array_keys($this->services);
		sort($names);

		return $names;
	}
}
