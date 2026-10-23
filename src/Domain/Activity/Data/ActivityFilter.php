<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Activity\Data;

/**
 * The filters the Activity page sends. Every field is optional; '' means
 * "any". `user` matches the user id, API key id or OAuth client id, so one
 * box finds a member, a key, or an AI client. Dates are `Y-m-d`, inclusive.
 */
final readonly class ActivityFilter
{
	public const PAGE_SIZE = 50;

	public function __construct(
		public string $event = '',
		public string $actorKind = '',
		public string $user = '',
		public string $collection = '',
		public string $level = '',
		public string $from = '',
		public string $to = '',
		public int $page = 1,
	) {
	}

	/**
	 * @param array<string,mixed> $query
	 */
	public static function fromQuery(array $query): self
	{
		$str = static fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';

		return new self(
			event: $str('event'),
			actorKind: $str('actor'),
			user: $str('user'),
			collection: $str('collection'),
			level: $str('level'),
			from: self::date($str('from')),
			to: self::date($str('to')),
			page: max(1, (int)$str('page')),
		);
	}

	public function offset(): int
	{
		return ($this->page - 1) * self::PAGE_SIZE;
	}

	/**
	 * The filters as query parameters, without the page — for pagination links.
	 *
	 * @return array<string,string>
	 */
	public function toQuery(): array
	{
		return array_filter([
			'event'      => $this->event,
			'actor'      => $this->actorKind,
			'user'       => $this->user,
			'collection' => $this->collection,
			'level'      => $this->level,
			'from'       => $this->from,
			'to'         => $this->to,
		], static fn (string $value): bool => $value !== '');
	}

	private static function date(string $value): string
	{
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
	}
}
