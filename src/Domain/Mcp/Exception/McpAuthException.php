<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Exception;

/**
 * Thrown by McpAuth when an API key is supplied but invalid.
 *
 * The endpoint action converts this to a 401 JSON response. Absent credentials
 * (no API key header at all) are NOT an exception — they resolve to the public
 * persona.
 */
class McpAuthException extends \RuntimeException
{
}
