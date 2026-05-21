<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\Mcp\McpDiscoveryAction;
use TotalCMS\Action\Mcp\McpEndpointAction;
use TotalCMS\Middleware\Security\McpRateLimitMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// MCP (Model Context Protocol) endpoint. POST carries JSON-RPC; GET handles the
	// SSE upgrade for streaming responses. The mcp/sdk StreamableHttpTransport
	// dispatches on method + Accept header, so a single action services both verbs.
	//
	// McpRateLimitMiddleware throttles anonymous callers (no API key) at
	// mcp.publicIpPerMinute requests per IP per 60s. API-key-bearing requests
	// bypass; admin keys are gated by credential possession already.
	$app->post('/mcp', McpEndpointAction::class)
		->add(McpRateLimitMiddleware::class)
		->setName('mcp');
	$app->get('/mcp', McpEndpointAction::class)
		->add(McpRateLimitMiddleware::class);

	// AI-agent discovery. Always 200; carries `disabled: true` when the endpoint
	// is unavailable so agents can show informative UX instead of guessing.
	$app->get('/.well-known/mcp.json', McpDiscoveryAction::class)->setName('mcp-discovery');
};
