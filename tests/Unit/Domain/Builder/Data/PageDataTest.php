<?php

namespace Tests\Unit\Domain\Builder\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Data\PageData;

final class PageDataTest extends TestCase
{
	public function testConstructsFromFullData(): void
	{
		$page = new PageData([
			'id'          => 'about',
			'title'       => 'About Us',
			'route'       => '/about',
			'template'    => 'about',
			'layout'      => 'wide',
			'description' => 'About our company',
			'draft'       => true,
			'nav'         => false,
			'sort'        => 5,
			'parent'      => 'company',
		]);

		$this->assertSame('about', $page->id);
		$this->assertSame('About Us', $page->title);
		$this->assertSame('/about', $page->route);
		$this->assertSame('about', $page->template);
		$this->assertSame('wide', $page->layout);
		$this->assertSame('About our company', $page->description);
		$this->assertTrue($page->draft);
		$this->assertFalse($page->nav);
		$this->assertSame(5, $page->sort);
		$this->assertSame('company', $page->parent);
	}

	public function testDefaultValues(): void
	{
		$page = new PageData([]);

		$this->assertSame('', $page->id);
		$this->assertSame('', $page->title);
		$this->assertSame('', $page->route);
		$this->assertSame('', $page->template);
		$this->assertSame('default', $page->layout);
		$this->assertSame('', $page->description);
		$this->assertFalse($page->draft);
		$this->assertTrue($page->nav);
		$this->assertSame(0, $page->sort);
		$this->assertSame('', $page->parent);
	}

	public function testIsPublishedWhenNotDraft(): void
	{
		$page = new PageData(['draft' => false]);

		$this->assertTrue($page->isPublished());
	}

	public function testIsNotPublishedWhenDraft(): void
	{
		$page = new PageData(['draft' => true]);

		$this->assertFalse($page->isPublished());
	}

	public function testNavDefaultsToTrue(): void
	{
		$page = new PageData(['id' => 'test']);

		$this->assertTrue($page->nav);
	}

	public function testNavCanBeDisabled(): void
	{
		$page = new PageData(['id' => 'test', 'nav' => false]);

		$this->assertFalse($page->nav);
	}

	public function testToArrayIncludesAllFields(): void
	{
		$data = [
			'id'          => 'home',
			'title'       => 'Home',
			'route'       => '/',
			'template'    => 'index',
			'layout'      => 'default',
			'description' => 'Welcome',
			'draft'       => false,
			'nav'         => true,
			'sort'        => 0,
			'parent'      => '',
		];

		$page = new PageData($data);

		$this->assertSame($data, $page->toArray());
	}

	public function testToArrayRoundTrip(): void
	{
		$original = new PageData([
			'id'     => 'test',
			'title'  => 'Test',
			'route'  => '/test',
			'draft'  => true,
			'nav'    => false,
			'sort'   => 3,
			'parent' => 'root',
		]);

		$reconstructed = new PageData($original->toArray());

		$this->assertSame($original->toArray(), $reconstructed->toArray());
	}
}
