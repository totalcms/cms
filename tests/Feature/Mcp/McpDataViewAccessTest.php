<?php

declare(strict_types=1);

use TotalCMS\Domain\DataView\Repository\DataViewRepository;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectSaver;

require_once __DIR__ . '/McpAuthHelpers.php';

/*
 * Data views over MCP follow REST's two gates: the Data Views edition, and,
 * for an OAuth member, a group that grants data views. A view's data is built
 * with full trust, so collection grants and ownership do not narrow it: the
 * data views grant is the whole gate. A public view needs no grant.
 */

/**
 * Save a view at MCP access $access, exposed as a resource, with cached data,
 * and return its id.
 */
function dataViewAccessSeedView(mixed $app, string $prefix, string $access): string
{
	$id = $prefix . '-' . bin2hex(random_bytes(4));
	$container = $app->getContainer();
	mcpAuthEnsureDataviewsCollection($app);
	$container->get(ObjectSaver::class)->saveObject('dataviews', [
		'id'         => $id,
		'name'       => 'Members and orders',
		'definition' => '{{ [] }}',
		'mcp'        => ['access' => $access, 'resource' => true],
	]);
	$container->get(DataViewRepository::class)->saveData($id, ['m1' => ['member' => 'm1', 'total' => 42]]);

	return $id;
}

/**
 * Give access group $groupId the data views grant.
 */
function dataViewAccessGrantViews(string $groupId): void
{
	$path   = cmsDataDir() . '/.system/access-groups.json';
	$groups = json_decode((string)file_get_contents($path), true);
	$list   = isset($groups['groups']) ? $groups['groups'] : $groups;
	foreach ($list as $index => $group) {
		if (($group['id'] ?? '') === $groupId) {
			$list[$index]['permissions']['dataviews'] = true;
		}
	}
	file_put_contents($path, (string)json_encode(isset($groups['groups']) ? ['groups' => $list] : $list, JSON_PRETTY_PRINT));
}

/**
 * Open an MCP session as fixture user $userId and return a caller for it.
 *
 * @return Closure(string, array<string,mixed>): array<string,mixed>
 */
function dataViewAccessSession(mixed $app, string $userId): Closure
{
	mcpAuthSetupOAuthKeys($app);
	mcpAuthSeedUser($userId);

	$token     = mcpAuthIssueToken($app, 'mcp-views-' . uniqid('', true), 'secret', ['cms:read', 'mcp:tools', 'mcp:resources'], $userId);
	$sessionId = mcpAuthInitSession($app, $token);
	expect($token)->not->toBe('')->and($sessionId)->not->toBe('');

	$id = 0;

	return static function (string $method, array $params = []) use ($app, $token, $sessionId, &$id): array {
		$body = ['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method];
		if ($params !== []) {
			$body['params'] = $params;
		}

		return (array)json_decode((string)mcpAuthRequest($app, $token, $body, $sessionId)->getBody(), true);
	};
}

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	mcpAuthSeedAccessGroups();
});

test('an OAuth member whose groups grant data views reads a view open to signed-in callers', function (): void {
	dataViewAccessGrantViews('editor');
	$viewId = dataViewAccessSeedView($this->app, 'members-orders', 'authenticated');
	$mcp = dataViewAccessSession($this->app, 'editor-user-test-com');

	$listed = $mcp('tools/call', ['name' => 'list_views', 'arguments' => []]);
	expect(array_column($listed['result']['structuredContent']['views'] ?? [], 'id'))->toContain($viewId);

	$view = $mcp('tools/call', ['name' => 'get_view', 'arguments' => ['id' => $viewId]]);
	expect($view['result']['isError'] ?? true)->toBeFalse()
		->and($view['result']['structuredContent']['total'] ?? null)->toBe(1);

	$resources = $mcp('resources/list');
	expect(array_column($resources['result']['resources'] ?? [], 'uri'))->toContain('tcms://view/' . $viewId);
});

test('an OAuth member whose groups do not grant data views cannot see or read it', function (): void {
	$viewId = dataViewAccessSeedView($this->app, 'members-orders', 'authenticated');
	$mcp = dataViewAccessSession($this->app, 'viewer-user-test-com');

	$listed = $mcp('tools/call', ['name' => 'list_views', 'arguments' => []]);
	expect(array_column($listed['result']['structuredContent']['views'] ?? [], 'id'))->not->toContain($viewId);

	foreach (['get_view' => ['id' => $viewId], 'query_view' => ['id' => $viewId], 'describe_view' => ['id' => $viewId]] as $tool => $arguments) {
		$refused = $mcp('tools/call', ['name' => $tool, 'arguments' => $arguments]);
		expect(($refused['result']['isError'] ?? false) || isset($refused['error']))->toBeTrue("{$tool} must refuse");
	}

	$resources = $mcp('resources/list');
	expect(array_column($resources['result']['resources'] ?? [], 'uri'))->not->toContain('tcms://view/' . $viewId);
});

test('a public view needs no data views grant', function (): void {
	$viewId = dataViewAccessSeedView($this->app, 'public-totals', 'public');
	$mcp = dataViewAccessSession($this->app, 'viewer-user-test-com');

	$view = $mcp('tools/call', ['name' => 'get_view', 'arguments' => ['id' => $viewId]]);
	expect($view['result']['isError'] ?? true)->toBeFalse();
});

test('without the Data Views edition no view tool or resource is offered, public views included', function (): void {
	dataViewAccessGrantViews('editor');
	$viewId = dataViewAccessSeedView($this->app, 'public-totals', 'public');

	$editions = test()->createMock(EditionFeatureService::class);
	$editions->method('can')->willReturnCallback(static fn (EditionFeature $feature): bool => $feature !== EditionFeature::DATA_VIEWS);
	$this->app->getContainer()->set(EditionFeatureService::class, $editions);

	$mcp = dataViewAccessSession($this->app, 'editor-user-test-com');

	$names = array_column($mcp('tools/list')['result']['tools'] ?? [], 'name');
	expect($names)->not->toContain('list_views')->not->toContain('get_view')
		->not->toContain('query_view')->not->toContain('describe_view')
		->and($names)->toContain('query_collection');

	$refused = $mcp('tools/call', ['name' => 'get_view', 'arguments' => ['id' => $viewId]]);
	expect(($refused['result']['isError'] ?? false) || isset($refused['error']))->toBeTrue();

	expect(array_column($mcp('resources/list')['result']['resources'] ?? [], 'uri'))->not->toContain('tcms://view/' . $viewId);
	expect(array_column($mcp('resources/templates/list')['result']['resourceTemplates'] ?? [], 'uriTemplate'))->not->toContain('tcms://view/{id}');
});
