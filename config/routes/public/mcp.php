<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\Mcp\McpDiscoveryAction;
use TotalCMS\Action\Mcp\McpEndpointAction;
use TotalCMS\Middleware\Security\McpCorsMiddleware;
use TotalCMS\Middleware\Security\McpRateLimitMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// MCP (Model Context Protocol) endpoint. POST carries JSON-RPC; GET handles the
	// SSE upgrade for streaming responses. The mcp/sdk StreamableHttpTransport
	// dispatches on method + Accept header, so a single action services both verbs.
	//
	// Middleware order (outer→inner): CORS → RateLimit → endpoint.
	// CORS runs FIRST so preflight OPTIONS short-circuits before the rate
	// limiter sees it (an OPTIONS doesn't carry credentials and shouldn't
	// burn a public-IP rate-limit slot). Slim's `->add()` wraps inside-out,
	// so the last add() runs FIRST — McpCorsMiddleware must be the final
	// `->add()` on each route.
	$app->post('/mcp', McpEndpointAction::class)
		->add(McpRateLimitMiddleware::class)
		->add(McpCorsMiddleware::class)
		->setName('mcp');
	$app->get('/mcp', McpEndpointAction::class)
		->add(McpRateLimitMiddleware::class)
		->add(McpCorsMiddleware::class);
	// OPTIONS route lets the CORS middleware short-circuit preflights without
	// Slim returning a 405 for the missing handler. The action is never
	// dispatched on OPTIONS because the middleware returns 204 before
	// handing off to the inner stack.
	$app->options('/mcp', McpEndpointAction::class)
		->add(McpCorsMiddleware::class);

	// AI-agent discovery. Always 200; carries `disabled: true` when the endpoint
	// is unavailable so agents can show informative UX instead of guessing.
	$app->get('/.well-known/mcp.json', McpDiscoveryAction::class)
		->add(McpCorsMiddleware::class)
		->setName('mcp-discovery');
	$app->options('/.well-known/mcp.json', McpDiscoveryAction::class)
		->add(McpCorsMiddleware::class);
};
