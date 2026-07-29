<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Handler;

/**
 * A group of XML-RPC methods. Handlers expose a name → callable map rather than
 * relying on dynamic method calls, so the allowlist is explicit and PHPStan can
 * still see the signatures.
 */
interface MethodHandler
{
	/**
	 * @return array<string,callable(array<int,mixed>,?string):mixed>
	 */
	public function methods(): array;
}
