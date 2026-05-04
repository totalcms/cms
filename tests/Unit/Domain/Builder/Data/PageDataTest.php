<?php

declare(strict_types=1);

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
			'description' => 'About our company',
			'draft'       => true,
			'nav'         => false,
		]);

		$this->assertSame('about', $page->id);
		$this->assertSame('About Us', $page->title);
		$this->assertSame('/about', $page->route);
		$this->assertSame('about', $page->template);
		$this->assertSame('About our company', $page->description);
		$this->assertTrue($page->draft);
		$this->assertFalse($page->nav);
	}

	public function testDefaultValues(): void
	{
		$page = new PageData([]);

		$this->assertSame('', $page->id);
		$this->assertSame('', $page->title);
		$this->assertSame('', $page->route);
		$this->assertSame('', $page->template);
		$this->assertSame('', $page->description);
		$this->assertFalse($page->draft);
		$this->assertTrue($page->nav);
		$this->assertSame(200, $page->status);
		$this->assertSame([], $page->data);
	}

	public function testStatusAcceptsCustomValue(): void
	{
		$page = new PageData(['status' => 404]);

		$this->assertSame(404, $page->status);
	}

	public function testStatusClampsOutOfRangeValuesToDefault(): void
	{
		$this->assertSame(200, (new PageData(['status' => 99]))->status);
		$this->assertSame(200, (new PageData(['status' => 600]))->status);
		$this->assertSame(200, (new PageData(['status' => 0]))->status);
	}

	public function testStatusCoercesStringNumeric(): void
	{
		$page = new PageData(['status' => '503']);

		$this->assertSame(503, $page->status);
	}

	public function testDataDecodesFromJsonString(): void
	{
		$page = new PageData(['data' => '{"hero":"Welcome","cta":{"label":"Sign up","url":"/signup"}}']);

		$this->assertSame('Welcome', $page->data['hero']);
		$this->assertSame(['label' => 'Sign up', 'url' => '/signup'], $page->data['cta']);
	}

	public function testDataAcceptsAlreadyDecodedArray(): void
	{
		$page = new PageData(['data' => ['hero' => 'Welcome']]);

		$this->assertSame(['hero' => 'Welcome'], $page->data);
	}

	public function testDataFallsBackToEmptyArrayOnInvalidJson(): void
	{
		$page = new PageData(['data' => '{not valid json']);

		$this->assertSame([], $page->data);
	}

	public function testDataFallsBackToEmptyArrayOnEmptyString(): void
	{
		$page = new PageData(['data' => '   ']);

		$this->assertSame([], $page->data);
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
			'id'              => 'home',
			'title'           => 'Home',
			'route'           => '/',
			'template'        => 'index',
			'description'     => 'Welcome',
			'image'           => [
				'name'   => 'hero.jpg',
				'link'   => 'home/hero.jpg',
				'width'  => 1920,
				'height' => 1080,
			],
			'draft'           => false,
			'nav'             => true,
			'sitemap'         => true,
			'changeFrequency' => 'weekly',
			'priority'        => 0.8,
			'status'          => 200,
			'redirectTo'      => '',
			'data'            => ['hero' => 'Welcome'],
		];

		$page = new PageData($data);
		$out  = $page->toArray();

		$this->assertSame('home', $out['id']);
		$this->assertSame('Home', $out['title']);
		$this->assertSame(['hero' => 'Welcome'], $out['data']);
		// Image is wrapped as ImageData and then transformed back — exact array
		// shape includes all the typed defaults, so just spot-check the inputs.
		$this->assertSame('hero.jpg', $out['image']['name']);
		$this->assertSame('home/hero.jpg', $out['image']['link']);
		$this->assertSame(1920, $out['image']['width']);
		$this->assertSame(1080, $out['image']['height']);
	}

	public function testToArrayRoundTrip(): void
	{
		$original = new PageData([
			'id'    => 'test',
			'title' => 'Test',
			'route' => '/test',
			'draft' => true,
			'nav'   => false,
		]);

		$reconstructed = new PageData($original->toArray());

		$this->assertSame($original->toArray(), $reconstructed->toArray());
	}

	// --- middleware ---

	public function testMiddlewareDefaultsToEmptyList(): void
	{
		$page = new PageData([]);

		$this->assertSame([], $page->middleware);
	}

	public function testMiddlewareAcceptsPlainArray(): void
	{
		$page = new PageData(['middleware' => ['auth', 'rate-limit']]);

		$this->assertSame(['auth', 'rate-limit'], $page->middleware);
	}

	public function testMiddlewareDecodesJsonStringFromMultiselectForm(): void
	{
		// The multiselect form widget serializes values as a JSON array string.
		$page = new PageData(['middleware' => '["auth","rate-limit"]']);

		$this->assertSame(['auth', 'rate-limit'], $page->middleware);
	}

	public function testMiddlewareAcceptsCommaSeparatedStringFallback(): void
	{
		$page = new PageData(['middleware' => 'auth, rate-limit']);

		$this->assertSame(['auth', 'rate-limit'], $page->middleware);
	}

	public function testMiddlewareDropsEmptyAndNonStringEntries(): void
	{
		$page = new PageData(['middleware' => ['auth', '', '  ', null, 42, 'log']]);

		$this->assertSame(['auth', 'log'], $page->middleware);
	}

	public function testMiddlewareEmptyStringYieldsEmptyList(): void
	{
		$page = new PageData(['middleware' => '']);

		$this->assertSame([], $page->middleware);
	}

	public function testMiddlewareInvalidJsonYieldsEmptyList(): void
	{
		// Looks like an array (leading `[`), but isn't valid JSON.
		$page = new PageData(['middleware' => '[broken']);

		$this->assertSame([], $page->middleware);
	}

	public function testMiddlewareIncludedInToArray(): void
	{
		$page = new PageData(['middleware' => ['auth']]);

		$this->assertSame(['auth'], $page->toArray()['middleware']);
	}

	// --- accessGroups ---

	public function testAccessGroupsDefaultsToEmptyList(): void
	{
		$this->assertSame([], (new PageData([]))->accessGroups);
	}

	public function testAccessGroupsAcceptsPlainArray(): void
	{
		$page = new PageData(['accessGroups' => ['staff', 'editors']]);

		$this->assertSame(['staff', 'editors'], $page->accessGroups);
	}

	public function testAccessGroupsDecodesJsonStringFromListField(): void
	{
		// The list form widget serializes values as a JSON array string.
		$page = new PageData(['accessGroups' => '["staff","editors"]']);

		$this->assertSame(['staff', 'editors'], $page->accessGroups);
	}

	public function testAccessGroupsDropsEmptyAndNonStringEntries(): void
	{
		$page = new PageData(['accessGroups' => ['staff', '', null, 42, 'editors']]);

		$this->assertSame(['staff', 'editors'], $page->accessGroups);
	}

	public function testAccessGroupsIncludedInToArray(): void
	{
		$page = new PageData(['accessGroups' => ['staff']]);

		$this->assertSame(['staff'], $page->toArray()['accessGroups']);
	}
}
