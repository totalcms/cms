<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

/**
 * Captures extension route registrations without adding them to Slim.
 *
 * Extensions call $group->get(), $group->post(), etc. on this object
 * during register(). The routes are stored for later matching.
 */
final class RouteCollector
{
	/** @var list<array{method: string, path: string, handler: mixed, public: bool}> */
	private array $routes = [];

	public function __construct(
		private readonly bool $isPublic = false,
	) {
	}

	/** @param mixed $handler */
	public function get(string $path, mixed $handler): self
	{
		$this->routes[] = ['method' => 'GET', 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic];

		return $this;
	}

	/** @param mixed $handler */
	public function post(string $path, mixed $handler): self
	{
		$this->routes[] = ['method' => 'POST', 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic];

		return $this;
	}

	/** @param mixed $handler */
	public function put(string $path, mixed $handler): self
	{
		$this->routes[] = ['method' => 'PUT', 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic];

		return $this;
	}

	/** @param mixed $handler */
	public function patch(string $path, mixed $handler): self
	{
		$this->routes[] = ['method' => 'PATCH', 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic];

		return $this;
	}

	/** @param mixed $handler */
	public function delete(string $path, mixed $handler): self
	{
		$this->routes[] = ['method' => 'DELETE', 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic];

		return $this;
	}

	/** @param mixed $handler */
	public function any(string $path, mixed $handler): self
	{
		foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
			$this->routes[] = ['method' => $method, 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic];
		}

		return $this;
	}

	/**
	 * @return list<array{method: string, path: string, handler: mixed, public: bool}>
	 */
	public function getRoutes(): array
	{
		return $this->routes;
	}
}
