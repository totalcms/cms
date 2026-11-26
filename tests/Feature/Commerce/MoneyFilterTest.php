<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;

/*
 * `{{ amount|money }}` prints an amount held in minor units, as orders hold
 * every amount. It is a core filter, so a receipt or an order page renders
 * the same whether or not the store is switched on.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->config = $this->app->getContainer()->get(Config::class);
	$this->render = fn (string $twig, array $vars = []): string => $this->app->getContainer()->get(TwigEngine::class)->renderString($twig, $vars);
});

test('prints minor units in the currency named', function (): void {
	expect(($this->render)("{{ 1999|money('USD') }}|{{ 1500|money('JPY') }}|{{ total|money(order.currency) }}", ['total' => 500000, 'order' => ['currency' => 'USD']]))
		->toBe('$19.99|¥1,500|$5,000.00');
});

test('uses the store currency when none is named', function (): void {
	$this->config->commerce = ['currency' => 'GBP'] + $this->config->commerce;

	expect(($this->render)('{{ 999|money }}'))->toBe('£9.99');
});

test("formats for the site's locale", function (): void {
	$this->config->locale = 'de_DE';

	expect(str_replace("\u{00A0}", ' ', ($this->render)("{{ 123456|money('EUR') }}")))->toBe('1.234,56 €');
});

test('prints nothing for a value that is not an amount, so a page never breaks on a missing field', function (): void {
	expect(($this->render)("[{{ missing|money('USD') }}][{{ ''|money('USD') }}][{{ 'abc'|money('USD') }}][{{ 19.99|money('USD') }}][{{ 100|money('dollars') }}]", ['missing' => null]))
		->toBe('[][][][][]');
});

test('takes a whole number that arrived as a string, as a form or an index gives it', function (): void {
	// A negative literal needs brackets in Twig (`(-500)|money`): the filter binds before the minus.
	expect(($this->render)("{{ '1999'|money('USD') }}|{{ 0|money('USD') }}|{{ refund|money('USD') }}|{{ (-500)|money('USD') }}", ['refund' => -500]))
		->toBe('$19.99|$0.00|-$5.00|-$5.00');
});
