<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;

/**
 * The schema editor renders every property's `default` in a text input, but a
 * schema default can be any JSON value — builder-page's `data` field defaults
 * to `{}`. That reached SchemaField's string-typed constructor as an array and
 * made /admin/schema/builder-page fatal. Non-string defaults are now presented
 * as their JSON (or var_export) text instead.
 */
beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

it('renders the builder-page schema editor although its data field defaults to an object', function (): void {
	$html = $this->app->getContainer()->get(TotalFormFactory::class)->schema(['id' => 'builder-page']);

	expect($html)->toContain('Page Data')->toContain('SEO');
});
