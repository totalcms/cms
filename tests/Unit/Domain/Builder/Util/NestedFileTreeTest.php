<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Util;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Util\NestedFileTree;

final class NestedFileTreeTest extends TestCase
{
	public function testReturnsEmptyForEmptyInput(): void
	{
		$this->assertSame([], NestedFileTree::build([]));
	}

	public function testFlatPathsReturnFlatFileNodes(): void
	{
		$tree = NestedFileTree::build(['about', 'contact', 'home'], 'pages');

		$this->assertCount(3, $tree);
		$this->assertSame('file', $tree[0]['type']);
		$this->assertSame('about', $tree[0]['name']);
		$this->assertSame('about', $tree[0]['id']);
		$this->assertSame('pages/about', $tree[0]['path']);
	}

	public function testNestedPathsCreateFolderNodes(): void
	{
		$tree = NestedFileTree::build(['blog/post', 'blog/index', 'about'], 'pages');

		// Folders before files; both alphabetical
		$this->assertCount(2, $tree);
		$this->assertSame('folder', $tree[0]['type']);
		$this->assertSame('blog', $tree[0]['name']);
		$this->assertCount(2, $tree[0]['children']);
		$this->assertSame('index', $tree[0]['children'][0]['name']);
		$this->assertSame('blog/index', $tree[0]['children'][0]['id']);
		$this->assertSame('pages/blog/index', $tree[0]['children'][0]['path']);
		$this->assertSame('post', $tree[0]['children'][1]['name']);

		$this->assertSame('file', $tree[1]['type']);
		$this->assertSame('about', $tree[1]['name']);
	}

	public function testHandlesDeepNesting(): void
	{
		$tree = NestedFileTree::build(['docs/api/v1/intro', 'docs/api/v1/auth'], 'pages');

		$this->assertCount(1, $tree);
		$this->assertSame('docs', $tree[0]['name']);
		$this->assertSame('api', $tree[0]['children'][0]['name']);
		$this->assertSame('v1', $tree[0]['children'][0]['children'][0]['name']);
		$this->assertCount(2, $tree[0]['children'][0]['children'][0]['children']);

		$leaves = $tree[0]['children'][0]['children'][0]['children'];
		$this->assertSame('auth', $leaves[0]['name']);
		$this->assertSame('docs/api/v1/auth', $leaves[0]['id']);
		$this->assertSame('pages/docs/api/v1/auth', $leaves[0]['path']);
		$this->assertSame('intro', $leaves[1]['name']);
	}

	public function testFoldersSortBeforeFilesAtSameLevel(): void
	{
		$tree = NestedFileTree::build(['z-file', 'a-folder/inside', 'm-file'], 'pages');

		$this->assertSame('folder', $tree[0]['type']);
		$this->assertSame('a-folder', $tree[0]['name']);
		$this->assertSame('file', $tree[1]['type']);
		$this->assertSame('m-file', $tree[1]['name']);
		$this->assertSame('file', $tree[2]['type']);
		$this->assertSame('z-file', $tree[2]['name']);
	}

	public function testFoldersAreSortedAlphabetically(): void
	{
		$tree = NestedFileTree::build(['zoo/a', 'apple/b', 'mango/c'], 'pages');

		$this->assertSame('apple', $tree[0]['name']);
		$this->assertSame('mango', $tree[1]['name']);
		$this->assertSame('zoo', $tree[2]['name']);
	}

	public function testEmptyPrefixOmitsLeadingSlash(): void
	{
		$tree = NestedFileTree::build(['about', 'blog/post'], '');

		$this->assertSame('about', $tree[1]['path']);
		$this->assertSame('blog/post', $tree[0]['children'][0]['path']);
	}

	public function testFolderAndFileSameNameAtSameLevel(): void
	{
		// Both a `blog` file (i.e. blog.twig) and a `blog/` folder can coexist
		$tree = NestedFileTree::build(['blog', 'blog/post'], 'pages');

		$this->assertCount(2, $tree);
		$this->assertSame('folder', $tree[0]['type']);
		$this->assertSame('blog', $tree[0]['name']);
		$this->assertCount(1, $tree[0]['children']);
		$this->assertSame('post', $tree[0]['children'][0]['name']);

		$this->assertSame('file', $tree[1]['type']);
		$this->assertSame('blog', $tree[1]['name']);
		$this->assertSame('blog', $tree[1]['id']);
	}
}
