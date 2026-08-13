<?php

declare(strict_types=1);

// Bundled extensions are not in the Composer autoloader — load the entry
// point the same way ExtensionManager::registerExtension() does (require_once
// the file at its on-disk path), mirroring the pattern used by
// tests/Unit/Bundled/AlgoliaSearch/AlgoliaSearchLifecycleTest.php.
require_once dirname(__DIR__, 2) . '/resources/extensions/totalcms/docs/Extension.php';

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Bundled\Docs\Extension;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\McpExtensionRegistrar;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Task 3 (docs-extension-promotion): the totalcms/docs extension moved from
 * a private, out-of-package extension (totalcms.co only) to a bundled
 * extension shipped in resources/extensions/. Two things had to survive the
 * move unchanged: the tool CONTRACT (names/descriptions/schemas — a
 * submitted ChatGPT plugin was scanned against them) and the traversal
 * guard on docs_get. One thing had to change: default access flips from
 * 'public' to 'authenticated' so anonymous callers on a customer's site
 * don't see documentation tools unless the operator opts in.
 *
 * These tests exercise the real, on-disk Extension.php directly (not a
 * synthetic stand-in) — the same construction pattern used by
 * McpExtensionRegistrarTest for extension-registered tools — so a change to
 * the shipped file is what's actually under test.
 */

/**
 * Real ExtensionSettingsManager backed by a throwaway on-disk store (same
 * shape as production: `.system/extension-settings/totalcms/docs.json`),
 * matching the construction pattern AlgoliaSearchLifecycleTest uses for
 * bundled-extension tests. Pre-seeds the file when $publicTools is true;
 * otherwise leaves it absent so ExtensionSettingsManager::getSetting()
 * falls through to its declared default (false) — proving the DEFAULT,
 * not a mocked stand-in for it.
 *
 * The tmp dir is only needed for the single settings read Extension::register()
 * makes at the top of the method, so it's removed again immediately after —
 * nothing later in a test touches it.
 *
 * @return array{extension: Extension, context: ExtensionContext}
 */
function docsExtensionRegister(bool $publicTools): array
{
	$manifestPath = dirname(__DIR__, 2) . '/resources/extensions/totalcms/docs/extension.json';
	$manifestData = json_decode((string)file_get_contents($manifestPath), true);
	$manifest     = ExtensionManifest::fromArray(is_array($manifestData) ? $manifestData : []);

	$tmpDir = sys_get_temp_dir() . '/tcms-docs-ext-test-' . uniqid('', true);
	mkdir($tmpDir . '/.system/extension-settings/totalcms', 0755, true);
	if ($publicTools) {
		file_put_contents(
			$tmpDir . '/.system/extension-settings/totalcms/docs.json',
			(string)json_encode(['publicTools' => true]),
		);
	}

	$flysystem       = new Filesystem(new LocalFilesystemAdapter($tmpDir));
	$storage         = new StorageFilesystemAdapter($flysystem);
	$settingsManager = new ExtensionSettingsManager($storage);

	$container = new class implements ContainerInterface {
		public function get(string $id): never
		{
			throw new \RuntimeException("Unexpected container access for '{$id}' — docs extension should not need services from the DI container.");
		}

		public function has(string $id): bool
		{
			return false;
		}
	};

	$extensionPath = dirname(__DIR__, 2) . '/resources/extensions/totalcms/docs';
	$context       = new ExtensionContext($manifest, $extensionPath, $container, $settingsManager, new NullLogger());

	$extension = new Extension();
	$extension->register($context);

	docsExtensionRrmdir($tmpDir);

	return ['extension' => $extension, 'context' => $context];
}

function docsExtensionRrmdir(string $dir): void
{
	if (!is_dir($dir)) {
		return;
	}
	foreach (scandir($dir) ?: [] as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir($path) ? docsExtensionRrmdir($path) : unlink($path);
	}
	rmdir($dir);
}

/** @return list<McpToolDefinition> */
function docsExtensionToolNames(array $tools): array
{
	return array_map(static fn (McpToolDefinition $t): string => $t->name, $tools);
}

/** @return McpToolDefinition */
function docsExtensionFindTool(array $tools, string $name): McpToolDefinition
{
	foreach ($tools as $tool) {
		if ($tool->name === $name) {
			return $tool;
		}
	}

	throw new \RuntimeException("Tool '{$name}' not registered.");
}

// ──────────────────────────────────────────────────────────────────────────────
// Manifest sanity
// ──────────────────────────────────────────────────────────────────────────────

it('ships a bundled manifest with id totalcms/docs, default_enabled, an icon and a settings schema', function (): void {
	$manifestPath = dirname(__DIR__, 2) . '/resources/extensions/totalcms/docs/extension.json';
	$data         = json_decode((string)file_get_contents($manifestPath), true);

	expect($data)->toBeArray();
	expect($data['id'])->toBe('totalcms/docs');
	expect($data['default_enabled'])->toBeTrue();

	// Filenames match the house convention used by the other bundled
	// extensions (settings.json, icon.png) so the admin renders this one the
	// same way — and both files must actually exist, not just be declared.
	expect($data['settings_schema'])->toBe('settings.json');
	expect(is_file(dirname($manifestPath) . '/' . $data['settings_schema']))->toBeTrue();
	expect($data['icon'])->toBe('icon.png');
	expect(is_file(dirname($manifestPath) . '/' . $data['icon']))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// Access: default settings (authenticated, not public)
// ──────────────────────────────────────────────────────────────────────────────

it('registers all three tools as authenticated-only by default, visible to AUTHENTICATED but not PUBLIC_', function (): void {
	['context' => $context] = docsExtensionRegister(publicTools: false);

	$tools = $context->getRegisteredMcpTools();
	expect(docsExtensionToolNames($tools))->toEqualCanonicalizing(['docs_search', 'docs_get', 'docs_lookup']);

	foreach ($tools as $tool) {
		expect($tool->access)->toBe('authenticated');
		expect($tool->isVisibleTo(McpPersona::ADMIN))->toBeTrue();
		expect($tool->isVisibleTo(McpPersona::AUTHENTICATED))->toBeTrue();
		expect($tool->isVisibleTo(McpPersona::PUBLIC_))->toBeFalse();
	}

	// No MCP resource template ships with this extension (see Extension.php's
	// class docblock) — only the three tools above.
	expect($context->getRegisteredMcpResourceTemplates())->toBe([]);

	// Also drive the real production wiring — ExtensionContext::registerMcpTool()
	// -> McpExtensionRegistrar::register() -> ToolRegistry::forPersona() — to
	// prove the tools are actually REGISTERED (not just individually visible)
	// and stay hidden from an anonymous tools/list under this registry too.
	$registry  = new ToolRegistry();
	$registrar = new McpExtensionRegistrar(new NullLogger());
	$result    = $registrar->register($registry, ['totalcms/docs' => $tools]);

	expect($result['registered'])->toBe(3);
	expect($result['blocked'])->toBe(0);
	expect(docsExtensionToolNames($registry->forPersona(McpPersona::AUTHENTICATED)))->toEqualCanonicalizing(['docs_search', 'docs_get', 'docs_lookup']);
	expect(docsExtensionToolNames($registry->forPersona(McpPersona::PUBLIC_)))->toBe([]);
});

// ──────────────────────────────────────────────────────────────────────────────
// Access: publicTools setting enabled
// ──────────────────────────────────────────────────────────────────────────────

it('registers all three tools as public when the publicTools setting is enabled', function (): void {
	['context' => $context] = docsExtensionRegister(publicTools: true);

	$tools = $context->getRegisteredMcpTools();
	expect(docsExtensionToolNames($tools))->toEqualCanonicalizing(['docs_search', 'docs_get', 'docs_lookup']);

	foreach ($tools as $tool) {
		expect($tool->access)->toBe('public');
		expect($tool->isVisibleTo(McpPersona::PUBLIC_))->toBeTrue();
	}

	$registry  = new ToolRegistry();
	$registrar = new McpExtensionRegistrar(new NullLogger());
	$registrar->register($registry, ['totalcms/docs' => $tools]);

	expect(docsExtensionToolNames($registry->forPersona(McpPersona::PUBLIC_)))->toEqualCanonicalizing(['docs_search', 'docs_get', 'docs_lookup']);
});

// ──────────────────────────────────────────────────────────────────────────────
// Behavioural: path resolution + traversal guard survived the move
// ──────────────────────────────────────────────────────────────────────────────

it('docs_search returns real results for "twig", proving path resolution works from inside the package', function (): void {
	['context' => $context] = docsExtensionRegister(publicTools: false);
	$tool                   = docsExtensionFindTool($context->getRegisteredMcpTools(), 'docs_search');

	/** @var array<string,mixed> $result */
	$result = ($tool->handler)('twig', 8);

	expect($result)->toHaveKey('results');
	expect($result['results'])->not->toBe([]);
	expect($result['total'])->toBeGreaterThan(0);

	$paths = array_column($result['results'], 'path');
	expect($paths)->toContain('twig/filters');
});

it('docs_get refuses a path that is not in the search index (traversal guard)', function (): void {
	['context' => $context] = docsExtensionRegister(publicTools: false);
	$tool                   = docsExtensionFindTool($context->getRegisteredMcpTools(), 'docs_get');

	/** @var array<string,mixed> $result */
	$result = ($tool->handler)('../../../../etc/passwd');

	expect($result)->toHaveKey('error');
	expect($result['error'])->toContain('Unknown documentation path');

	// A plausible-looking but non-indexed path is refused the same way — the
	// index (not the filesystem) is the allowlist.
	/** @var array<string,mixed> $result2 */
	$result2 = ($tool->handler)('twig/does-not-exist');
	expect($result2)->toHaveKey('error');
	expect($result2['error'])->toContain('Unknown documentation path');
});

it('docs_get returns real markdown for a known path, confirming resolution against resources/docs', function (): void {
	['context' => $context] = docsExtensionRegister(publicTools: false);
	$tool                   = docsExtensionFindTool($context->getRegisteredMcpTools(), 'docs_get');

	/** @var array<string,mixed> $result */
	$result = ($tool->handler)('twig/filters');

	expect($result)->toHaveKey('markdown');
	expect($result['markdown'])->not->toBe('');
	expect($result['path'])->toBe('twig/filters');
});

// ──────────────────────────────────────────────────────────────────────────────
// MCP prompts (tcms_*) — content lives in prompts.json
// ──────────────────────────────────────────────────────────────────────────────

it('registers the five tcms_ workflow prompts, following the tools access level', function (): void {
	['context' => $context] = docsExtensionRegister(publicTools: false);

	$prompts = $context->getRegisteredMcpPrompts();
	$names   = array_map(static fn (array $p): string => $p['prompt']->name, $prompts);

	expect($names)->toEqualCanonicalizing([
		'tcms_research',
		'tcms_build_page',
		'tcms_explain_field',
		'tcms_twig_recipe',
		'tcms_troubleshoot_mcp',
	]);

	// A prompt that tells an agent to call docs_lookup is useless to a caller
	// who cannot see docs_lookup, so access must track the tools'.
	foreach ($prompts as $registration) {
		expect($registration['access'])->toBe('authenticated');
	}

	// Every prompt declares its arguments — without them the SDK advertises no
	// argument schema and clients cannot prompt the user for input.
	foreach ($prompts as $registration) {
		expect($registration['prompt']->arguments)->not->toBeEmpty();
	}
});

it('exposes the prompts publicly when publicTools is enabled', function (): void {
	['context' => $context] = docsExtensionRegister(publicTools: true);

	foreach ($context->getRegisteredMcpPrompts() as $registration) {
		expect($registration['access'])->toBe('public');
	}
});

it('substitutes declared arguments into the prompt body', function (): void {
	['context' => $context] = docsExtensionRegister(publicTools: false);

	$prompt = null;
	foreach ($context->getRegisteredMcpPrompts() as $registration) {
		if ($registration['prompt']->name === 'tcms_research') {
			$prompt = $registration;
			break;
		}
	}

	expect($prompt)->not->toBeNull();

	$messages = ($prompt['handler'])(['question' => 'How do I resize an image?']);
	$text     = $messages[0]->content->text;

	expect($text)->toContain('How do I resize an image?');
	expect($text)->not->toContain('{question}');
});

it('leaves literal braces that are not declared arguments untouched', function (): void {
	['context' => $context] = docsExtensionRegister(publicTools: false);

	$prompt = null;
	foreach ($context->getRegisteredMcpPrompts() as $registration) {
		if ($registration['prompt']->name === 'tcms_build_page') {
			$prompt = $registration;
			break;
		}
	}

	$text = (($prompt['handler'])(['purpose' => 'a pricing page']))[0]->content->text;

	// `/case-studies/{id}` is guidance about route templates, not a placeholder.
	// A naive regex substitution would eat it.
	expect($text)->toContain('a pricing page');
	expect($text)->toContain('/case-studies/{id}');
});

it('tells the agent to ask for a missing required argument instead of rendering a blank', function (): void {
	['context' => $context] = docsExtensionRegister(publicTools: false);

	$prompt = null;
	foreach ($context->getRegisteredMcpPrompts() as $registration) {
		if ($registration['prompt']->name === 'tcms_explain_field') {
			$prompt = $registration;
			break;
		}
	}

	$text = (($prompt['handler'])([]))[0]->content->text;

	expect($text)->toContain('not supplied');
});

// ──────────────────────────────────────────────────────────────────────────────
// prompts.json integrity
//
// The loader is deliberately forgiving — a malformed entry is skipped so one
// bad prompt cannot take the extension down. That forgiveness is why the file
// itself needs asserting: a typo'd argument name doesn't throw, it renders the
// literal placeholder into the instructions we hand an agent, and every other
// test still passes. These checks exist so ADDING a prompt is safe.
// ──────────────────────────────────────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function docsExtensionPromptsJson(): array
{
	$file = dirname(__DIR__, 2) . '/resources/extensions/totalcms/docs/prompts.json';
	expect(is_file($file))->toBeTrue();

	$decoded = json_decode((string)file_get_contents($file), true);
	expect($decoded)->toBeArray();

	/** @var list<array<string,mixed>> $decoded */
	return $decoded;
}

it('ships prompts.json with unique, well-formed, tcms_-prefixed names', function (): void {
	$prompts = docsExtensionPromptsJson();
	expect($prompts)->not->toBeEmpty();

	$names = array_map(static fn (array $p): string => (string)($p['name'] ?? ''), $prompts);

	expect($names)->toBe(array_values(array_unique($names)));

	foreach ($names as $name) {
		expect($name)->toMatch('/^[a-z][a-z0-9_]*$/');
		expect(mb_strlen($name))->toBeLessThanOrEqual(64);

		// The prefix is load-bearing, not cosmetic: a collection-stored prompt of
		// the same name wins outright (McpServerFactory soft-deny), so a generic
		// name lets an operator's own prompt silently replace a shipped one.
		expect($name)->toStartWith('tcms_');
	}
});

it('declares every prompt argument that its body actually uses', function (): void {
	foreach (docsExtensionPromptsJson() as $prompt) {
		$name = (string)$prompt['name'];
		$body = (string)$prompt['body'];

		expect($body)->not->toBe('');
		expect((string)($prompt['description'] ?? ''))->not->toBe('');

		foreach ($prompt['args'] ?? [] as $arg) {
			$argName = (string)($arg['name'] ?? '');
			expect($argName)->toMatch('/^[a-z][a-z0-9_]*$/');

			// Clients show this when prompting the user for input.
			expect((string)($arg['description'] ?? ''))->not->toBe('');

			// The check that earns its keep. Substitution only replaces DECLARED
			// names, so a body saying {fieldtype} while the arg is declared as
			// field_type renders the raw placeholder into the agent's
			// instructions and silently drops the caller's value. Nothing else
			// in the suite would notice.
			expect($body)->toContain('{' . $argName . '}');
		}
	}
});

it('skips malformed prompt entries without discarding the whole file', function (): void {
	['extension' => $extension] = docsExtensionRegister(publicTools: false);

	$method = new ReflectionMethod($extension, 'promptDefinitions');
	/** @var array<string,mixed> $definitions */
	$definitions = $method->invoke($extension);

	// Every shipped entry survives the loader — proof the file and the loader's
	// validation agree, not just that the loader returns something.
	expect(array_keys($definitions))->toEqualCanonicalizing(
		array_map(static fn (array $p): string => (string)$p['name'], docsExtensionPromptsJson()),
	);
});

it('declares a description and required flag on every prompt argument', function (): void {
	// These now reach the wire. Registration goes through Builder::add(), which
	// publishes the declared Prompt object verbatim, so a missing description is
	// an unlabelled field in every client rather than a cosmetic omission.
	['context' => $context] = docsExtensionRegister(publicTools: false);

	foreach ($context->getRegisteredMcpPrompts() as $registration) {
		$arguments = $registration['prompt']->arguments ?? [];
		expect($arguments)->not->toBeEmpty();

		foreach ($arguments as $argument) {
			expect($argument->description)->not->toBeNull();
			expect(trim((string)$argument->description))->not->toBe('');
			expect($argument->required)->toBeBool();
		}
	}
});
