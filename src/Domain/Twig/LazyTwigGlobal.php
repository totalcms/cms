<?php

namespace TotalCMS\Domain\Twig;

/**
 * Lazy loading proxy for Twig global variables.
 * Only instantiates the wrapped object when actually accessed.
 */
final class LazyTwigGlobal
{
	private mixed $instance = null;
	private bool $loaded = false;

	public function __construct(
		private \Closure $factory
	) {
	}

	/**
	 * Magic method to proxy all method calls to the lazy-loaded instance.
	 *
	 * @param array<mixed> $arguments
	 */
	public function __call(string $name, array $arguments): mixed
	{
		return $this->getInstance()->{$name}(...$arguments);
	}

	/**
	 * Magic method to proxy property access to the lazy-loaded instance.
	 */
	public function __get(string $name): mixed
	{
		return $this->getInstance()->{$name};
	}

	/**
	 * Magic method to proxy property setting to the lazy-loaded instance.
	 */
	public function __set(string $name, mixed $value): void
	{
		$this->getInstance()->{$name} = $value;
	}

	/**
	 * Magic method to proxy property existence checks to the lazy-loaded instance.
	 */
	public function __isset(string $name): bool
	{
		return isset($this->getInstance()->{$name});
	}

	/**
	 * Magic method to allow the object to be treated as a string.
	 */
	public function __toString(): string
	{
		$instance = $this->getInstance();
		if (method_exists($instance, '__toString')) {
			return (string)$instance;
		}
		return get_class($instance) . '@' . spl_object_hash($instance);
	}

	/**
	 * Get the lazy-loaded instance, creating it if necessary.
	 */
	private function getInstance(): mixed
	{
		if (!$this->loaded) {
			$this->instance = ($this->factory)();
			$this->loaded = true;
		}

		return $this->instance;
	}
}