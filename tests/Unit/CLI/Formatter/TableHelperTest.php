<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Formatter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use TotalCMS\CLI\Formatter\TableHelper;

final class TableHelperTest extends TestCase
{
	public function testRenderKeyValuePairs(): void
	{
		$output = new BufferedOutput();

		TableHelper::renderKeyValue($output, [
			'Name'    => 'blog',
			'Version' => '3.2.2',
			'Status'  => 'active',
		]);

		$display = $output->fetch();

		expect($display)->toContain('Name');
		expect($display)->toContain('blog');
		expect($display)->toContain('Version');
		expect($display)->toContain('3.2.2');
	}

	public function testRenderKeyValueAlignsKeys(): void
	{
		$output = new BufferedOutput();

		TableHelper::renderKeyValue($output, [
			'ID'          => '1',
			'Description' => 'A longer key',
		]);

		$display = $output->fetch();
		$lines   = array_filter(explode("\n", $display));

		// Both lines should be present
		expect(count($lines))->toBe(2);
	}

	public function testRenderKeyValueHandlesArrayValues(): void
	{
		$output = new BufferedOutput();

		TableHelper::renderKeyValue($output, [
			'Tags' => ['blog', 'news'],
		]);

		$display = $output->fetch();
		expect($display)->toContain('["blog","news"]');
	}

	public function testRenderList(): void
	{
		$output = new BufferedOutput();

		TableHelper::renderList($output, ['ID', 'Name'], [
			['1', 'blog'],
			['2', 'gallery'],
		]);

		$display = $output->fetch();

		expect($display)->toContain('ID');
		expect($display)->toContain('Name');
		expect($display)->toContain('blog');
		expect($display)->toContain('gallery');
	}

	public function testRenderEmptyKeyValue(): void
	{
		$output = new BufferedOutput();

		TableHelper::renderKeyValue($output, []);

		$display = $output->fetch();
		expect($display)->toBe('');
	}
}
