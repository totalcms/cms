<?php

declare(strict_types=1);

namespace Tests\Unit\Bundled\AlgoliaSearch;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/algolia-search/Service/AlgoliaSearchProvider.php';

use PHPUnit\Framework\TestCase;
use TotalCMS\Bundled\AlgoliaSearch\Service\AlgoliaSearchProvider;
use TotalCMS\Domain\Search\Data\SearchQuery;

final class AlgoliaSearchProviderTest extends TestCase
{
	public function testIdAndLabel(): void
	{
		$p = new AlgoliaSearchProvider('app', 'admin', 'search', 'idx');
		$this->assertSame('algolia', $p->id());
		$this->assertSame('Algolia', $p->label());
	}

	public function testIsAvailableFalseWhenAppIdMissing(): void
	{
		$this->assertFalse((new AlgoliaSearchProvider('', 'a', 's', 'i'))->isAvailable());
	}

	public function testIsAvailableFalseWhenAdminKeyMissing(): void
	{
		$this->assertFalse((new AlgoliaSearchProvider('a', '', 's', 'i'))->isAvailable());
	}

	public function testIsAvailableFalseWhenSearchKeyMissing(): void
	{
		$this->assertFalse((new AlgoliaSearchProvider('a', 'a', '', 'i'))->isAvailable());
	}

	public function testIsAvailableTrueWhenAllKeysPresent(): void
	{
		$p = new AlgoliaSearchProvider('app', 'admin', 'search', 'idx');
		$this->assertTrue($p->isAvailable());
	}

	public function testSearchReturnsEmptyWhenUnavailable(): void
	{
		$p = new AlgoliaSearchProvider('', '', '', 'idx');
		$this->assertSame([], $p->search(new SearchQuery(text: 'x')));
	}

	public function testIndexAndDeleteAreNoopWhenUnavailable(): void
	{
		$p = new AlgoliaSearchProvider('', '', '', 'idx');
		// Should not throw — silent skip on missing keys.
		$p->index('blog', 'post-1', ['title' => 'x']);
		$p->delete('blog', 'post-1');
		$this->assertTrue(true);
	}

	// NOTE: Tests that exercise actual Algolia API calls require an HTTP mock
	// or a stubbed SearchClient. v1 ships with the guard-rail tests above;
	// integration testing against real Algolia happens via the extension's
	// smoke test in C7 and the manual operator setup in the README.
}
