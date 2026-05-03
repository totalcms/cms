<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Data\StarterManifest;

final class StarterManifestTest extends TestCase
{
	public function testParsesAllTopLevelFields(): void
	{
		$m = new StarterManifest([
			'name'        => 'Blog',
			'description' => 'A blog starter',
			'version'     => '2.1.0',
		], '/starters/blog');

		$this->assertSame('Blog', $m->name);
		$this->assertSame('A blog starter', $m->description);
		$this->assertSame('2.1.0', $m->version);
		$this->assertSame('/starters/blog', $m->directory);
	}

	public function testNameDefaultsToUnknownWhenMissing(): void
	{
		$m = new StarterManifest([], '/starters/x');

		$this->assertSame('Unknown', $m->name);
		$this->assertSame('', $m->description);
		$this->assertSame('1.0.0', $m->version);
	}

	public function testParsesPagesWithExplicitFields(): void
	{
		$m = new StarterManifest([
			'pages' => [
				['id' => 'home', 'title' => 'Home', 'route' => '/', 'template' => 'index', 'nav' => true],
				['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'about', 'nav' => false],
			],
		], '/starters/x');

		$this->assertCount(2, $m->pages);
		$this->assertSame('home', $m->pages[0]['id']);
		$this->assertSame('Home', $m->pages[0]['title']);
		$this->assertSame('/', $m->pages[0]['route']);
		$this->assertSame('index', $m->pages[0]['template']);
		$this->assertTrue($m->pages[0]['nav']);
		$this->assertFalse($m->pages[1]['nav']);
	}

	public function testRouteFallsBackToPathFieldWithLeadingSlash(): void
	{
		$m = new StarterManifest([
			'pages' => [
				['id' => 'about', 'title' => 'About', 'path' => 'about'],
			],
		], '/starters/x');

		$this->assertSame('/about', $m->pages[0]['route']);
	}

	public function testRouteFallsBackToEmptyWhenNeitherRouteNorPath(): void
	{
		$m = new StarterManifest([
			'pages' => [
				['id' => 'home', 'title' => 'Home'],
			],
		], '/starters/x');

		$this->assertSame('/', $m->pages[0]['route']);
	}

	public function testTemplateFallsBackToId(): void
	{
		$m = new StarterManifest([
			'pages' => [
				['id' => 'about', 'title' => 'About', 'route' => '/about'],
			],
		], '/starters/x');

		$this->assertSame('about', $m->pages[0]['template']);
	}

	public function testNavDefaultsToTrue(): void
	{
		$m = new StarterManifest([
			'pages' => [
				['id' => 'home', 'title' => 'Home'],
			],
		], '/starters/x');

		$this->assertTrue($m->pages[0]['nav']);
	}

	public function testNonArrayPageEntriesAreFiltered(): void
	{
		$m = new StarterManifest([
			'pages' => [
				['id' => 'home', 'title' => 'Home'],
				'not-an-array',
				42,
				['id' => 'about', 'title' => 'About'],
			],
		], '/starters/x');

		$this->assertCount(2, $m->pages);
		$this->assertSame('home', $m->pages[0]['id']);
		$this->assertSame('about', $m->pages[1]['id']);
	}

	public function testEmptyIdEntryIsKeptAsEmptyString(): void
	{
		// The manifest layer doesn't validate id presence — that's the
		// service's job. The cast just ensures we hand a string downstream.
		$m = new StarterManifest([
			'pages' => [
				['title' => 'Mysterious'],
			],
		], '/starters/x');

		$this->assertSame('', $m->pages[0]['id']);
	}

	public function testToArraySummary(): void
	{
		$m = new StarterManifest([
			'name'        => 'Blog',
			'description' => 'desc',
			'version'     => '1.2.3',
			'pages'       => [
				['id' => 'home', 'title' => 'Home'],
				['id' => 'about', 'title' => 'About'],
			],
		], '/starters/blog');

		$this->assertSame([
			'name'        => 'Blog',
			'description' => 'desc',
			'version'     => '1.2.3',
			'pages'       => 2,
		], $m->toArray());
	}

	public function testEmptyPagesProducesEmptyList(): void
	{
		$m = new StarterManifest(['name' => 'X'], '/starters/x');

		$this->assertSame([], $m->pages);
	}

	public function testNonNumericVersionStillCoercesToString(): void
	{
		$m = new StarterManifest(['version' => 42], '/starters/x');

		$this->assertSame('42', $m->version);
	}
}
