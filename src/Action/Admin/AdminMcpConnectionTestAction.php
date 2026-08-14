<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Mcp\Service\McpConnectionChecker;
use TotalCMS\Renderer\TwigRenderer;

/**
 * HTMX target for the "Test connection" button on Settings → MCP.
 * Runs the outbound self-probes (seconds, not ms — the button is explicit
 * user intent, never run on page load) and renders the results partial.
 */
readonly class AdminMcpConnectionTestAction
{
	public function __construct(
		private McpConnectionChecker $checker,
		private TwigRenderer $twigRenderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->twigRenderer->template($response, 'admin/mcp-connection-test.twig', [
			'checks' => $this->checker->run(),
		]);
	}
}
