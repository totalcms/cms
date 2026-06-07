<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

/**
 * Captures extension route registrations without adding them to Slim.
 *
 * Extensions call $group->get(), $group->post(), etc. on this object
 * during register(). The routes are stored for later matching.
 *
 * The optional $permission argument applies to ADMIN routes only and
 * controls who can reach the page: 'admin' (the default — super admins
 * only) or 'any' (any logged-in dashboard user). Pair it with the same
 * value on the AdminNavItem that links to the page. API and public
 * routes ignore it — their access is governed by API auth and the
 * public flag respectively.
 */
final class RouteCollector
{
	/** @var list<array{method: string, path: string, handler: mixed, public: bool, permission: string|null}> */
	private array $routes = [];

	public function __construct(
		private readonly bool $isPublic = false,
	) {
	}

	public function get(string $path, mixed $handler, ?string $permission = null): self
	{
		$this->routes[] = ['method' => 'GET', 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic, 'permission' => $permission];

		return $this;
	}

	public function post(string $path, mixed $handler, ?string $permission = null): self
	{
		$this->routes[] = ['method' => 'POST', 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic, 'permission' => $permission];

		return $this;
	}

	public function put(string $path, mixed $handler, ?string $permission = null): self
	{
		$this->routes[] = ['method' => 'PUT', 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic, 'permission' => $permission];

		return $this;
	}

	public function patch(string $path, mixed $handler, ?string $permission = null): self
	{
		$this->routes[] = ['method' => 'PATCH', 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic, 'permission' => $permission];

		return $this;
	}

	public function delete(string $path, mixed $handler, ?string $permission = null): self
	{
		$this->routes[] = ['method' => 'DELETE', 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic, 'permission' => $permission];

		return $this;
	}

	public function any(string $path, mixed $handler, ?string $permission = null): self
	{
		foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
			$this->routes[] = ['method' => $method, 'path' => $path, 'handler' => $handler, 'public' => $this->isPublic, 'permission' => $permission];
		}

		return $this;
	}

	/**
	 * @return list<array{method: string, path: string, handler: mixed, public: bool, permission: string|null}>
	 */
	public function getRoutes(): array
	{
		return $this->routes;
	}
}
