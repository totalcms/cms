<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Designer;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Action\Designer\DesignerTemplateUpdateAction;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Template\Exception\TemplatesLockedException;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Renderer\JsonRenderer;

/**
 * The Designer API is a public, token-gated route. Git-managed templates never
 * travel by it, even on a dev install where the dashboard may edit them.
 */
final class DesignerTemplateUpdateActionTest extends TestCase
{
	public function testRefusesGitManagedTemplatesEvenWhenUnlocked(): void
	{
		$paths = $this->createMock(BuilderTemplatePaths::class);
		$paths->method('isProjectManaged')->willReturn(true);
		$paths->method('locked')->willReturn(false);

		$saver = $this->createMock(TemplateSaver::class);
		$saver->expects($this->never())->method('saveTemplate');

		$action  = new DesignerTemplateUpdateAction(new JsonRenderer(), $saver, $paths);
		$request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/designer/templates/hero')
			->withAttribute('designerTemplate', 'hero');

		$this->expectException(TemplatesLockedException::class);
		$action($request, (new ResponseFactory())->createResponse(), []);
	}
}
