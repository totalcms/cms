<?php

namespace Tests\Unit\Action\Object;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Object\ObjectUpdatePropertyAction;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

final class ObjectUpdatePropertyActionTest extends TestCase
{
	private ObjectUpdatePropertyAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $objectUpdater;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

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
		$config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = ['enable' => true, 'collection' => 'auth'];
		$policy       = new AuthFieldPolicy(
			$schemaFetcher,
			$this->createMock(UserValidationService::class),
			$this->createMock(ObjectFetcher::class),
			$config,
		);
		$guard = new PrivilegedFieldGuard($policy, $this->createMock(SessionInterface::class));

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
			->with($this->response, $objectData, $this->isInstanceOf(\TotalCMS\Transformer\ObjectMetaTransformer::class))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}
}
