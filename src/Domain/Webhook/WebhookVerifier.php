<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Webhook;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Decides whether an inbound webhook really came from who it claims to.
 *
 * Each sender has its own way: a signature header over the raw body, a
 * timestamp to stop replays, a certificate. An implementation reads the body
 * with {@see RawBody} (the signature is over the exact bytes) and compares
 * with {@see HmacSignature} or the sender's own scheme.
 *
 * It answers false for anything it cannot verify, and never throws.
 */
interface WebhookVerifier
{
	public function verify(ServerRequestInterface $request): bool;
}
