<?php

declare(strict_types=1);

use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

use function TotalCMS\Slim\Pest\postJson;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('automations');
});

function saveWebhookAutomation(object $container, string $id, string $auth, bool $sync, string $handler): void
{
	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => $id,
		'name'     => ucfirst($id),
		'enabled'  => true,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'webhook', 'auth' => $auth, 'sync' => $sync]],
		'handler'  => $handler,
	]);
}

it('accepts a none-auth webhook and queues a run (202)', function (): void {
	saveWebhookAutomation($this->app->getContainer(), 'hook', 'none', false, "<?php\n\nreturn function (\$ctx) { return \$ctx->args; };\n");

	$response = postJson('/automations/hook', ['order_id' => 42]);

	expect($response->getStatusCode())->toBe(202);
	$json = json_decode((string)$response->getBody(), true);
	expect($json)->toHaveKey('runId');
	expect($json['status'])->toBe('queued');
});

it('runs a sync webhook inline and returns the result', function (): void {
	saveWebhookAutomation($this->app->getContainer(), 'synchook', 'none', true, "<?php\n\nreturn function (\$ctx) { return ['echo' => \$ctx->args]; };\n");

	$response = postJson('/automations/synchook', ['x' => 1]);

	expect($response->getStatusCode())->toBe(200);
	$json = json_decode((string)$response->getBody(), true);
	expect($json['status'])->toBe('success');
	expect($json['return'])->toBe(['echo' => ['x' => 1]]);
});

it('surfaces handler error detail in a sync webhook outside production', function (): void {
	// Test env is 'test' (non-prod), so errors surface loudly for the builder.
	saveWebhookAutomation($this->app->getContainer(), 'boom', 'none', true, "<?php\n\nreturn function (\$ctx) { throw new \\RuntimeException('kaboom from handler'); };\n");

	$response = postJson('/automations/boom', []);

	expect($response->getStatusCode())->toBe(500);
	$json = json_decode((string)$response->getBody(), true);
	expect($json['status'])->toBe('failed');
	// Full detail (message + trace) is visible in dev/preview.
	expect($json['exception'])->toContain('kaboom from handler');
	expect($json['exception'])->toContain('#0'); // stack trace frame
});

it('hides handler error detail in a sync webhook in production', function (): void {
	// Flip to a true production request: the public caller must not see the
	// message or stack trace, only a generic failure. The app is rebuilt per
	// test (beforeEach), so this mutation does not leak.
	$this->app->getContainer()->get(TotalCMS\Support\Config::class)->env = 'prod';

	saveWebhookAutomation($this->app->getContainer(), 'boom-prod', 'none', true, "<?php\n\nreturn function (\$ctx) { throw new \\RuntimeException('secret path /var/www leaked'); };\n");

	$response = postJson('/automations/boom-prod', []);

	expect($response->getStatusCode())->toBe(500);
	$json = json_decode((string)$response->getBody(), true);
	expect($json['status'])->toBe('failed');
	expect($json['exception'])->toBe('Automation handler failed. See the server logs for details.');
	expect($json['exception'])->not->toContain('secret path');
	expect($json['exception'])->not->toContain('#0');
});

it('rejects an apiKey webhook with no key (401)', function (): void {
	saveWebhookAutomation($this->app->getContainer(), 'secure', 'apiKey', false, "<?php\n\nreturn function (\$ctx) { return true; };\n");

	expect(postJson('/automations/secure', [])->getStatusCode())->toBe(401);
});

it('fires an apiKey webhook for a key scoped to POST /automations', function (): void {
	$container = $this->app->getContainer();
	saveWebhookAutomation($container, 'syncsecure', 'apiKey', true, "<?php\n\nreturn function (\$ctx) { return ['ok' => true]; };\n");
	$key = $container->get(ApiKeyCreator::class)->createApiKey('hook', ['methods' => ['POST'], 'paths' => ['/automations']]);

	$response = postJson('/automations/syncsecure', ['x' => 1], ['X-API-Key' => $key->key]);

	expect($response->getStatusCode())->toBe(200);
	expect(json_decode((string)$response->getBody(), true)['return'])->toBe(['ok' => true]);
});

it('rejects an apiKey webhook for a key not scoped for /automations (401)', function (): void {
	// The key is valid but scoped to /collections, so authenticate() rejects it
	// against POST /automations — same 401 the REST API returns for a scope miss.
	$container = $this->app->getContainer();
	saveWebhookAutomation($container, 'secure2', 'apiKey', false, "<?php\n\nreturn function (\$ctx) { return true; };\n");
	$key = $container->get(ApiKeyCreator::class)->createApiKey('wrong', ['methods' => ['POST'], 'paths' => ['/collections']]);

	expect(postJson('/automations/secure2', [], ['X-API-Key' => $key->key])->getStatusCode())->toBe(401);
});

it('allows a sameOrigin webhook from the site\'s own host', function (): void {
	saveWebhookAutomation($this->app->getContainer(), 'form', 'sameOrigin', true, "<?php\n\nreturn function (\$ctx) { return ['ok' => true]; };\n");

	$response = postJson('/automations/form', ['name' => 'Joe'], [
		'X-Forwarded-Host' => 'mysite.test',
		'Origin'           => 'https://mysite.test',
	]);

	expect($response->getStatusCode())->toBe(200);
	expect(json_decode((string)$response->getBody(), true)['return'])->toBe(['ok' => true]);
});

it('allows a sameOrigin webhook via the Referer fallback when Origin is absent', function (): void {
	saveWebhookAutomation($this->app->getContainer(), 'form-ref', 'sameOrigin', true, "<?php\n\nreturn function (\$ctx) { return 'ok'; };\n");

	$response = postJson('/automations/form-ref', [], [
		'X-Forwarded-Host' => 'mysite.test',
		'Referer'          => 'https://mysite.test/contact',
	]);

	expect($response->getStatusCode())->toBe(200);
});

it('rejects a sameOrigin webhook from a different origin (403)', function (): void {
	saveWebhookAutomation($this->app->getContainer(), 'form2', 'sameOrigin', false, "<?php\n\nreturn function (\$ctx) { return true; };\n");

	expect(postJson('/automations/form2', [], [
		'X-Forwarded-Host' => 'mysite.test',
		'Origin'           => 'https://evil.test',
	])->getStatusCode())->toBe(403);
});

it('rejects a sameOrigin webhook with no Origin or Referer (403)', function (): void {
	saveWebhookAutomation($this->app->getContainer(), 'form3', 'sameOrigin', false, "<?php\n\nreturn function (\$ctx) { return true; };\n");

	expect(postJson('/automations/form3', [], ['X-Forwarded-Host' => 'mysite.test'])->getStatusCode())->toBe(403);
});

it('404s for an unknown webhook id', function (): void {
	expect(postJson('/automations/nope', [])->getStatusCode())->toBe(404);
});
