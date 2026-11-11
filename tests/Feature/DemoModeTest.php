<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;

const DEMO_REFUSED = 'This site is in demo mode, so saving is disabled.';

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('playground');
});

/** A request with headers, which the Pest helpers don't take. */
function demoRequest(App $app, string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory->createServerRequest($method, $path);
	foreach ($headers as $name => $value) {
		$request = $request->withHeader($name, $value);
	}
	if ($body !== null) {
		$request = $request->withHeader('Content-Type', 'application/json')
			->withBody($factory->createStream((string)json_encode($body)));
	}

	return $app->handle($request);
}

function demoSnippet(string $id): array
{
	return ['id' => $id, 'name' => 'Demo', 'category' => 'Testing', 'snippet' => '{{ 1 + 1 }}'];
}

it('changes nothing until it is switched on', function (): void {
	$id = uniqid('demo-off-');
	postJson('/api/playground', demoSnippet($id))->assertOk();

	$this->assertFileExists(objectPath('playground', $id));
});

describe('with demo mode on', function (): void {
	beforeEach(function (): void {
		$config       = $this->app->getContainer()->get(Config::class);
		$config->demo = ['enable' => true];

		// Sign-in on, as on a real site, so the test shows demo mode turning it off.
		$auth           = $config->auth;
		$auth['enable'] = true;
		$config->auth   = $auth;
	});

	it('turns sign-in off', function (): void {
		expect($this->app->getContainer()->get(Config::class)->authEnabled())->toBeFalse();
	});

	it('opens the admin without a sign-in and says it is a demo', function (): void {
		$response = demoRequest($this->app, 'GET', '/admin/utils', ['Accept' => 'text/html']);

		expect($response->getStatusCode())->toBe(200);
		$html = (string)$response->getBody();
		expect($html)->toContain('demo-banner');
		expect($html)->toContain('This site is a demo.');
	});

	it('refuses a save with the demo message and writes nothing', function (): void {
		$response = demoRequest($this->app, 'POST', '/api/playground', [], demoSnippet('demo-on'));

		expect($response->getStatusCode())->toBe(403);
		expect(json_decode((string)$response->getBody(), true))->toBe(['error' => DEMO_REFUSED]);
		$this->assertFileDoesNotExist(objectPath('playground', 'demo-on'));
	});

	it('refuses a save in the language of the page that sent it', function (): void {
		$spanish = 'Este sitio está en modo de demostración, por lo que no se puede guardar.';

		$response = demoRequest($this->app, 'POST', '/api/playground', ['X-Locale' => 'es'], demoSnippet('demo-es'));
		expect($response->getStatusCode())->toBe(403);
		expect(json_decode((string)$response->getBody(), true))->toBe(['error' => $spanish]);
	});

	it('answers in English again when the next request names no language', function (): void {
		demoRequest($this->app, 'POST', '/api/playground', ['X-Locale' => 'es'], demoSnippet('demo-es'));

		$response = demoRequest($this->app, 'POST', '/api/playground', [], demoSnippet('demo-en'));
		expect(json_decode((string)$response->getBody(), true))->toBe(['error' => DEMO_REFUSED]);
	});

	it('refuses every method that could change something', function (string $method, string $path): void {
		$response = demoRequest($this->app, $method, $path, [], demoSnippet('anything'));

		expect($response->getStatusCode())->toBe(403);
		expect((string)$response->getBody())->toContain(DEMO_REFUSED);
	})->with([
		'POST'   => ['POST', '/api/collections/playground'],
		'PUT'    => ['PUT', '/api/collections/playground/anything'],
		'PATCH'  => ['PATCH', '/api/collections/playground/anything'],
		'DELETE' => ['DELETE', '/api/collections/playground/anything'],
	]);

	it('refuses a write dressed up as a GET through the method override', function (): void {
		// MethodOverrideMiddleware turns this into a PUT before the route sees it.
		$response = demoRequest($this->app, 'POST', '/api/playground/x', ['X-Http-Method-Override' => 'PUT'], demoSnippet('x'));

		expect($response->getStatusCode())->toBe(403);
	});

	it('still serves reads', function (): void {
		get('/api/playground')->assertOk();
	});

	it('answers HTMX with a message it can swap in', function (): void {
		$response = demoRequest($this->app, 'POST', '/admin/playground', ['HX-Request' => 'true']);

		expect($response->getStatusCode())->toBe(403);
		expect($response->getHeaderLine('Content-Type'))->toContain('text/html');
		expect((string)$response->getBody())->toContain('class="warning-box demo-refused"')
			->toContain(DEMO_REFUSED);
	});

	it('answers a plain admin form post with the access-denied page', function (): void {
		$response = demoRequest($this->app, 'POST', '/admin/playground', ['Accept' => 'text/html']);

		expect($response->getStatusCode())->toBe(403);
		expect((string)$response->getBody())->toContain(DEMO_REFUSED)->toContain('<html');
	});

	it('hides the Log Analyzer, whose logs hold visitors\' IP addresses', function (string $path): void {
		expect(demoRequest($this->app, 'GET', $path, ['Accept' => 'text/html'])->getStatusCode())->toBe(404);
	})->with(['/admin/utils/logs', '/admin/utils/logs/download']);

	it('leaves the Log Analyzer out of the Utilities menu and keeps the rest', function (): void {
		$html = (string)demoRequest($this->app, 'GET', '/admin/utils', ['Accept' => 'text/html'])->getBody();

		expect($html)->not->toContain('href="utils/logs"');
		expect($html)->toContain('href="utils/activity"');
	});
});
