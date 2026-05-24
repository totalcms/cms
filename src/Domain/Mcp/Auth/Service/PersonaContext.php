<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Service;

use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;

/**
 * Request-scoped store of the resolved MCP persona.
 *
 * The mcp/sdk reflects on each tool handler's signature to map JSON arguments
 * by parameter name, so we can't pass persona via the handler args without
 * either breaking that reflection (variadic params aren't supported) or
 * polluting every tool's input schema with a `_persona` field.
 *
 * Instead McpEndpointAction writes the persona here after auth, and tools
 * inject this service to read it during dispatch. The container treats this
 * as a singleton per request — same lifetime as the Slim app instance.
 *
 * For OAuth Bearer requests McpEndpointAction also stores the resolved scopes
 * via setScopes() so OAuthScopeEvaluator can read them during tool dispatch
 * without needing access to the PSR-7 request directly.
 */
class PersonaContext
{
	private ?McpPersona $persona = null;

	/** @var list<string> */
	private array $scopes = [];

	public function set(McpPersona $persona): void
	{
		$this->persona = $persona;
	}

	public function current(): McpPersona
	{
		if (!$this->persona instanceof McpPersona) {
			throw new \LogicException('Persona has not been resolved for this request.');
		}

		return $this->persona;
	}

	public function isResolved(): bool
	{
		return $this->persona instanceof McpPersona;
	}

	/**
	 * Store the OAuth scopes for this request. Called by McpEndpointAction
	 * after Bearer authentication resolves an AUTHENTICATED persona.
	 *
	 * @param list<string> $scopes
	 */
	public function setScopes(array $scopes): void
	{
		$this->scopes = $scopes;
	}

	/**
	 * Return the OAuth scopes for this request. Empty for non-Bearer requests.
	 *
	 * @return list<string>
	 */
	public function getScopes(): array
	{
		return $this->scopes;
	}
}
