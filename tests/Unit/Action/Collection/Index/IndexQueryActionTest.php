<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Collection\Index;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Collection\Index\IndexQueryAction;
use TotalCMS\Action\QueryActionRenderer;
use TotalCMS\Domain\Collection\Data\OwnerScope;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Support\Config;

/**
 * The action's one job for owned collections: hand the middleware's
 * `ownerScope` attribute (an OwnerScope) to the service, which applies it
 * before the query. Anything else in the attribute is ignored.
 */
final class IndexQueryActionTest extends TestCase
{
	/**
	 * @param array<string,string> $params
	 *
	 * @return array{0:string,1:array<string,string>,2:OwnerScope|null}
	 */
	private function invoke(mixed $scope, array $params = []): array
	{
		$captured = ['', [], null];

		$service = $this->createMock(IndexQueryService::class);
		$service->method('query')->willReturnCallback(
			function (string $collection, array $params, ?OwnerScope $scope = null) use (&$captured): QueryResult {
				$captured = [$collection, $params, $scope];

				return new QueryResult([], 0, 20, 0);
			},
		);

		$renderer = $this->createMock(QueryActionRenderer::class);
		$renderer->method('render')->willReturn($this->createMock(ResponseInterface::class));

		$config      = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->api = '';

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn($params);
		$request->method('getAttribute')->willReturnCallback(
			static fn (string $name, $default = null) => $name === 'ownerScope' ? $scope : $default,
		);

		(new IndexQueryAction($service, $renderer, $config))($request, $this->createMock(ResponseInterface::class), ['collection' => 'showcase']);

		return $captured;
	}

	public function testPassesNoScopeWithoutOne(): void
	{
		[$collection, , $scope] = $this->invoke(null);

		expect($collection)->toBe('showcase');
		expect($scope)->toBeNull();
	}

	public function testPassesTheOwnerScopeToTheService(): void
	{
		$ownerScope = new OwnerScope('owner', 'alex');

		[, $params, $scope] = $this->invoke($ownerScope, ['limit' => '5']);

		expect($scope)->toBe($ownerScope);
		expect($params['limit'])->toBe('5');
	}

	public function testIgnoresAnAttributeThatIsNotAnOwnerScope(): void
	{
		[, , $scope] = $this->invoke(['property' => 'owner', 'value' => 'alex']);

		expect($scope)->toBeNull();
	}
}
