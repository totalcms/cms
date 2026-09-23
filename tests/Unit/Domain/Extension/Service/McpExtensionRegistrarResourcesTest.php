<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Extension\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use TotalCMS\Domain\Extension\Service\McpExtensionRegistrar;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceDefinition;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceTemplateDefinition;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;

/**
 * registerResources() and registerResourceTemplates() over the
 * ResourceRegistry with the strict-deny collision policy. Extensions hand
 * over finished definitions (ExtensionContext builds them), so the registrar
 * only arbitrates collisions.
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

	private function resource(string $uri, string $name): McpResourceDefinition
	{
		return new McpResourceDefinition($uri, $name, 'desc', 'application/json', 'public', fn (): array => []);
	}

	private function template(string $uriTemplate, string $name): McpResourceTemplateDefinition
	{
		return new McpResourceTemplateDefinition($uriTemplate, $name, 'desc', 'application/json', 'public', fn (): array => []);
	}

	public function testRegisterResourcesPutsEntriesIntoTheRegistry(): void
	{
		$result = $this->registrar->registerResources($this->registry, [
			'acme/widgets' => [$this->resource('acme://widgets/all', 'Acme Widgets')],
		]);

		$this->assertSame(['registered' => 1, 'blocked' => 0], $result);
		$this->assertSame('Acme Widgets', $this->registry->get('acme://widgets/all')?->name);
	}

	public function testRegisterResourcesBlocksDuplicateUriAcrossExtensions(): void
	{
		$this->registrar->registerResources($this->registry, [
			'acme/widgets' => [$this->resource('acme://widgets/all', 'Acme')],
			'beta/widgets' => [$this->resource('acme://widgets/all', 'Beta')],
		]);

		$this->assertSame('Acme', $this->registry->get('acme://widgets/all')?->name, 'first-registered wins; duplicate blocked');
		$this->assertCount(1, $this->logger->records);
		$this->assertSame('warning', $this->logger->records[0]['level']);
		$this->assertStringContainsString('beta/widgets', $this->logger->records[0]['message']);
		$this->assertStringContainsString('acme://widgets/all', $this->logger->records[0]['message']);
	}

	public function testRegisterResourcesBlocksCollisionWithCoreRegistration(): void
	{
		$this->registry->register($this->resource('tcms://blog/', 'core-blog'));

		$result = $this->registrar->registerResources($this->registry, [
			'rogue/ext' => [$this->resource('tcms://blog/', 'rogue')],
		]);

		$this->assertSame(['registered' => 0, 'blocked' => 1], $result);
		$this->assertSame('core-blog', $this->registry->get('tcms://blog/')?->name, 'core registration preserved');
	}

	public function testRegisterResourceTemplatesPutsEntriesIntoTheRegistry(): void
	{
		$result = $this->registrar->registerResourceTemplates($this->registry, [
			'acme/widgets' => [$this->template('acme://widgets/{id}', 'Acme Widget')],
		]);

		$this->assertSame(['registered' => 1, 'blocked' => 0], $result);
		$this->assertSame('Acme Widget', $this->registry->getTemplate('acme://widgets/{id}')?->name);
	}

	public function testRegisterResourceTemplatesBlocksDuplicates(): void
	{
		$this->registry->registerTemplate($this->template('tcms://blog/{id}', 'core'));

		$result = $this->registrar->registerResourceTemplates($this->registry, [
			'rogue/ext' => [$this->template('tcms://blog/{id}', 'rogue')],
		]);

		$this->assertSame(['registered' => 0, 'blocked' => 1], $result);
		$this->assertSame('core', $this->registry->getTemplate('tcms://blog/{id}')?->name);
		$this->assertStringContainsString('rogue/ext', $this->logger->records[0]['message']);
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
