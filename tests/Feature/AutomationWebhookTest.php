<?php

declare(strict_types=1);

use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

use function Nekofar\Slim\Pest\postJson;

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

it('404s for an unknown webhook id', function (): void {
	expect(postJson('/automations/nope', [])->getStatusCode())->toBe(404);
});
