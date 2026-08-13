<?php

declare(strict_types=1);

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\PromptArgument;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;

/**
 * Regression cover for extension-registered MCP prompts.
 *
 * The SDK gives a prompt handler two jobs at once, and both run off the same
 * mechanism: Builder::addPrompt() takes no $arguments parameter, so the SDK
 * REFLECTS the handler to decide what prompts/list advertises, and
 * ReferenceHandler::handle() then fills those same parameters BY NAME from
 * the caller's bag.
 *
 * A handler written as `fn (array $arguments = [])` fails both: prompts/list
 * advertises one optional argument literally named "arguments", and the
 * caller's real arguments match nothing and vanish. In a client that surfaces
 * as a single unlabelled text box whose contents are silently discarded —
 * which is exactly how it was found, with a shipped prompt rendering its
 * "argument not supplied" fallback no matter what the user typed.
 *
 * So these tests assert the ADVERTISED schema as well as delivery. Delivery
 * alone would still pass with the wrong argument names.
 */

/** @return array{handler: \Closure, received: object} */
function buildDocsPromptHandler(
	array $arguments = [],
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

	$factory = (new ReflectionClass(McpServerFactory::class))->newInstanceWithoutConstructor();

	// The handler builder logs when it drops an unsafe argument name, and the
	// factory was built without its constructor, so seed the one dependency it
	// reaches for.
	$loggerProperty = new ReflectionProperty(McpServerFactory::class, 'logger');
	$loggerProperty->setValue($factory, new NullLogger());

	$method = new ReflectionMethod(McpServerFactory::class, 'buildExtensionPromptHandler');

	/** @var \Closure $handler */
	$handler = $method->invoke($factory, $inner, $persona, 'tcms_twig_recipe', $access, $arguments);

	return ['handler' => $handler, 'received' => $received];
}

/** @return list<string> */
function promptHandlerParameterNames(\Closure $handler): array
{
	return array_map(
		static fn (ReflectionParameter $p): string => $p->getName(),
		(new ReflectionFunction($handler))->getParameters(),
	);
}

/**
 * ReferenceHandler::handle() reads `_session` out of the argument bag before
 * it dispatches, so a stub is required to exercise the real path at all.
 * Nothing under test touches its state.
 */
function promptHandlerSession(): SessionInterface
{
	return new class implements SessionInterface {
		public function getId(): Uuid { return Uuid::v4(); }
		public function save(): bool { return true; }
		public function get(string $key, mixed $default = null): mixed { return $default; }
		public function set(string $key, mixed $value, bool $overwrite = true): void {}
		public function has(string $key): bool { return false; }
		public function forget(string $key): void {}
		public function clear(): void {}
		public function pull(string $key, mixed $default = null): mixed { return $default; }
		public function all(): array { return []; }
		public function hydrate(array $attributes): void {}
		public function jsonSerialize(): mixed { return []; }
	};
}

it('advertises the declared argument names, not the handler bag', function (): void {
	['handler' => $handler] = buildDocsPromptHandler([
		new PromptArgument(name: 'goal', description: 'What the template must do.', required: true),
	]);

	// This is the assertion that would have caught the shipped bug: the SDK
	// reflects these names into prompts/list, so a parameter called
	// "arguments" means every client renders the wrong form.
	expect(promptHandlerParameterNames($handler))->toBe(['goal']);
	expect(promptHandlerParameterNames($handler))->not->toContain('arguments');
});

it('advertises every declared argument in order', function (): void {
	['handler' => $handler] = buildDocsPromptHandler([
		new PromptArgument(name: 'symptom', description: 'What the client reports.', required: true),
		new PromptArgument(name: 'site_url', description: 'Endpoint, if known.', required: false),
	]);

	expect(promptHandlerParameterNames($handler))->toBe(['symptom', 'site_url']);
});

it('delivers caller arguments through the real SDK dispatch path', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler([
		new PromptArgument(name: 'goal', description: 'What the template must do.', required: true),
	]);

	// Dispatched through ReferenceHandler rather than called directly, because
	// name-based mapping is the step under test — a direct call would pass
	// even with the wrong parameter names.
	(new ReferenceHandler())->handle(
		new ElementReference($handler),
		['goal' => 'I need to format a date as yyyy/mm/dd', '_session' => promptHandlerSession()],
	);

	expect($received->args)->toBe(['goal' => 'I need to format a date as yyyy/mm/dd']);
});

it('omits arguments the caller did not supply rather than passing null', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler([
		new PromptArgument(name: 'symptom', description: 'What the client reports.', required: true),
		new PromptArgument(name: 'site_url', description: 'Endpoint, if known.', required: false),
	]);

	(new ReferenceHandler())->handle(
		new ElementReference($handler),
		['symptom' => 'connects but no tools', '_session' => promptHandlerSession()],
	);

	// Absent rather than null, so an extension can use a plain isset().
	expect($received->args)->toBe(['symptom' => 'connects but no tools']);
	expect($received->args)->not->toHaveKey('site_url');
});

it('drops argument names that are not safe PHP identifiers', function (): void {
	// Argument names come from third-party extension code and reach eval(),
	// unlike the collection path where the schema validates them first.
	['handler' => $handler] = buildDocsPromptHandler([
		new PromptArgument(name: 'goal', description: 'Fine.', required: true),
		new PromptArgument(name: '$x; echo "pwned"', description: 'Not fine.', required: false),
		new PromptArgument(name: 'Goal-2', description: 'Also not fine.', required: false),
	]);

	expect(promptHandlerParameterNames($handler))->toBe(['goal']);
});

it('handles a prompt that declares no arguments at all', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler([]);

	expect(promptHandlerParameterNames($handler))->toBe([]);

	(new ReferenceHandler())->handle(new ElementReference($handler), ['_session' => promptHandlerSession()]);

	expect($received->args)->toBe([]);
});

it('still refuses a caller whose persona cannot access the prompt', function (): void {
	['handler' => $handler, 'received' => $received] = buildDocsPromptHandler(
		arguments: [new PromptArgument(name: 'goal', description: 'What to do.', required: true)],
		persona: McpPersona::PUBLIC_,
		access: 'admin',
	);

	expect(fn () => $handler(goal: 'anything'))
		->toThrow(\Mcp\Exception\PromptGetException::class);

	// The guard must fire BEFORE the extension handler runs, not after.
	expect($received->args)->toBeNull();
});
