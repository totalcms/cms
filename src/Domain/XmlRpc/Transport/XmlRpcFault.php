<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Transport;

/**
 * An XML-RPC fault. Thrown by the transport and by method handlers; the action
 * converts it into a `<fault>` response body with HTTP 200, which is what the
 * XML-RPC spec requires and what clients parse.
 *
 * Codes follow WordPress so client-side error dialogs read naturally:
 * 403 bad credentials, 401 insufficient permissions, 404 missing post or blog,
 * 500 unexpected, -32601 unknown method, -32700 malformed request.
 */
class XmlRpcFault extends \RuntimeException
{
	public function __construct(int $code, string $message)
	{
		parent::__construct($message, $code);
	}

	public static function unknownMethod(string $method): self
	{
		return new self(-32601, sprintf('Server error. Requested method %s does not exist.', $method));
	}

	public static function badCredentials(): self
	{
		return new self(403, 'Bad login/pass combination.');
	}

	public static function forbidden(string $message): self
	{
		return new self(401, $message);
	}

	public static function notFound(string $message): self
	{
		return new self(404, $message);
	}

	public static function malformed(string $message): self
	{
		return new self(-32700, $message);
	}
}
