<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Webhook;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The body of a request as the exact bytes that were sent.
 *
 * A webhook's signature is over those bytes. The parsed body is no use for
 * checking one: decoding JSON and encoding it again changes spacing, key
 * order and escapes. And something earlier in the request may already have
 * read the stream to its end.
 */
final class RawBody
{
	public static function of(ServerRequestInterface $request): string
	{
		$body = $request->getBody();
		if ($body->isSeekable()) {
			$body->rewind();
		}

		return $body->getContents();
	}
}
