<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Data;

/**
 * The acting user of an access-checked request, resolved once by
 * BaseAccessMiddleware (from the token's `sub` claim for a bearer, from the
 * session otherwise) and carried as the `accessActor` request attribute.
 *
 * `collection` is the raw value: `''` for a session user who logged in at the
 * default `/admin/login` route. {@see self::authCollection()} applies the
 * codebase-wide rule that an empty value means the default auth collection.
 */
final readonly class AccessActor
{
	public function __construct(
		public string $userId,
		public string $collection,
	) {
	}

	public function authCollection(string $default): string
	{
		return $this->collection !== '' ? $this->collection : $default;
	}
}
