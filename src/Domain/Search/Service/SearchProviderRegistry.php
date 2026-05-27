<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Service;

/**
 * Holds registered SearchProvider instances + provides lookup by id.
 *
 * Pre-populated with the built-in TextSearchProvider at container build
 * time; extensions add more during their `register()` lifecycle phase
 * via `ExtensionContext::registerSearchProvider()`.
 *
 * Collision policy: strict deny. Re-registering an id throws — extensions
 * cannot shadow core providers. The registrar logs + skips on extension
 * collisions (caller catches the exception).
 */
final class SearchProviderRegistry
{
	/** @var array<string,SearchProvider> */
	private array $providers = [];

	public function register(SearchProvider $provider): void
	{
		$id = $provider->id();
		if (isset($this->providers[$id])) {
			throw new \LogicException(sprintf(
				'Already registered search provider id "%s". Provider IDs must be unique.',
				$id,
			));
		}
		$this->providers[$id] = $provider;
	}

	public function get(string $id): ?SearchProvider
	{
		return $this->providers[$id] ?? null;
	}

	/**
	 * The provider configured as the site-wide active one. Returns null
	 * when the requested id isn't registered; SearchService falls back
	 * to text search in that case.
	 */
	public function active(string $id): ?SearchProvider
	{
		return $this->providers[$id] ?? null;
	}

	/**
	 * @return list<SearchProvider>
	 */
	public function all(): array
	{
		return array_values($this->providers);
	}
}
