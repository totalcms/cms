<?php

namespace Tests\Unit\Action\Object;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use TotalCMS\Action\Object\ObjectUpdatePropertyAction;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class ObjectUpdatePropertyActionTest extends TestCase
{
	private ObjectUpdatePropertyAction $action;
	private MockObject $objectUpdater;
	private MockObject $renderer;
	private MockObject $request;
	private MockObject $response;

	protected function setUp(): void
	{
		$this->objectUpdater = $this->createMock(ObjectUpdater::class);
		$this->renderer      = $this->createMock(JsonRenderer::class);
		$this->request       = $this->createMock(ServerRequestInterface::class);
		$this->response      = $this->createMock(ResponseInterface::class);

		// Real guard with an empty-schema policy: protectedFieldsFor() is always
		// empty for these non-auth collections, so guardProperty() never blocks.
		$schemaFetcher = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchemaForCollection')->willReturn(new SchemaData());
		$config              = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth        = ['enable' => true, 'collection' => 'auth'];
		$policyLoggerFactory = $this->createMock(LoggerFactory::class);
		$policyLoggerFactory->method('channelLogger')->willReturn(new NullLogger());
		$ownerProperties = $this->createMock(OwnerPropertyResolver::class);
		$ownerProperties->method('resolve')->willReturn('');
		$policy = new AuthFieldPolicy(
			$schemaFetcher,
			$this->createMock(UserValidationService::class),
			$this->createMock(ObjectFetcher::class),
			$ownerProperties,
			$config,
			$policyLoggerFactory,
		);
		$guard = new PrivilegedFieldGuard($policy);

		$this->action = new ObjectUpdatePropertyAction($this->renderer, $this->objectUpdater, $guard);
	}

	public function testUpdatesPropertySuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'image',
		];

		$data = ['value' => 'new-image.jpg'];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectUpdater->expects($this->once())
			->method('updateObjectProperty')
			->with('products', 'product-1', 'image', $data)
			->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->anything())
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesAllArgsToUpdater(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'content',
		];

		$data = ['value' => 'Updated content'];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectUpdater->expects($this->once())
			->method('updateObjectProperty')
			->with('blog', 'post-5', 'content', $data)
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesBodyDataToUpdater(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'prop',
		];

		$data = [
			'value' => 'test value',
			'meta'  => ['key' => 'value'],
		];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectUpdater->expects($this->once())
			->method('updateObjectProperty')
			->with($this->anything(), $this->anything(), $this->anything(), $data)
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsJsonItemWithTransformer(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'prop',
		];

		$this->request->method('getParsedBody')->willReturn([]);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectUpdater->method('updateObjectProperty')->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->isInstanceOf(ObjectMetaTransformer::class))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesGuardForcedValuesAsTrailingArgument(): void
	{
		// A real guard over a schema that forces draft => true for non-admins.
		$action = new ObjectUpdatePropertyAction($this->renderer, $this->objectUpdater, $this->forcedGuard());

		$this->request->method('getParsedBody')->willReturn(['value' => 'x']);

		$this->objectUpdater->expects($this->once())
			->method('updateObjectProperty')
			->with('showcase', 'site-1', 'title', ['value' => 'x'], false, ['draft' => true])
			->willReturn($this->createMock(ObjectData::class));
		$this->renderer->method('jsonItem')->willReturn($this->response);

		$action($this->request, $this->response, ['collection' => 'showcase', 'id' => 'site-1', 'property' => 'title']);
	}

	private function forcedGuard(): PrivilegedFieldGuard
	{
		$schema             = new SchemaData();
		$schema->id         = 'showcase';
		$schema->properties = ['title' => [], 'draft' => ['protected' => ['value' => true]]];

		$schemaFetcher = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$config              = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth        = ['enable' => true, 'collection' => 'auth'];
		$loggerFactory       = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());
		$ownerProperties = $this->createMock(OwnerPropertyResolver::class);
		$ownerProperties->method('resolve')->willReturn('');

		$policy = new AuthFieldPolicy(
			$schemaFetcher,
			$this->createMock(UserValidationService::class),
			$this->createMock(ObjectFetcher::class),
			$ownerProperties,
			$config,
			$loggerFactory,
		);

		return new PrivilegedFieldGuard($policy);
	}
}
