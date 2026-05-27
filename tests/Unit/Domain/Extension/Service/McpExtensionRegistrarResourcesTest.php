<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Extension\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use TotalCMS\Domain\Extension\Service\McpExtensionRegistrar;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;

/**
 * Covers the resource-side paths added to McpExtensionRegistrar in Phase 2
 * Chunk C: registerResources() and registerResourceTemplates() over the
 * ResourceRegistry, including strict-deny collision policy.
 */
final class McpExtensionRegistrarResourcesTest extends TestCase
{
	private ResourceRegistry $registry;
	private TestLogger $logger;
	private McpExtensionRegistrar $registrar;

	protected function setUp(): void
	{
		$this->registry  = new ResourceRegistry();
		$this->logger    = new TestLogger();
		$this->registrar = new McpExtensionRegistrar($this->logger);
	}

	// ── registerResources ────────────────────────────────────────────────────

	public function testRegisterResourcesMaterializesEntriesIntoRegistry(): void
	{
		$result = $this->registrar->registerResources($this->registry, [
			'acme/widgets' => [
				[
					'uri'         => 'acme://widgets/all',
					'name'        => 'Acme Widgets',
					'description' => 'All widgets',
					'handler'     => fn (): array => [],
					'access'      => 'public',
					'mimeType'    => 'application/json',
				],
			],
		]);

		$this->assertSame(['registered' => 1, 'blocked' => 0], $result);

		$res = $this->registry->get('acme://widgets/all');
		$this->assertNotNull($res);
		$this->assertSame('acme://widgets/all', $res->uri);
		$this->assertSame('Acme Widgets', $res->name);
		$this->assertSame('All widgets', $res->description);
		$this->assertSame('public', $res->access);
		$this->assertSame('application/json', $res->mimeType);
	}

	public function testRegisterResourcesBlocksDuplicateUriAcrossExtensions(): void
	{
		$this->registrar->registerResources($this->registry, [
			'acme/widgets' => [
				['uri' => 'acme://widgets/all', 'name' => 'Acme', 'description' => 'a', 'handler' => fn (): array => [], 'access' => 'public', 'mimeType' => 'application/json'],
			],
			'beta/widgets' => [
				['uri' => 'acme://widgets/all', 'name' => 'Beta', 'description' => 'b', 'handler' => fn (): array => [], 'access' => 'public', 'mimeType' => 'application/json'],
			],
		]);

		$res = $this->registry->get('acme://widgets/all');
		$this->assertNotNull($res);
		$this->assertSame('Acme', $res->name, 'first-registered wins; duplicate blocked');

		$this->assertCount(1, $this->logger->records);
		$this->assertSame('warning', $this->logger->records[0]['level']);
		$this->assertStringContainsString('beta/widgets', $this->logger->records[0]['message']);
		$this->assertStringContainsString('acme://widgets/all', $this->logger->records[0]['message']);
	}

	public function testRegisterResourcesBlocksCollisionWithCoreRegistration(): void
	{
		// Simulate a core registration: a tcms:// resource already in the registry.
		$this->registry->register(new \TotalCMS\Domain\Mcp\Resource\Data\McpResourceDefinition(
			uri: 'tcms://blog/',
			name: 'core-blog',
			description: 'core',
			mimeType: 'application/json',
			access: 'public',
			handler: fn (): array => [],
		));

		$result = $this->registrar->registerResources($this->registry, [
			'rogue/ext' => [
				['uri' => 'tcms://blog/', 'name' => 'rogue', 'description' => 'r', 'handler' => fn (): array => [], 'access' => 'public', 'mimeType' => 'application/json'],
			],
		]);

		$this->assertSame(['registered' => 0, 'blocked' => 1], $result);
		$this->assertSame('core-blog', $this->registry->get('tcms://blog/')?->name, 'core registration preserved');
	}

	public function testRegisterResourcesSkipsMalformedEntriesWithoutCrashing(): void
	{
		$result = $this->registrar->registerResources($this->registry, [
			'broken/ext' => [
				'not-an-array',
				['missing-uri' => true],
				['uri'         => 'acme://ok/', 'name' => 'OK', 'description' => '', 'handler' => fn (): array => [], 'access' => 'public', 'mimeType' => 'application/json'],
			],
		]);

		// Only the well-formed entry is registered; the malformed ones are
		// defensively skipped without throwing.
		$this->assertSame(['registered' => 1, 'blocked' => 0], $result);
		$this->assertNotNull($this->registry->get('acme://ok/'));
	}

	public function testRegisterResourcesAppliesDefaults(): void
	{
		// Extensions might use the ExtensionContext helper that fills defaults,
		// but if they construct the array shape directly with missing keys,
		// the registrar still produces a sensible registration.
		$this->registrar->registerResources($this->registry, [
			'acme' => [
				['uri' => 'acme://thing/', 'handler' => fn (): array => []],
			],
		]);

		$res = $this->registry->get('acme://thing/');
		$this->assertNotNull($res);
		$this->assertSame('acme://thing/', $res->name);
		$this->assertSame('', $res->description);
		$this->assertSame('public', $res->access);
		$this->assertSame('application/json', $res->mimeType);
	}

	// ── registerResourceTemplates ────────────────────────────────────────────

	public function testRegisterResourceTemplatesMaterializesEntriesIntoRegistry(): void
	{
		$result = $this->registrar->registerResourceTemplates($this->registry, [
			'acme/widgets' => [
				[
					'uriTemplate' => 'acme://widgets/{id}',
					'name'        => 'Acme Widget Detail',
					'description' => 'Single widget by id',
					'handler'     => fn (string $id): array => [],
					'access'      => 'public',
					'mimeType'    => 'application/json',
				],
			],
		]);

		$this->assertSame(['registered' => 1, 'blocked' => 0], $result);
		$tmpl = $this->registry->getTemplate('acme://widgets/{id}');
		$this->assertNotNull($tmpl);
		$this->assertSame('Acme Widget Detail', $tmpl->name);
	}

	public function testRegisterResourceTemplatesBlocksDuplicateTemplate(): void
	{
		$this->registrar->registerResourceTemplates($this->registry, [
			'acme/widgets' => [
				['uriTemplate' => 'acme://widgets/{id}', 'name' => 'Acme', 'description' => 'a', 'handler' => fn (string $id): array => [], 'access' => 'public', 'mimeType' => 'application/json'],
			],
			'beta/widgets' => [
				['uriTemplate' => 'acme://widgets/{id}', 'name' => 'Beta', 'description' => 'b', 'handler' => fn (string $id): array => [], 'access' => 'public', 'mimeType' => 'application/json'],
			],
		]);

		$this->assertNotEmpty($this->logger->records);
		$this->assertStringContainsString('beta/widgets', $this->logger->records[0]['message']);
		$this->assertStringContainsString('acme://widgets/{id}', $this->logger->records[0]['message']);
	}

	public function testEmptyExtensionMapReturnsZeroCounts(): void
	{
		$result = $this->registrar->registerResources($this->registry, []);
		$this->assertSame(['registered' => 0, 'blocked' => 0], $result);

		$result = $this->registrar->registerResourceTemplates($this->registry, []);
		$this->assertSame(['registered' => 0, 'blocked' => 0], $result);
	}
}

/**
 * Minimal PSR-3 logger that records every call for assertion.
 */
final class TestLogger extends AbstractLogger
{
	/** @var list<array{level: string, message: string, context: array<string,mixed>}> */
	public array $records = [];

	/** @param array<string,mixed> $context */
	public function log($level, \Stringable|string $message, array $context = []): void
	{
		$this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
	}
}
