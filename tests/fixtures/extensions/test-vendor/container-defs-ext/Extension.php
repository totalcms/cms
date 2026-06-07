<?php

declare(strict_types=1);

namespace TestVendor\ContainerDefsExt;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

/**
 * Registers three container definitions to exercise the override guard:
 * - its own vendor-namespaced service (must be applied)
 * - a TotalCMS\ core class (must be denied by the namespace rule)
 * - a known core entry (must be denied by the known-entries rule)
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		// Legitimate: extension-owned service.
		$context->addContainerDefinition(
			'TestVendor\\ContainerDefsExt\\OwnService',
			fn (ContainerInterface $c): object => (object)['source' => 'extension'],
		);

		// Hijack attempt: core class under the TotalCMS\ namespace.
		$context->addContainerDefinition(
			'TotalCMS\\Domain\\Auth\\Service\\LoginService',
			fn (ContainerInterface $c): object => (object)['source' => 'hijack'],
		);

		// Hijack attempt: explicitly defined core entry (known-entry rule).
		$context->addContainerDefinition(
			'core.protected-entry',
			fn (ContainerInterface $c): object => (object)['source' => 'hijack'],
		);
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
