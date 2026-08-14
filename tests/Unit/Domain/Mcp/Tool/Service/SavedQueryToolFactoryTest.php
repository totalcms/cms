<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Service;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\CollectionQueryResultFormatter;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Data\SavedQueryToolDefinition;
use TotalCMS\Domain\Mcp\Tool\SavedQuery\SavedQueryTool;
use TotalCMS\Domain\Mcp\Tool\Service\FilterValueResolver;
use TotalCMS\Domain\Mcp\Tool\Service\SavedQueryToolFactory;

final class SavedQueryToolFactoryTest extends TestCase
{
	private SavedQueryToolFactory $factory;

	protected function setUp(): void
	{
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): mixed => match ($id) {
			IndexQueryService::class        => $this->createMock(IndexQueryService::class),
			FilterValueResolver::class      => new FilterValueResolver(),
			ContentRenderer::class          => $this->createMock(ContentRenderer::class),
			// This file never invokes a built tool's ->handle() — only tests
			// SavedQueryToolFactory's shaping — so PersonaContext's Task 10b
			// constructor deps never matter; plain stubs satisfy the type.
			PersonaContext::class                 => new PersonaContext($this->createStub(CollectionFetcher::class), $this->createStub(McpSchemaResolver::class)),
			ObjectUrlBuilder::class               => $this->createMock(ObjectUrlBuilder::class),
			McpSchemaResolver::class              => $this->createMock(McpSchemaResolver::class),
			CollectionRepository::class           => $this->createMock(CollectionRepository::class),
			CollectionQueryResultFormatter::class => new CollectionQueryResultFormatter(),
			default                               => null,
		});

		$this->factory = new SavedQueryToolFactory($container);
	}

	public function testBuildsASavedQueryToolFromADefinition(): void
	{
		$def = SavedQueryToolDefinition::fromArray('listings', 'public', [
			'id'          => 'find_listings',
			'description' => 'Find listings.',
			'params'      => [
				'city' => ['type' => 'string', 'required' => true],
			],
		]);

		$tool = $this->factory->build($def);

		$this->assertInstanceOf(SavedQueryTool::class, $tool);
	}

	public function testGeneratesAnInputSchemaFromTheParamsBlock(): void
	{
		$def = SavedQueryToolDefinition::fromArray('listings', 'public', [
			'id'          => 'find_listings',
			'description' => 'Find listings.',
			'params'      => [
				'city'      => ['type' => 'string', 'description' => 'City name.', 'required' => true],
				'max_price' => ['type' => 'number', 'minimum' => 0],
			],
		]);

		$schema = $this->factory->inputSchemaFor($def);

		$this->assertSame('object', $schema['type']);
		$this->assertSame('string', $schema['properties']['city']['type']);
		$this->assertSame('City name.', $schema['properties']['city']['description']);
		$this->assertSame('number', $schema['properties']['max_price']['type']);
		$this->assertSame(0, $schema['properties']['max_price']['minimum']);
		$this->assertSame(['city'], $schema['required']);
	}

	public function testGeneratesAnEmptyObjectInputSchemaWhenNoParams(): void
	{
		$def = SavedQueryToolDefinition::fromArray('blog', 'public', [
			'id'          => 'find_featured',
			'description' => 'Find featured posts.',
			'filters'     => ['featured' => ['value' => true]],
		]);

		$schema = $this->factory->inputSchemaFor($def);

		$this->assertSame('object', $schema['type']);
		// properties must be a JSON object ({}), not a JSON array ([]). PHP
		// json_encode renders empty arrays as `[]`; stdClass forces `{}`.
		// Strict MCP clients (e.g. Inspector) reject `[]` for inputSchema.properties.
		$this->assertInstanceOf(\stdClass::class, $schema['properties']);
		$this->assertSame('{}', json_encode($schema['properties']));
		$this->assertSame([], $schema['required'] ?? []);
	}

	public function testGeneratesAnOutputSchemaDescribingTheSharedPaginatedEnvelopeShape(): void
	{
		$def = SavedQueryToolDefinition::fromArray('listings', 'public', [
			'id'          => 'find_listings',
			'description' => 'Find listings.',
		]);

		$schema = $this->factory->outputSchemaFor($def);

		$this->assertSame('object', $schema['type']);
		$this->assertSame(['items', 'total', 'limit', 'offset', 'has_more'], $schema['required']);
		$this->assertFalse($schema['additionalProperties']);
		$this->assertSame('array', $schema['properties']['items']['type']);
		$this->assertStringContainsString('listings', $schema['properties']['items']['description']);
		$this->assertSame('object', $schema['properties']['items']['items']['type']);
		$this->assertSame(['id'], $schema['properties']['items']['items']['required']);
		$this->assertSame('string', $schema['properties']['items']['items']['properties']['id']['type']);
		$this->assertSame('string', $schema['properties']['items']['items']['properties']['url']['type']);
		$this->assertSame('integer', $schema['properties']['total']['type']);
		$this->assertSame('integer', $schema['properties']['limit']['type']);
		$this->assertSame('integer', $schema['properties']['offset']['type']);
		$this->assertSame('boolean', $schema['properties']['has_more']['type']);
	}

	public function testOutputSchemaItemElementShapeMatchesQueryCollectionToolsExactly(): void
	{
		// Drift-proof: both tools' outputSchemas are built by the same
		// CollectionQueryResultFormatter::outputSchema(), so the per-item
		// element shape (the piece that used to be duplicated
		// character-for-character) must be byte-identical. Only the
		// top-level `items` description is allowed to differ.
		$def = SavedQueryToolDefinition::fromArray('listings', 'public', [
			'id'          => 'find_listings',
			'description' => 'Find listings.',
		]);

		$savedQuerySchema = $this->factory->outputSchemaFor($def);

		$formatter                = new CollectionQueryResultFormatter();
		$queryCollectionItemsDesc = 'Matching objects in sort order. Field set varies by collection — call describe_collection for the schema.';
		$queryCollectionSchema    = $formatter->outputSchema($queryCollectionItemsDesc);

		$this->assertSame(
			$queryCollectionSchema['properties']['items']['items'],
			$savedQuerySchema['properties']['items']['items'],
		);
		$this->assertSame($queryCollectionSchema['required'], $savedQuerySchema['required']);
		$this->assertSame(
			$queryCollectionSchema['properties']['total'],
			$savedQuerySchema['properties']['total'],
		);
		$this->assertSame(
			$queryCollectionSchema['properties']['has_more'],
			$savedQuerySchema['properties']['has_more'],
		);
		// The one deliberate difference: the items description carries the
		// bound collection name for saved-query tools.
		$this->assertNotSame(
			$queryCollectionSchema['properties']['items']['description'],
			$savedQuerySchema['properties']['items']['description'],
		);
		$this->assertStringContainsString('listings', $savedQuerySchema['properties']['items']['description']);
	}

	public function testComposesADescriptionBlockWithTheParamCatalog(): void
	{
		$def = SavedQueryToolDefinition::fromArray('listings', 'public', [
			'id'          => 'find_listings',
			'description' => 'Search active listings.',
			'params'      => [
				'city'      => ['type' => 'string', 'description' => 'City name.', 'required' => true],
				'max_price' => ['type' => 'number', 'description' => 'Max USD.', 'minimum' => 0],
			],
		]);

		$desc = $this->factory->descriptionFor($def);

		$this->assertStringContainsString('Search active listings.', $desc);
		$this->assertStringContainsString('Parameters:', $desc);
		$this->assertStringContainsString('city', $desc);
		$this->assertStringContainsString('required', $desc);
		$this->assertStringContainsString('string', $desc);
		$this->assertStringContainsString('max_price', $desc);
		$this->assertStringContainsString('optional', $desc);
		$this->assertStringContainsString('number', $desc);
		$this->assertStringContainsString('Returns:', $desc);
	}

	public function testClosureForReturnsNoArgClosureWhenNoParams(): void
	{
		$def = SavedQueryToolDefinition::fromArray('blog', 'public', [
			'id'          => 'find_featured',
			'description' => 'Featured posts.',
			'filters'     => ['featured' => ['value' => true]],
		]);

		// SavedQueryTool is final — build via the factory so the closure can be
		// constructed; reflection-only assertions don't invoke handle().
		$tool = $this->factory->build($def);

		$closure = $this->factory->closureFor($def, $tool);

		$reflection = new \ReflectionFunction($closure);
		$this->assertCount(0, $reflection->getParameters(), 'no-arg closure expected when params is empty');
		$this->assertTrue($reflection->isStatic(), 'no-param path should produce a static closure');
	}

	public function testClosureForBuildsNamedParameterClosureFromSpec(): void
	{
		$def = SavedQueryToolDefinition::fromArray('listings', 'public', [
			'id'          => 'find_listings',
			'description' => 'Find listings.',
			'params'      => [
				'city'      => ['type' => 'string', 'required' => true],
				'max_price' => ['type' => 'number'],
			],
		]);

		// SavedQueryTool is final — build via the factory; reflection assertions
		// verify the closure shape the SDK will see without invoking handle().
		$tool = $this->factory->build($def);

		$closure = $this->factory->closureFor($def, $tool);

		$reflection = new \ReflectionFunction($closure);
		$params     = $reflection->getParameters();
		$this->assertCount(2, $params);

		$this->assertSame('city', $params[0]->getName());
		$this->assertSame('string', (string)$params[0]->getType());
		$this->assertFalse($params[0]->isOptional());

		$this->assertSame('max_price', $params[1]->getName());
		$this->assertSame('?float', (string)$params[1]->getType());
		$this->assertTrue($params[1]->isOptional());
	}
}
