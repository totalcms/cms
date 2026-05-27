<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Prompt\Service;

use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptArgData;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptData;
use TotalCMS\Domain\Mcp\Prompt\Exception\PromptRenderException;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptRenderer;
use TotalCMS\Domain\Twig\Service\TwigEngine;

final class PromptRendererTest extends TestCase
{
	private TwigEngine $twig;
	private PromptRenderer $renderer;

	protected function setUp(): void
	{
		// TwigEngine has too many deps to mock here; use a thin stub.
		$this->twig     = $this->createMock(TwigEngine::class);
		$this->renderer = new PromptRenderer($this->twig);
	}

	public function testRendersBodyWithArgs(): void
	{
		$this->twig
			->expects($this->once())
			->method('renderString')
			->with(
				'Hello {{ args.name }}',
				$this->callback(fn (array $ctx): bool => ($ctx['args']['name'] ?? null) === 'Joe'),
			)
			->willReturn('Hello Joe');

		$prompt = new PromptData(
			name:        'greet',
			description: 'Greet someone',
			body:        'Hello {{ args.name }}',
			args:        [new PromptArgData('name', '', true)],
		);

		$result = $this->renderer->render($prompt, ['name' => 'Joe']);

		$this->assertCount(1, $result->messages);
		$this->assertInstanceOf(PromptMessage::class, $result->messages[0]);
		$this->assertSame(Role::User, $result->messages[0]->role);
		$this->assertInstanceOf(TextContent::class, $result->messages[0]->content);
		$this->assertSame('Hello Joe', $result->messages[0]->content->text);
		$this->assertSame('Greet someone', $result->description);
	}

	public function testThrowsOnMissingRequiredArg(): void
	{
		$prompt = new PromptData(
			name:        'greet',
			description: 'Greet',
			body:        'Hello {{ args.name }}',
			args:        [new PromptArgData('name', '', true)],
		);

		$this->expectException(PromptRenderException::class);
		$this->expectExceptionMessageMatches('/required argument.*name/i');

		$this->renderer->render($prompt, []);
	}

	public function testIgnoresExtraArgs(): void
	{
		$this->twig
			->method('renderString')
			->with('hi', $this->callback(function (array $ctx): bool {
				return $ctx['args'] === ['name' => 'Joe']; // 'extra' dropped
			}))
			->willReturn('hi');

		$prompt = new PromptData(
			name:        'p',
			description: 'd',
			body:        'hi',
			args:        [new PromptArgData('name')],
		);

		$result = $this->renderer->render($prompt, ['name' => 'Joe', 'extra' => 'dropped']);
		$this->assertSame('hi', $result->messages[0]->content->text);
	}

	public function testWrapsTwigErrorsInPromptRenderException(): void
	{
		$this->twig
			->method('renderString')
			->willThrowException(new \Twig\Error\SyntaxError('bad syntax'));

		$prompt = new PromptData(name: 'p', description: 'd', body: '{{ broken');

		$this->expectException(PromptRenderException::class);
		$this->expectExceptionMessageMatches('/twig.*bad syntax/i');

		$this->renderer->render($prompt, []);
	}
}
