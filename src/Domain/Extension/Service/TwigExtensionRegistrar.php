<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Filters extension Twig registrations to prevent collisions with core,
 * then registers the safe items into the TwigEngine.
 *
 * - Core collisions (functions, filters, globals): blocked and logged
 * - Extension-to-extension collisions: allowed with warning, last wins
 */
final readonly class TwigExtensionRegistrar
{
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Filter extension Twig items against core and register the safe ones.
	 *
	 * @param list<TwigFunction>  $functions
	 * @param list<TwigFilter>    $filters
	 * @param array<string,mixed> $globals
	 */
	public function filterAndRegister(
		TwigEngine $twigEngine,
		TotalCMSTwigExtension $coreExtension,
		array $functions,
		array $filters,
		array $globals,
	): void {
		// Get core names directly from the extension class (avoids triggering Twig initialization)
		$coreFunctionNames = array_values(array_map(fn (TwigFunction $f): string => $f->getName(), $coreExtension->getFunctions()));
		$coreFilterNames   = array_values(array_map(fn (TwigFilter $f): string => $f->getName(), $coreExtension->getFilters()));
		/** @var list<string> $coreGlobalNames */
		$coreGlobalNames = array_keys($coreExtension->getGlobals());

		$filtered = $this->filter($functions, $filters, $globals, $coreFunctionNames, $coreFilterNames, $coreGlobalNames);

		if ($filtered['functions'] !== [] || $filtered['filters'] !== [] || $filtered['globals'] !== []) {
			$twigEngine->registerExtensionItems($filtered['functions'], $filtered['filters'], $filtered['globals']);
		}
	}

	/**
	 * Filter extension Twig items against core registrations.
	 *
	 * @param list<TwigFunction>   $functions
	 * @param list<TwigFilter>     $filters
	 * @param array<string,mixed>  $globals
	 * @param list<string>         $coreFunctionNames
	 * @param list<string>         $coreFilterNames
	 * @param list<string>         $coreGlobalNames
	 *
	 * @return array{functions: list<TwigFunction>, filters: list<TwigFilter>, globals: array<string,mixed>}
	 */
	public function filter(
		array $functions,
		array $filters,
		array $globals,
		array $coreFunctionNames,
		array $coreFilterNames,
		array $coreGlobalNames,
	): array {
		return [
			'functions' => $this->filterFunctions($functions, $coreFunctionNames),
			'filters'   => $this->filterFilters($filters, $coreFilterNames),
			'globals'   => $this->filterGlobals($globals, $coreGlobalNames),
		];
	}

	/**
	 * @param list<TwigFunction> $functions
	 * @param list<string>       $coreNames
	 *
	 * @return list<TwigFunction>
	 */
	private function filterFunctions(array $functions, array $coreNames): array
	{
		$core     = array_flip($coreNames);
		$seen     = [];
		$filtered = [];

		foreach ($functions as $fn) {
			$name = $fn->getName();
			if (isset($core[$name])) {
				$this->logger->warning("Twig function '{$name}' from extension blocked: conflicts with a core function.");

				continue;
			}
			if (isset($seen[$name])) {
				$this->logger->warning("Twig function '{$name}' registered by multiple extensions. Last registration wins.");
			}
			$seen[$name] = true;
			$filtered[]  = $fn;
		}

		return $filtered;
	}

	/**
	 * @param list<TwigFilter> $filters
	 * @param list<string>     $coreNames
	 *
	 * @return list<TwigFilter>
	 */
	private function filterFilters(array $filters, array $coreNames): array
	{
		$core     = array_flip($coreNames);
		$seen     = [];
		$filtered = [];

		foreach ($filters as $filter) {
			$name = $filter->getName();
			if (isset($core[$name])) {
				$this->logger->warning("Twig filter '{$name}' from extension blocked: conflicts with a core filter.");

				continue;
			}
			if (isset($seen[$name])) {
				$this->logger->warning("Twig filter '{$name}' registered by multiple extensions. Last registration wins.");
			}
			$seen[$name] = true;
			$filtered[]  = $filter;
		}

		return $filtered;
	}

	/**
	 * @param array<string,mixed> $globals
	 * @param list<string>        $coreNames
	 *
	 * @return array<string,mixed>
	 */
	private function filterGlobals(array $globals, array $coreNames): array
	{
		$core     = array_flip($coreNames);
		$filtered = [];

		foreach ($globals as $name => $value) {
			if (isset($core[$name])) {
				$this->logger->warning("Twig global '{$name}' from extension blocked: conflicts with a core global.");

				continue;
			}
			$filtered[$name] = $value;
		}

		return $filtered;
	}
}
