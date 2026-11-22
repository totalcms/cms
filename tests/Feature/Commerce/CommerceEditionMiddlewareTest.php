<?php

declare(strict_types=1);

use DI\Container;
use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Middleware\Commerce\CommerceEditionMiddleware;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\getJson;

/*
 * The gate on every commerce route. The test app's license is Pro with no
 * paid product, so out of the box commerce is one purchase away.
 */

beforeEach(function (): void {
	$this->setUpApp(bootstrap());

	$this->app->get('/commerce-gate-test', function ($request, $response) {
		$response->getBody()->write('through the gate');

		return $response;
	})->add(CommerceEditionMiddleware::class);

	/** The refusal's message: a path outside the admin is answered in JSON. */
	$this->message = fn ($response): string => (string)(json_decode((string)$response->getBody(), true)['error']['message'] ?? (string)$response->getBody());
});

test('a Pro site without the add-on is refused, and told where to add it', function (): void {
	$response = get('/commerce-gate-test');

	expect($response->getStatusCode())->toBe(403)
		->and(($this->message)($response))->toBe('Commerce requires Total CMS Pro and the Commerce add-on. Add Commerce: https://totalcms.co/commerce')
		->and((string)$response->getBody())->not->toContain('through the gate');
});

test('a site below Pro is refused with no add-on link: there is nothing to add yet', function (): void {
	$container = $this->app->getContainer();
	assert($container instanceof Container);
	$container->set(CommerceGate::class, new class() implements CommerceGate {
		public function allowed(): bool
		{
			return false;
		}

		public function status(): CommerceGateStatus
		{
			return CommerceGateStatus::NeedsPro;
		}
	});

	expect(($this->message)(get('/commerce-gate-test')->assertStatus(403)))->toBe('Commerce requires Total CMS Pro and the Commerce add-on.');
});

test('the refusal is JSON for a caller that asks for JSON', function (): void {
	$response = getJson('/commerce-gate-test');

	expect($response->getStatusCode())->toBe(403)
		->and($response->getHeaderLine('Content-Type'))->toContain('application/json')
		->and(($this->message)($response))->toStartWith('Commerce requires Total CMS Pro and the Commerce add-on.');
});

test('the add-on link comes from the site\'s settings', function (): void {
	$config           = $this->app->getContainer()->get(TotalCMS\Support\Config::class);
	$config->commerce = ['purchaseUrl' => 'https://reseller.example/commerce'] + $config->commerce;

	expect(($this->message)(get('/commerce-gate-test')))->toEndWith('Add Commerce: https://reseller.example/commerce');
});

test('with commerce enabled the request goes through', function (): void {
	enableCommerce($this->app);

	expect((string)get('/commerce-gate-test')->assertOk()->getBody())->toBe('through the gate');
});
