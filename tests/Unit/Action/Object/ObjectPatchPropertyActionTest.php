<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Object;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use TotalCMS\Action\Object\ObjectPatchPropertyAction;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

final class ObjectPatchPropertyActionTest extends TestCase
{
	public function testPassesGuardForcedValuesAsTrailingArgument(): void
	{
		// A real guard over a schema that forces draft => true for non-admins.
		$guard = $this->forcedGuard();

		$patcher = $this->createMock(ObjectPatcher::class);
		$patcher->expects($this->once())
			->method('patchObjectProperty')
			->with('showcase', 'site-1', 'meta', ['author' => 'Jane'], ['draft' => true])
			->willReturn($this->createMock(ObjectData::class));

		$response = $this->createMock(ResponseInterface::class);
		$renderer = $this->createMock(JsonRenderer::class);
		$renderer->method('jsonItem')->willReturn($response);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['author' => 'Jane']);

		$action = new ObjectPatchPropertyAction($renderer, $patcher, $guard);
		$result = $action($request, $response, ['collection' => 'showcase', 'id' => 'site-1', 'property' => 'meta']);

		$this->assertSame($response, $result);
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
