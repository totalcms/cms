<?php

declare(strict_types=1);

namespace TestVendor\BrokenRoutesExt;

use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use Twig\TwigFunction;

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		// Registers fine — nothing here calls the registrar.
		$context->addTwigFunction(new TwigFunction('broken_routes_marker', fn (): string => 'ok'));

		// The mistake: core passes a TotalCMS RouteCollector, so invoking this
		// callback throws a TypeError. Before the guard, that TypeError escaped
		// bootAll() and 500'd every request including the admin.
		$context->addRoutes(function (RouteCollectorProxy $group): void {
			$group->post('/compile', fn ($request, $response) => $response);
		});
	}

	public function boot(ExtensionContext $context): void
	{
		// Boot itself is fine.
	}
}
