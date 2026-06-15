<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Session\SessionKeys;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->importer = $this->app->getContainer()->get(JumpStartImporter::class);
	$this->objects  = $this->app->getContainer()->get(ObjectFetcher::class);
});

describe('JumpStart system-collection guard', function (): void {
	$automationDefinition = static fn (): array => [
		'name'    => 'Malicious kit',
		'objects' => [[
			'collection' => 'automations',
			'id'         => 'pwn',
			'data'       => ['title' => 'pwn', 'trigger' => 'webhook'],
		]],
	];

	it('refuses to import an automations object without authorization', function () use ($automationDefinition): void {
		$result = $this->importer->importFromDefinition($automationDefinition(), false, false);

		expect($result->success)->toBeFalse();
		$errors = $result->data['errors'] ?? [];
		expect(implode("\n", $errors))->toContain('refused');
		// The RCE-carrying object must never have been written.
		expect($this->objects->existsObject('automations', 'pwn'))->toBeFalse();
	});

	it('does not refuse the import when explicitly authorized (shell/super-admin)', function () use ($automationDefinition): void {
		$result = $this->importer->importFromDefinition($automationDefinition(), false, true);

		// It may still fail for unrelated reasons (e.g. the automations
		// collection not existing in this fixture), but it must NOT be a
		// system-collection refusal.
		$errors = $result->data['errors'] ?? [];
		expect(implode("\n", $errors))->not->toContain('refused');
	});

	it('still imports non-system collections normally without authorization', function (): void {
		$result = $this->importer->importFromDefinition([
			'name'    => 'Safe kit',
			'objects' => [[
				'collection' => 'blog',
				'id'         => 'hello',
				'data'       => ['title' => 'Hello', 'content' => 'World'],
			]],
		], false, false);

		$errors = $result->data['errors'] ?? [];
		expect(implode("\n", $errors))->not->toContain('refused');
	});

	// The guard is applied on EVERY write surface, not just objects: a
	// definition can install/define the `automations` collection via its
	// schema, a reserved- or custom-collection entry, or a factory block —
	// each is just as RCE-grade as writing an object. These cases lock the
	// guard onto each path so a regression in any one branch is caught.

	it('refuses an automations schema without authorization', function (): void {
		$result = $this->importer->importFromDefinition([
			'name'    => 'Malicious kit',
			'schemas' => [[
				'id'         => 'automations',
				'name'       => 'Automations',
				'type'       => 'object',
				'properties' => [],
			]],
		], false, false);

		expect($result->success)->toBeFalse();
		expect(implode("\n", $result->data['errors'] ?? []))->toContain('refused');
	});

	it('refuses an automations reserved collection without authorization', function (): void {
		$result = $this->importer->importFromDefinition([
			'name'        => 'Malicious kit',
			'collections' => ['reserved' => ['automations']],
		], false, false);

		expect($result->success)->toBeFalse();
		expect(implode("\n", $result->data['errors'] ?? []))->toContain('refused');
	});

	it('refuses an automations custom collection without authorization', function (): void {
		$result = $this->importer->importFromDefinition([
			'name'        => 'Malicious kit',
			'collections' => ['custom' => [[
				'id'     => 'automations',
				'name'   => 'Automations',
				'schema' => 'automations',
			]]],
		], false, false);

		expect($result->success)->toBeFalse();
		expect(implode("\n", $result->data['errors'] ?? []))->toContain('refused');
	});

	it('refuses an automations factory block without authorization', function (): void {
		$result = $this->importer->importFromDefinition([
			'name'    => 'Malicious kit',
			'factory' => [[
				'collection' => 'automations',
				'count'      => 1,
				'data'       => ['title' => 'word'],
			]],
		], false, false);

		expect($result->success)->toBeFalse();
		expect(implode("\n", $result->data['errors'] ?? []))->toContain('refused');
	});

	// End-to-end: proves the whole wiring (route → DualAuthMiddleware →
	// SyncImportAction → JumpStartImporter) refuses for a logged-in caller who
	// is not a super-admin. The unit tests prove the action passes the right
	// flag and the importer refuses; this proves they are actually connected on
	// the live `/api/sync/import` route — the path that was the bypass.
	it('refuses an automations push over /api/sync/import for a non-super-admin session', function (): void {
		/** @var PhpSession $session */
		$session = $this->app->getContainer()->get(PhpSession::class);
		if (!$session->isStarted()) {
			$session->start();
		}
		// Logged-in, but not a super-admin.
		$session->set(SessionKeys::AUTH_USER, 'bob');
		$session->set(SessionKeys::AUTH_COLLECTION, 'auth');

		$payload = json_encode([
			'name'    => 'Malicious mirror',
			'objects' => [[
				'collection' => 'automations',
				'id'         => 'pwn',
				'data'       => ['title' => 'pwn', 'trigger' => 'webhook'],
			]],
		]);

		$factory = new Psr17Factory();
		$request = $factory->createServerRequest('POST', '/api/sync/import')
			->withHeader('Content-Type', 'application/json')
			->withBody($factory->createStream((string)$payload));

		$response = $this->app->handle($request);
		$body     = (string)$response->getBody();

		expect($body)->toContain('refused');
		// And the RCE-carrying object must never have been written.
		expect($this->objects->existsObject('automations', 'pwn'))->toBeFalse();
	});
});
