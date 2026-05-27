<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Prompt\Service;

use Mcp\Exception\PromptGetException;
use Mcp\Server;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptData;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptRegistrar;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptRenderer;
use TotalCMS\Domain\Twig\Service\TwigEngine;

final class PromptRegistrarTest extends TestCase
{
	private PromptRenderer $renderer;
	private PromptRegistrar $registrar;

	protected function setUp(): void
	{
		$twig            = $this->createMock(TwigEngine::class);
		$this->renderer  = new PromptRenderer($twig);
		$this->registrar = new PromptRegistrar($this->renderer);
	}

	/**
	 * Build a Builder, register prompts on it, and capture the handlers via
	 * reflection (the $prompts property is private on the SDK's final Builder).
	 *
	 * @param list<PromptData> $prompts
	 * @return list<\Closure>
	 */
	private function captureHandlers(array $prompts, McpPersona $persona): array
	{
		$builder = Server::builder();
		$this->registrar->registerAll($builder, $prompts, $persona);

		$ref        = new \ReflectionProperty(Server\Builder::class, 'prompts');
		$registered = $ref->getValue($builder);

		return array_column($registered, 'handler');
	}

	public function testAddsEachPromptToBuilder(): void
	{
		$prompts = [
			new PromptData(name: 'p1', description: 'd1', body: 'b1', access: 'public'),
			new PromptData(name: 'p2', description: 'd2', body: 'b2', access: 'public'),
		];

		$handlers = $this->captureHandlers($prompts, McpPersona::ADMIN);
		$this->assertCount(2, $handlers);
	}

	public function testHandlerThrowsWhenPersonaCannotAccess(): void
	{
		$adminPrompt = new PromptData(name: 'draft_post', description: 'd', body: 'b', access: 'admin');
		$handlers    = $this->captureHandlers([$adminPrompt], McpPersona::PUBLIC_);

		$this->assertCount(1, $handlers);
		$this->expectException(PromptGetException::class);
		$this->expectExceptionMessageMatches('/draft_post/');

		($handlers[0])([]);
	}

	public function testHandlerRendersWhenPersonaCanAccess(): void
	{
		$twig = $this->createMock(TwigEngine::class);
		$twig
			->expects($this->once())
			->method('renderString')
			->willReturn('Hello Joe');

		$renderer  = new PromptRenderer($twig);
		$registrar = new PromptRegistrar($renderer);

		$builder = Server::builder();
		$publicPrompt = new PromptData(name: 'greet', description: 'd', body: 'Hello {{ args.name }}', access: 'public');
		$registrar->registerAll($builder, [$publicPrompt], McpPersona::PUBLIC_);

		$ref        = new \ReflectionProperty(Server\Builder::class, 'prompts');
		$registered = $ref->getValue($builder);

		$handler = $registered[0]['handler'];
		$result  = ($handler)(['name' => 'Joe']);
		$this->assertNotNull($result);
	}

	public function testPersonaCanAccessPublic(): void
	{
		$this->assertTrue(PromptRegistrar::personaCanAccess(McpPersona::PUBLIC_, 'public'));
		$this->assertTrue(PromptRegistrar::personaCanAccess(McpPersona::AUTHENTICATED, 'public'));
		$this->assertTrue(PromptRegistrar::personaCanAccess(McpPersona::ADMIN, 'public'));
	}

	public function testPersonaCanAccessAuthenticated(): void
	{
		$this->assertFalse(PromptRegistrar::personaCanAccess(McpPersona::PUBLIC_, 'authenticated'));
		$this->assertTrue(PromptRegistrar::personaCanAccess(McpPersona::AUTHENTICATED, 'authenticated'));
		$this->assertTrue(PromptRegistrar::personaCanAccess(McpPersona::ADMIN, 'authenticated'));
	}

	public function testPersonaCanAccessAdmin(): void
	{
		$this->assertFalse(PromptRegistrar::personaCanAccess(McpPersona::PUBLIC_, 'admin'));
		$this->assertFalse(PromptRegistrar::personaCanAccess(McpPersona::AUTHENTICATED, 'admin'));
		$this->assertTrue(PromptRegistrar::personaCanAccess(McpPersona::ADMIN, 'admin'));
	}

	public function testPersonaCanAccessDefaultsToAdmin(): void
	{
		$this->assertFalse(PromptRegistrar::personaCanAccess(McpPersona::PUBLIC_, 'unknown'));
		$this->assertFalse(PromptRegistrar::personaCanAccess(McpPersona::AUTHENTICATED, 'unknown'));
		$this->assertTrue(PromptRegistrar::personaCanAccess(McpPersona::ADMIN, 'unknown'));
	}
}
