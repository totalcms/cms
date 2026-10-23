<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for `access.denied`: one refusal by the access middleware or by
 * the storage-level write gate. `resource` is what was asked for
 * (`collection`, `schema`, `object`…), `reason` the check that refused
 * (`group`, `owner`, `protected`…, or `''` when the check gives none).
 * `route`, `method` and `path` are empty for a refusal below the HTTP layer.
 *
 * Who was refused is the `actor` every payload carries.
 */
readonly class AccessDeniedPayload extends EventPayload
{
	public function __construct(
		public string $resource,
		public string $operation,
		public string $reason = '',
		public string $collection = '',
		public string $id = '',
		public string $route = '',
		public string $method = '',
		public string $path = '',
	) {
	}

	public function toArray(): array
	{
		return [
			'resource'   => $this->resource,
			'operation'  => $this->operation,
			'reason'     => $this->reason,
			'collection' => $this->collection,
			'id'         => $this->id,
			'route'      => $this->route,
			'method'     => $this->method,
			'path'       => $this->path,
		];
	}
}
