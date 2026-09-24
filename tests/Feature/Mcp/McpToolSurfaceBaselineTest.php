<?php

declare(strict_types=1);
use Slim\App;

/**
 * Regression pin for the spec's compatibility guarantee: an API-key caller and
 * an anonymous caller get exactly the tools/list they got before the session
 * caller existed. The fixture was captured on the parent commit with
 * MCP_BASELINE_WRITE=1; a drift fails here, loudly. Bearer callers are covered
 * by the McpAuthPersona* suites, which did not change.
 */
const BASELINE_API_KEY  = 'tcms_mcp_baseline_test_key_0000000000000';
const BASELINE_FIXTURE  = __DIR__ . '/../../fixtures/mcp/tool-surface-baseline.json';

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	file_put_contents(cmsDataDir() . '.system/apikeys.json', (string)json_encode([
		'apikeys' => [[
			'id'       => 'baseline-key-id',
			'name'     => 'Baseline Key',
			'key'      => BASELINE_API_KEY,
			'created'  => '2026-01-01T00:00:00Z',
			'lastUsed' => null,
			'scopes'   => ['methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'paths' => ['*']],
		]],
	], JSON_PRETTY_PRINT));
});

function baselineToolNames(App $app, array $headers, string $ip): array
{
	$response = mcpStatelessCall($app, 'tools/list', [], $headers, $ip);
	expect($response->getStatusCode())->toBe(200);
	$body  = json_decode((string)$response->getBody(), true);
	$names = array_column($body['result']['tools'] ?? [], 'name');
	sort($names);

	return $names;
}

test('the API-key and anonymous tool surfaces match the pre-session baseline', function (): void {
	$surface = [
		'api-key'   => baselineToolNames($this->app, ['X-API-Key' => BASELINE_API_KEY], '203.0.113.90'),
		'anonymous' => baselineToolNames($this->app, [], '203.0.113.91'),
	];

	if (getenv('MCP_BASELINE_WRITE') === '1') {
		file_put_contents(BASELINE_FIXTURE, json_encode($surface, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}

	$expected = json_decode((string)file_get_contents(BASELINE_FIXTURE), true);

	expect($surface['api-key'])->toBe($expected['api-key'])
		->and($surface['anonymous'])->toBe($expected['anonymous'])
		->and($surface['api-key'])->toContain('create_object')
		->and($surface['anonymous'])->not->toContain('create_object');
});
