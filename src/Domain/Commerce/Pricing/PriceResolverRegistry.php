<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Pricing;

/**
 * The price resolvers extensions have registered, in the order they are asked.
 */
final class PriceResolverRegistry
{
	/** @var array<string,array{resolver: PriceResolver, priority: int, order: int}> keyed by resolver id */
	private array $resolvers = [];

	/**
	 * @param int $priority lower is asked first
	 *
	 * @throws \LogicException when the id is empty, is `catalog`, or is already taken
	 */
	public function register(PriceResolver $resolver, int $priority = 0): void
	{
		$id = trim($resolver->id());
		if ($id === '' || $id === PricedUnit::CATALOG) {
			throw new \LogicException("A price resolver needs an id of its own; '{$id}' cannot be used.");
		}
		if (isset($this->resolvers[$id])) {
			throw new \LogicException("A price resolver with the id '{$id}' is already registered.");
		}

		$this->resolvers[$id] = ['resolver' => $resolver, 'priority' => $priority, 'order' => count($this->resolvers)];
	}

	/**
	 * Lowest priority first; resolvers of equal priority in the order they registered.
	 *
	 * @return list<PriceResolver>
	 */
	public function ordered(): array
	{
		$entries = array_values($this->resolvers);
		usort($entries, static fn (array $a, array $b): int => [$a['priority'], $a['order']] <=> [$b['priority'], $b['order']]);

		return array_column($entries, 'resolver');
	}
}
