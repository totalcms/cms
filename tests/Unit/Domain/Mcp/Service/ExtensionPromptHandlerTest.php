<?php

declare(strict_types=1);

use Mcp\Server\ClientGateway;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Prompt\Handler\ExtensionPromptHandler;

/**
 * Extension-registered MCP prompts go through the SDK's explicit-schema entry
 * point (Builder::add) rather than addPrompt().
 *
 * That choice is what preserves argument descriptions and required flags:
 * addPrompt() derives the advertised schema by REFLECTING the handler, and
 * reflection recovers parameter names only. Descriptions have nowhere to
 * travel and every argument comes out optional, so clients render an
 * unlabelled box for a required argument with no hint of what to type — the
 * symptom seen in Claude Desktop, where a shipped prompt showed one anonymous
 * field.
 *
 * Schema fidelity is asserted where the schema is declared
 * (tests/Feature/DocsExtensionTest.php). These cover the handler: arguments
 * arrive intact, transport internals do not, and access is re-checked at call
 * time.
 */

/** @return array{handler: ExtensionPromptHandler, received: object} */
function buildDocsPromptHandler(
	McpPersona $persona = McpPersona::ADMIN,
	string $access = 'public',
): array {
	$received = new class {
		/** @var array<string,mixed>|null */
		public ?array $args = null;
	};

	$inner = function (array $args = []) use ($received): string {
		$received->args = $args;

		return 'rendered';
	};

	return [
		'handler'  => new ExtensionPromptHandler($inner, $persona, 'tcms_twig_recipe', $access),
		'received' => $received,
	];
}

function promptHandlerGateway(): ClientGateway
{
	return (new ReflectionClass(ClientGateway::class))->newInstanceWithoutConstructor();
}

it('passes the caller arguments through to the extension handler', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler();

	$handler->get(['goal' => 'I need to format a date as yyyy/mm/dd'], promptHandlerGateway());

	expect($received->args)->toBe(['goal' => 'I need to format a date as yyyy/mm/dd']);
});

it('passes multiple arguments through intact', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler();

	$handler->get(
		['symptom' => 'connects but no tools', 'site_url' => 'https://example.com/mcp'],
		promptHandlerGateway(),
	);

	expect($received->args)->toBe([
		'symptom'  => 'connects but no tools',
		'site_url' => 'https://example.com/mcp',
	]);
});

it('omits arguments the caller did not supply', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler();

	$handler->get(['symptom' => 'connects but no tools'], promptHandlerGateway());

	// Absent rather than null, so an extension can use a plain isset().
	expect($received->args)->not->toHaveKey('site_url');
});

it('strips transport internals so extensions see only caller arguments', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler();

	$handler->get(
		['goal' => 'hi', '_session' => 'sess', '_request' => 'req'],
		promptHandlerGateway(),
	);

	expect($received->args)->toBe(['goal' => 'hi']);
});

it('refuses a caller whose persona cannot access the prompt', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler(
		persona: McpPersona::PUBLIC_,
		access: 'admin',
	);

	expect(fn () => $handler->get(['goal' => 'anything'], promptHandlerGateway()))
		->toThrow(\Mcp\Exception\PromptGetException::class);

	// The guard must fire BEFORE the extension handler runs, not after.
	expect($received->args)->toBeNull();
});

it('allows an authenticated caller a prompt registered as authenticated', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler(
		persona: McpPersona::AUTHENTICATED,
		access: 'authenticated',
	);

	$handler->get(['goal' => 'ok'], promptHandlerGateway());

	expect($received->args)->toBe(['goal' => 'ok']);
});
