<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Service\ImageSourceResolver;
use TotalCMS\Domain\ImageWorks\Service\WatermarkFactory;

// PHP-DI's autowiring skips optional constructor parameters, so a
// `?ImageSourceResolver $sources = null` was null in the running app and every
// markimage layer was dropped, while the hand-built test stack worked.

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
});

it('gets the record image lookup from the container', function (): void {
	$factory = $this->app->getContainer()->get(WatermarkFactory::class);

	expect((new ReflectionProperty(WatermarkFactory::class, 'sources'))->getValue($factory))
		->toBeInstanceOf(ImageSourceResolver::class);
});
