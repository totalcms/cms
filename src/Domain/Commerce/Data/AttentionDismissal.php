<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Data;

use TotalCMS\Domain\Auth\Data\Actor;

/**
 * The mark staff leave on an order or a subscription they have dealt with:
 * why it had needed someone, who said it was handled, and when.
 *
 * It is kept among the record's flags. Marking a record as handled takes it
 * off the count of those that need someone; it changes nothing else about
 * it. A record flagged again later for any reason is counted again.
 */
final readonly class AttentionDismissal
{
	public const FLAG = 'attentionDismissed';

	/**
	 * The mark among a record's flags, or [] when it has none.
	 *
	 * @param array<mixed> $flags
	 *
	 * @return array{reason: string, by: string, at: string}|array{}
	 */
	public static function from(array $flags): array
	{
		$kept = $flags[self::FLAG] ?? null;
		if (!is_array($kept) || !is_string($kept['reason'] ?? null) || $kept['reason'] === '') {
			return [];
		}

		return [
			'reason' => $kept['reason'],
			'by'     => is_string($kept['by'] ?? null) ? $kept['by'] : '',
			'at'     => is_string($kept['at'] ?? null) ? $kept['at'] : '',
		];
	}

	/**
	 * The flags with the mark made now.
	 *
	 * @param array<mixed> $flags
	 * @param string       $by    who handled it, as {@see actor()} names them
	 *
	 * @return array<mixed>
	 */
	public static function onto(array $flags, string $reason, string $by): array
	{
		return [self::FLAG => ['reason' => $reason, 'by' => $by, 'at' => date(DATE_ATOM)]] + $flags;
	}

	/** How the store's records name whoever did something: `user:{collection}/{id}`, or the kind of actor. */
	public static function actor(Actor $by): string
	{
		return $by->userId !== '' ? "user:{$by->authCollection}/{$by->userId}" : $by->kind->value;
	}
}
