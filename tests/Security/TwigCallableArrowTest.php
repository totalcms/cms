<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\ContainerFactory;

/**
 * GHSA-95hr-9gqg-c9jw: Twig 3 accepts any PHP callable as the arrow of
 * `map`, `filter`, `sort`, `reduce` and `find`, so `{{ ['id']|map('system') }}`
 * in a template an editor could save (playground, builder, data views,
 * mailer) ran a shell command. Only a Twig arrow function — a Closure — is
 * accepted now, as Twig 4 will require.
 */
function arrowTwig(): TwigEngine
{
	static $twig = null;

	return $twig ??= ContainerFactory::build()->get(TwigEngine::class);
}

it('refuses a PHP function name as the callable', function (string $template): void {
	expect(fn () => arrowTwig()->renderString($template))
		->toThrow(\Twig\Error\RuntimeError::class, 'must be a Closure');
})->with([
	'map'    => ["{{ ['tcms-arrow-probe']|map('strtoupper')|join }}"],
	'filter' => ["{{ ['tcms-arrow-probe']|filter('strlen')|join }}"],
	'sort'   => ["{{ [2, 1]|sort('strcmp')|join }}"],
	'reduce' => ["{{ [1, 2]|reduce('max', 0) }}"],
	'find'   => ["{{ ['tcms-arrow-probe']|find('strlen') }}"],
	'static' => ["{{ ['a']|map('TotalCMS\\\\Support\\\\SameOriginRedirect::resolve')|join }}"],
]);

it('still runs Twig arrow functions', function (string $template, string $expected): void {
	expect(arrowTwig()->renderString($template))->toBe($expected);
})->with([
	'map'    => ['{{ ["a", "b"]|map(v => v|upper)|join(",") }}', 'A,B'],
	'filter' => ['{{ [1, 2, 3]|filter(v => v > 1)|join(",") }}', '2,3'],
	'sort'   => ['{{ [3, 1, 2]|sort((a, b) => a <=> b)|join(",") }}', '1,2,3'],
	'sort, no arrow' => ['{{ [3, 1, 2]|sort|join(",") }}', '1,2,3'],
	'reduce' => ['{{ [1, 2, 3]|reduce((c, v) => c + v, 10) }}', '16'],
	'find'   => ['{{ [1, 5, 9]|find(v => v > 4) }}', '5'],
]);
