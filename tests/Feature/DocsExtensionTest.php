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

it('ships a bundled manifest with id totalcms/docs, default_enabled, and a settings schema', function (): void {
	$manifestPath = dirname(__DIR__, 2) . '/resources/extensions/totalcms/docs/extension.json';
	$data         = json_decode((string)file_get_contents($manifestPath), true);

	expect($data)->toBeArray();
	expect($data['id'])->toBe('totalcms/docs');
	expect($data['default_enabled'])->toBeTrue();
	expect($data['settings_schema'])->toBe('settings-schema.json');
	expect(is_file(dirname($manifestPath) . '/' . $data['settings_schema']))->toBeTrue();
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
