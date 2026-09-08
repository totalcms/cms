<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\UtilsPageData;
use TotalCMS\Action\Admin\Utils\UtilsPageDataResolver;

final class UtilsPageDataResolverTest extends TestCase
{
	private function builder(string $marker): UtilsPageData
	{
		return new class($marker) implements UtilsPageData {
			public function __construct(private readonly string $marker)
			{
			}

			public function build(ServerRequestInterface $request, string $page, string $action): array
			{
				return ['marker' => $this->marker];
			}
		};
	}

	public function testReturnsTheBuilderRegisteredForAPage(): void
	{
		$oauth    = $this->builder('oauth');
		$resolver = new UtilsPageDataResolver(['oauth-clients' => $oauth, 'oauth-grants' => $oauth]);

		$this->assertSame($oauth, $resolver->for('oauth-grants'));
		$this->assertSame($oauth, $resolver->for('oauth-clients'));
	}

	public function testReturnsNullForAPageWithNoBuilder(): void
	{
		$resolver = new UtilsPageDataResolver(['sync' => $this->builder('sync')]);

		$this->assertNull($resolver->for('logs'));
		$this->assertNull($resolver->for('index'));
	}
}
