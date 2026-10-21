<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Service\TwigEngine;

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$this->render = fn (string $template): string => $this->app->getContainer()->get(TwigEngine::class)->renderString($template, []);
});

it('is reachable from a template as cms.badge', function (): void {
	expect(($this->render)("{{ cms.badge.pill({theme: 'dark'}) }}"))->toContain('src="https://totalcms.co/badges/pill-dark.svg"')
		->and(($this->render)("{{ cms.badge.earned('joeworkman') }}"))->toContain('/@joeworkman/badge.svg?style=earned');
});
