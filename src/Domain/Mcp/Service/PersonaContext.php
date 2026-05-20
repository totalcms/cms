<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Mcp\Data\McpPersona;

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
 */
class PersonaContext
{
	private ?McpPersona $persona = null;

	public function set(McpPersona $persona): void
	{
		$this->persona = $persona;
	}

	public function current(): McpPersona
	{
		if ($this->persona === null) {
			throw new \LogicException('Persona has not been resolved for this request.');
		}

		return $this->persona;
	}

	public function isResolved(): bool
	{
		return $this->persona !== null;
	}
}
