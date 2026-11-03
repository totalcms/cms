<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

/**
 * Decides at send time whether a rendered bulk message still goes to a
 * recipient, so someone who unsubscribed after the send was queued is
 * skipped. Named by class in RenderedBulkMessage::$recipientFilter and
 * resolved from the container; test sends to an override address skip it.
 */
interface BulkRecipientFilter
{
	/** @param array<string,mixed> $object the recipient, as stored now */
	public function accepts(array $object): bool;
}
