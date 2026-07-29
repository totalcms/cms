<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Handler;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\XmlRpc\Service\MethodRouter;

/**
 * Capability discovery. Unauthenticated on purpose — clients call these before
 * they have credentials to decide which dialect to speak, and the response
 * reveals nothing but the method allowlist.
 *
 * Takes the raw container rather than `MethodRouter` directly: `MethodRouter`
 * builds its handler map from a generator that resolves `SystemHandler` as one
 * of the handlers, so a constructor-injected `MethodRouter` here is a genuine
 * cycle that PHP-DI refuses to build. Resolving it lazily inside
 * `supportedMethods()` defers the lookup until after `MethodRouter`'s own
 * constructor has finished (and PHP-DI has cached the singleton), breaking
 * the cycle.
 */
readonly class SystemHandler implements MethodHandler
{
	public function __construct(private ContainerInterface $container)
	{
	}

	/** @return array<string,callable(array<int,mixed>,?string):mixed> */
	public function methods(): array
	{
		return [
			'mt.supportedMethods'     => $this->supportedMethods(...),
			'system.listMethods'      => $this->supportedMethods(...),
			'mt.supportedTextFilters' => $this->supportedTextFilters(...),
		];
	}

	/**
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,string>
	 */
	public function supportedMethods(array $params, ?string $collection): array
	{
		return $this->container->get(MethodRouter::class)->methodNames();
	}

	/**
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,mixed>
	 */
	public function supportedTextFilters(array $params, ?string $collection): array
	{
		// T3 stores rich text as HTML; there is no Markdown/Textile filter stage
		// for a client to select, so the list is empty rather than absent.
		return [];
	}
}
