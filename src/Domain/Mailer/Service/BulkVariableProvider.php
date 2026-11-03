<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

/**
 * Supplies per-recipient Twig variables and mail headers for a rendered
 * bulk message: an unsubscribe link of the caller's own, say. Named by
 * class in RenderedBulkMessage::$variables and resolved from the container.
 *
 * Its variables override Bulk Send's (`unsubscribeUrl`) and may replace
 * `contact`. Its headers override Bulk Send's of the same name, compared
 * without regard to case.
 */
interface BulkVariableProvider
{
	/**
	 * @param array<string,mixed> $object the recipient
	 *
	 * @return array{vars: array<string,mixed>, headers: array<string,string>}
	 */
	public function variables(array $object): array;
}
