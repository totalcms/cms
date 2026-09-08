<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The JSON-RPC envelope of one /mcp request, read once.
 *
 * Slim's BodyParsingMiddleware may have already consumed the body stream:
 * getParsedBody() is used when it populated one (Content-Type:
 * application/json), otherwise the raw stream is rewound and decoded. The
 * stream is always rewound afterwards so the SDK's StreamableHttpTransport
 * can call getContents() from position 0. This replaces three identical
 * inline peeks the endpoint action used to make (scope gate, initialize
 * client info, subscriptions/listen admission).
 */
final readonly class McpRequestBody
{
	/** @param array<mixed> $rpc */
	private function __construct(
		private string $httpMethod,
		private array $rpc,
	) {
	}

	public static function from(ServerRequestInterface $request): self
	{
		$parsed = $request->getParsedBody();
		if (is_array($parsed)) {
			$rpc = $parsed;
		} else {
			$request->getBody()->rewind();
			$decoded = json_decode($request->getBody()->getContents(), true);
			$rpc     = is_array($decoded) ? $decoded : [];
		}
		// Always rewind so the SDK's StreamableHttpTransport can call getContents()
		// from position 0.
		$request->getBody()->rewind();

		return new self($request->getMethod(), $rpc);
	}

	/** The JSON-RPC method, or '' when the body carried none. */
	public function method(): string
	{
		$method = $this->rpc['method'] ?? null;

		return is_string($method) ? $method : '';
	}

	/**
	 * The scope-gate operation: for tools/call the tool name is appended so
	 * per-tool scopes can gate individual invocations (foundation for future
	 * fine-grained scopes; in v1 the mcp:tools scope covers all tool
	 * invocations via prefix match on "tools/call").
	 */
	public function operation(): string
	{
		$method = $this->method();
		$name   = $this->rpc['params']['name'] ?? null;
		if ($method === 'tools/call' && is_string($name)) {
			return 'tools/call:' . $name;
		}

		return $method;
	}

	/**
	 * Protocol lifecycle messages are exempt from the scope gate: ping
	 * keep-alives and the notifications the spec obliges clients to send
	 * (initialized, cancelled, progress) are plumbing, not capability access.
	 */
	public function isLifecycle(): bool
	{
		$method = $this->method();

		return $method === 'ping' || str_starts_with($method, 'notifications/');
	}

	public function isInitialize(): bool
	{
		return $this->method() === 'initialize';
	}

	/**
	 * The client's self-reported identity from the initialize handshake;
	 * empty strings when absent.
	 *
	 * @return array{name:string,version:string}
	 */
	public function clientInfo(): array
	{
		$params = is_array($this->rpc['params'] ?? null) ? $this->rpc['params'] : [];
		$client = is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : [];

		return [
			'name'    => is_string($client['name'] ?? null) ? $client['name'] : '',
			'version' => is_string($client['version'] ?? null) ? $client['version'] : '',
		];
	}

	/** Is this a modern-era `subscriptions/listen` call? (POST only.) */
	public function isListen(): bool
	{
		return $this->httpMethod === 'POST' && $this->method() === 'subscriptions/listen';
	}
}
