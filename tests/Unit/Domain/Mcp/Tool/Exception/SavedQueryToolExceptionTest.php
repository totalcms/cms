<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Exception;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Tool\Exception\SavedQueryToolException;

final class SavedQueryToolExceptionTest extends TestCase
{
	public function testForValidationCarriesToolNameAndHint(): void
	{
		$ex = SavedQueryToolException::forValidation(
			toolName: 'broken_tool',
			message: 'limit must be an integer',
		);

		$this->assertStringContainsString('broken_tool', $ex->getMessage());
		$this->assertStringContainsString('limit must be an integer', $ex->getMessage());
		$this->assertStringContainsString('admin', $ex->recoveryHint);
		$this->assertStringContainsString('fix', $ex->recoveryHint);
		$this->assertSame('validation', $ex->kind);
	}

	public function testForCollisionIdentifiesBothCollisionsAndSource(): void
	{
		$ex = SavedQueryToolException::forCollision(
			toolName: 'query_collection',
			source: 'core',
			owner: 'blog',
		);

		$this->assertStringContainsString('query_collection', $ex->getMessage());
		$this->assertStringContainsString('core', $ex->getMessage());
		$this->assertSame('collision', $ex->kind);
	}

	public function testForPlaceholderNamesTheOffendingPlaceholderAndTool(): void
	{
		$ex = SavedQueryToolException::forPlaceholder(
			placeholder: '{{params.unknown_field}}',
			toolName: 'find_listings',
		);

		$this->assertStringContainsString('unknown_field', $ex->getMessage());
		$this->assertStringContainsString('find_listings', $ex->getMessage());
		$this->assertSame('placeholder', $ex->kind);
	}
}
