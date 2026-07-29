<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Service;

use TotalCMS\Domain\XmlRpc\Handler\MethodHandler;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;

/**
 * Dispatches a method name against a strict allowlist assembled from the
 * registered handlers. Anything not in the map faults before any work happens —
 * notably `system.multicall` and `pingback.*`, which are never registered.
 */
readonly class MethodRouter
{
	/** @var array<string,callable(array<int,mixed>,?string):mixed> */
	private array $methods;

	/** @param iterable<MethodHandler> $handlers */
	public function __construct(iterable $handlers)
	{
		$map = [];

		foreach ($handlers as $handler) {
			foreach ($handler->methods() as $name => $callable) {
				$map[$name] = $callable;
			}
		}

		$this->methods = $map;
	}

	/**
	 * @param array<int,mixed> $params
	 * @param string|null      $collection Collection pinned by the URL, or null
	 *                                     when the caller used /xmlrpc.php
	 */
	public function dispatch(string $method, array $params, ?string $collection): mixed
	{
		if (!isset($this->methods[$method])) {
			throw XmlRpcFault::unknownMethod($method);
		}

		return ($this->methods[$method])($params, $collection);
	}

	/** @return array<int,string> */
	public function methodNames(): array
	{
		$names = array_keys($this->methods);
		sort($names);

		return $names;
	}
}
