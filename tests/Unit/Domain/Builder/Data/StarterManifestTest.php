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

	public function testDefaultsForMissingFields(): void
	{
		$m = new StarterManifest([], '/starters/x');

		$this->assertSame('Unknown', $m->name);
		$this->assertSame('', $m->description);
		$this->assertSame('1.0.0', $m->version);
	}

	public function testNonNumericVersionCoercesToString(): void
	{
		$m = new StarterManifest(['version' => 42], '/starters/x');

		$this->assertSame('42', $m->version);
	}

	public function testToArrayReturnsAllFields(): void
	{
		$m = new StarterManifest([
			'name'        => 'Blog',
			'description' => 'desc',
			'version'     => '1.2.3',
		], '/starters/blog');

		$this->assertSame([
			'name'        => 'Blog',
			'description' => 'desc',
			'version'     => '1.2.3',
		], $m->toArray());
	}

	public function testExtraManifestFieldsAreIgnored(): void
	{
		// Old-format manifests still on disk (with a `pages` key) should not
		// trip up the parser — extra fields are just dropped.
		$m = new StarterManifest([
			'name'  => 'Blog',
			'pages' => [['id' => 'home', 'title' => 'Home']],
		], '/starters/blog');

		$this->assertSame('Blog', $m->name);
	}
}
