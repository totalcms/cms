<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Docs;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Docs\Service\DocsPageLoader;
use TotalCMS\Domain\Docs\Service\DocsResource;

final class DocsPageLoaderTest extends TestCase
{
	private string $root = '';
	private DocsPageLoader $loader;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/tcms-docs-' . uniqid();
		mkdir($this->root . '/guide/images', 0755, true);
		file_put_contents($this->root . '/index.md', '# Home');
		file_put_contents($this->root . '/guide/page.md', '# Page');
		file_put_contents($this->root . '/guide/images/shot.png', 'png');
		file_put_contents($this->root . '/search-index.json', '[]');
		file_put_contents($this->root . '/legacy.html', '<p>old</p>');
		file_put_contents($this->root . '/menu.php', '<?php return [["title" => "Guide", "sub" => []], "junk"];');

		$this->loader = new DocsPageLoader($this->root);
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	/** @return iterable<string, array{string, string}> */
	public static function pagePaths(): iterable
	{
		yield 'empty is index'     => ['', 'index'];
		yield 'slashes trimmed'    => ['/guide/page/', 'guide/page'];
		yield 'duplicate slashes'  => ['guide//page', 'guide/page'];
		yield 'backslashes'        => ['guide\\page', 'guide/page'];
		yield 'traversal rejected' => ['../../etc/passwd', 'index'];
		yield 'odd characters'     => ['guide/<script>', 'index'];
		yield 'image filenames'    => ['guide/images/shot.png', 'guide/images/shot.png'];
	}

	/** @dataProvider pagePaths */
	public function testSanitizeNormalizesAndFallsBackToIndex(string $in, string $out): void
	{
		$this->assertSame($out, $this->loader->sanitize($in));
	}

	public function testResolveTellsTheKindsApart(): void
	{
		$this->assertTrue($this->loader->resolve('index')->is(DocsResource::MARKDOWN));
		$this->assertTrue($this->loader->resolve('legacy')->is(DocsResource::HTML));
		$this->assertTrue($this->loader->resolve('search-index')->is(DocsResource::JSON));
		$this->assertTrue($this->loader->resolve('nope')->is(DocsResource::MISSING));

		$image = $this->loader->resolve('guide/images/shot.png');
		$this->assertTrue($image->is(DocsResource::IMAGE));
		$this->assertSame('image/png', $image->mime);
		$this->assertSame($this->root . '/guide/images/shot.png', $image->path);
	}

	public function testAMissingImageIsMissingNotAPage(): void
	{
		$this->assertTrue($this->loader->resolve('guide/images/none.png')->is(DocsResource::MISSING));
	}

	public function testMenuKeepsOnlyStringKeyedGroups(): void
	{
		$this->assertSame([['title' => 'Guide', 'sub' => []]], $this->loader->menu());
	}
}
