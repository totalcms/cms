<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Service;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\FilterValueResolver;
use TotalCMS\Domain\Mcp\Tool\Service\SavedQueryToolFactory;
use TotalCMS\Domain\Mcp\Tool\Service\SchemaToolRegistrar;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/** @internal */
final class RecordingLogger extends AbstractLogger
{
	/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
	public array $records = [];

	/**
	 * @param array<string,mixed> $context
	 */
	public function log(mixed $level, string|\Stringable $message, array $context = []): void
	{
		$this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
	}

	public function hasWarning(string $fragment): bool
	{
		foreach ($this->records as $r) {
			if ($r['level'] === 'warning' && str_contains($r['message'], $fragment)) {
				return true;
			}
		}

		return false;
	}
}

final class SchemaToolRegistrarTest extends TestCase
{
	private function makeCollection(string $id, array $mcpConfig): CollectionData
	{
		$c         = new CollectionData();
		$c->id     = $id;
		$c->name   = ucfirst($id);
		$c->schema = $id;
		$c->mcp    = $mcpConfig;

		return $c;
	}

	private function makeFactory(): SavedQueryToolFactory
	{
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id): mixed {
			return match ($id) {
				IndexQueryService::class    => $this->createMock(IndexQueryService::class),
				FilterValueResolver::class  => new FilterValueResolver(),
				ContentRenderer::class      => $this->createMock(ContentRenderer::class),
				PersonaContext::class       => new PersonaContext(),
				ObjectUrlBuilder::class     => $this->createMock(ObjectUrlBuilder::class),
				McpSchemaResolver::class    => $this->createMock(McpSchemaResolver::class),
				CollectionRepository::class => $this->createMock(CollectionRepository::class),
				default                     => null,
			};
		});

		return new SavedQueryToolFactory($container);
	}

	private function makeRepo(array $collections): CollectionRepository
	{
		$repo = $this->createMock(CollectionRepository::class);
		$repo->method('listAllCollections')->willReturn($collections);

		return $repo;
	}

	private function coreTool(string $name): McpToolDefinition
	{
		return new McpToolDefinition($name, "core {$name}", 'public', static fn (): array => []);
	}

	public function testRegistersASingleToolFromASingleCollection(): void
	{
		$collection = $this->makeCollection('blog', [
			'access' => 'public',
			'tools'  => [
				[
					'name'        => 'find_featured',
					'description' => 'Featured posts.',
					'filters'     => ['featured' => ['value' => true]],
				],
			],
		]);

		$repo      = $this->makeRepo([$collection]);
		$factory   = $this->makeFactory();
		$registry  = new ToolRegistry();
		$registrar = new SchemaToolRegistrar($repo, $factory, new RecordingLogger());

		$registrar->register($registry);

		$tools = $registry->forPersona(McpPersona::PUBLIC_);
		$this->assertCount(1, $tools);
		$this->assertSame('find_featured', $tools[0]->name);
	}

	public function testSkipsCollisionWithCoreToolNameAndLogs(): void
	{
		$collection = $this->makeCollection('blog', [
			'access' => 'public',
			'tools'  => [
				[
					'name'        => 'query_collection',
					'description' => 'Bad colliding tool.',
				],
			],
		]);

		$repo     = $this->makeRepo([$collection]);
		$factory  = $this->makeFactory();
		$registry = new ToolRegistry();
		$registry->register($this->coreTool('query_collection')); // pre-populate core tool
		$logger   = new RecordingLogger();

		$registrar = new SchemaToolRegistrar($repo, $factory, $logger);
		$registrar->register($registry);

		// The schema tool should not be registered (core wins).
		$tools     = $registry->forPersona(McpPersona::PUBLIC_);
		$toolNames = array_map(static fn (McpToolDefinition $t): string => $t->name, $tools);
		$this->assertContains('query_collection', $toolNames);
		$this->assertCount(1, $tools); // only the core tool

		// A warning must have been logged.
		$this->assertTrue($logger->hasWarning('collides with core/extension tool'));
	}

	public function testSkipsCollisionAcrossCollections(): void
	{
		$blog = $this->makeCollection('blog', [
			'access' => 'public',
			'tools'  => [['name' => 'latest', 'description' => 'Blog latest.']],
		]);
		$news = $this->makeCollection('news', [
			'access' => 'public',
			'tools'  => [['name' => 'latest', 'description' => 'News latest.']],
		]);

		$repo     = $this->makeRepo([$blog, $news]);
		$factory  = $this->makeFactory();
		$registry = new ToolRegistry();
		$logger   = new RecordingLogger();

		$registrar = new SchemaToolRegistrar($repo, $factory, $logger);
		$registrar->register($registry);

		// Strict deny: both are rejected.
		$tools     = $registry->forPersona(McpPersona::ADMIN);
		$toolNames = array_map(static fn (McpToolDefinition $t): string => $t->name, $tools);
		$this->assertNotContains('latest', $toolNames);

		// At least one cross-collection collision warning logged.
		$this->assertGreaterThanOrEqual(1, count($logger->records));
	}

	public function testSkipsBadSiblingButContinuesWithGoodSibling(): void
	{
		$collection = $this->makeCollection('blog', [
			'access' => 'public',
			'tools'  => [
				['name' => 'BadName', 'description' => '...'],  // invalid name → skip
				['name' => 'find_good', 'description' => 'Good one.', 'filters' => ['featured' => ['value' => true]]],
			],
		]);

		$repo      = $this->makeRepo([$collection]);
		$factory   = $this->makeFactory();
		$registry  = new ToolRegistry();
		$logger    = new RecordingLogger();
		$registrar = new SchemaToolRegistrar($repo, $factory, $logger);

		$registrar->register($registry);

		$tools     = $registry->forPersona(McpPersona::PUBLIC_);
		$toolNames = array_map(static fn (McpToolDefinition $t): string => $t->name, $tools);
		$this->assertSame(['find_good'], $toolNames);
	}
}
