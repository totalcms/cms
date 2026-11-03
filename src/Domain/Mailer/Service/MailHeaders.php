<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * The extra headers a caller adds to one message through the `headers` key
 * of EmailSender::send(): `List-Unsubscribe` on bulk mail, a campaign id, a
 * feedback id.
 *
 * A header is dropped when its name is not an RFC 5322 field name, its value
 * is not a string or holds a line break (header injection), or it is one the
 * mailer writes itself from the other keys (From, To, Bcc, Subject, the MIME
 * headers). Those go through `from`, `to`, `bcc`, `subject` and the bodies.
 */
final class MailHeaders
{
	/** Lowercased names PHPMailer writes itself. */
	private const RESERVED = [
		'from', 'sender', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'return-path',
		'mime-version', 'content-type', 'content-transfer-encoding', 'content-disposition',
	];

	/**
	 * Add the valid headers in $headers to $mail.
	 *
	 * @return list<string> the names that were dropped
	 */
	public static function apply(PHPMailer $mail, mixed $headers): array
	{
		if (!is_array($headers)) {
			return [];
		}

		$skipped = [];
		foreach ($headers as $name => $value) {
			$name = (string)$name;
			if (!self::valid($name, $value)) {
				$skipped[] = $name;
				continue;
			}

			/** @var string $value */
			$mail->addCustomHeader($name, $value);
		}

		return $skipped;
	}

	/**
	 * $base with $overrides laid over it. Header names are compared without
	 * regard to case, so a caller's `list-unsubscribe` replaces Bulk Send's
	 * `List-Unsubscribe` instead of sending both.
	 *
	 * @param array<string,string> $base
	 * @param array<string,string> $overrides
	 *
	 * @return array<string,string>
	 */
	public static function merge(array $base, array $overrides): array
	{
		$replaced = array_change_key_case($overrides, CASE_LOWER);

		return array_filter($base, static fn (string $name): bool => !isset($replaced[strtolower($name)]), ARRAY_FILTER_USE_KEY) + $overrides;
	}

	private static function valid(string $name, mixed $value): bool
	{
		// Printable ASCII except space and colon (RFC 5322 field-name).
		if (preg_match('/^[\x21-\x39\x3B-\x7E]+$/', $name) !== 1 || in_array(strtolower($name), self::RESERVED, true)) {
			return false;
		}

		return is_string($value) && preg_match('/[\r\n\0]/', $value) !== 1;
	}
}
