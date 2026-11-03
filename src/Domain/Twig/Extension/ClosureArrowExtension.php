<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Extension;

use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\CoreExtension;
use Twig\TwigFilter;

/**
 * The `map`, `filter`, `sort`, `reduce` and `find` filters, taking a Twig
 * arrow function only.
 *
 * Twig 3 accepts any PHP callable there, so `{{ ['id']|map('system') }}` in
 * a template an editor saves (playground, builder, data views, mailer) ran a
 * shell command (security advisory GHSA-95hr-9gqg-c9jw, 2026-11-03). Core's
 * own sandbox check refuses anything but a Closure, so these call the core
 * implementations as sandboxed — nothing else of the sandbox is switched
 * on. Twig 4 makes the same rule the default.
 *
 * Registered after CoreExtension, so these replace the core filters of the
 * same name.
 */
final class ClosureArrowExtension extends AbstractExtension
{
	/** @return list<TwigFilter> */
	public function getFilters(): array
	{
		return [
			new TwigFilter('map', static fn (Environment $env, mixed $array, mixed $arrow): mixed => CoreExtension::map($env, true, $array, $arrow), ['needs_environment' => true]),
			new TwigFilter('filter', static fn (Environment $env, mixed $array, mixed $arrow): mixed => CoreExtension::filter($env, true, $array, $arrow), ['needs_environment' => true]),
			new TwigFilter('sort', static fn (Environment $env, mixed $array, mixed $arrow = null): array => CoreExtension::sort($env, true, $array, $arrow), ['needs_environment' => true]),
			new TwigFilter('reduce', static fn (Environment $env, mixed $array, mixed $arrow, mixed $initial = null): mixed => CoreExtension::reduce($env, true, $array, $arrow, $initial), ['needs_environment' => true]),
			new TwigFilter('find', static fn (Environment $env, mixed $array, mixed $arrow): mixed => CoreExtension::find($env, true, $array, $arrow), ['needs_environment' => true]),
		];
	}
}
