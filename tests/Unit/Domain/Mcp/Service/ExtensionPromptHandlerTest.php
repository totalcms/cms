<?php

declare(strict_types=1);

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;

/**
 * Regression cover for extension-registered MCP prompts being argument-blind.
 *
 * ReferenceHandler::handle() has two dispatch paths. A closure whose
 * getClosureScopeClass() is ReferenceHandler itself receives the raw argument
 * bag; everything else goes through prepareArguments(), which fills parameters
 * BY NAME. buildExtensionPromptHandler()'s wrapper has a single parameter named
 * `arguments`, which matches no incoming argument key — so before the scope
 * rebind it silently fell back to its `[]` default and every extension prompt
 * ran with zero arguments, with no error anywhere to notice.
 *
 * These tests pin the mechanism (scope) AND the observable behaviour
 * (arguments actually reach the handler), so a refactor that drops the bind
 * fails here rather than in a customer's prompt.
 */

/** @return array{handler: \Closure, received: object} */
function buildDocsPromptHandler(McpPersona $persona = McpPersona::ADMIN, string $access = 'public'): array
{
	$received = new class {
		/** @var array<string,mixed>|null */
		public ?array $args = null;
	};

	$inner = function (array $arguments = []) use ($received): string {
		$received->args = $arguments;

		return 'rendered';
	};

	$factory = (new ReflectionClass(McpServerFactory::class))->newInstanceWithoutConstructor();
	$method  = new ReflectionMethod(McpServerFactory::class, 'buildExtensionPromptHandler');

	/** @var \Closure $handler */
	$handler = $method->invoke($factory, $inner, $persona, 'tcms_research', $access);

	return ['handler' => $handler, 'received' => $received];
}

it('binds the prompt handler to ReferenceHandler scope so the SDK passes the raw argument bag', function (): void {
	['handler' => $handler] = buildDocsPromptHandler();

	$scope = (new ReflectionFunction($handler))->getClosureScopeClass()?->getName();

	// This exact identity is what ReferenceHandler::handle() tests to decide
	// between the raw-bag path and name-based mapping.
	expect($scope)->toBe(ReferenceHandler::class);
});

it('delivers caller arguments through the real SDK dispatch path', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler();

	// Dispatch through ReferenceHandler rather than calling the closure
	// directly. Calling it directly proves only that the wrapper forwards what
	// it is handed — it passes with OR without the scope bind, so it cannot
	// detect the bug. ReferenceHandler::handle() is where the raw-bag vs
	// name-mapping decision is actually made, so this is the assertion that
	// fails if the bind is ever dropped.
	// No _session key: on the bound path handle() returns before it reads one.
	// Its absence is also what makes the unbound path fail loudly here rather
	// than silently delivering an empty bag.
	(new ReferenceHandler())->handle(
		new ElementReference($handler),
		['question' => 'How do I resize an image?', 'limit' => 3],
	);

	expect($received->args)->toBe(['question' => 'How do I resize an image?', 'limit' => 3]);
});

it('strips the SDK internal injections so extensions see only caller arguments', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler();

	// Called directly on purpose: stripping is the wrapper's own behaviour, not
	// the dispatcher's, so driving it through ReferenceHandler would only add a
	// SessionInterface double without testing anything more.
	$handler(['question' => 'hi', '_session' => 'sess', '_request' => 'req']);

	expect($received->args)->toBe(['question' => 'hi']);
});

it('still refuses a caller whose persona cannot access the prompt', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler(
		persona: McpPersona::PUBLIC_,
		access: 'admin',
	);

	expect(fn () => $handler(['question' => 'hi']))
		->toThrow(\Mcp\Exception\PromptGetException::class);

	// The guard must fire BEFORE the extension handler runs, not after.
	expect($received->args)->toBeNull();
});
