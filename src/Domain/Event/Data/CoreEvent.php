<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Data;

/**
 * Canonical names of the core events Total CMS dispatches. Reference these
 * consts at `dispatch()` / `listen()` call sites instead of typing the string
 * literal — a misspelled event name silently matches no listener, so the
 * consts turn that whole class of bug into an IDE/static-analysis error.
 *
 * Single source of truth for anything that enumerates events (automation event
 * triggers, the `events` propertyOptions source, docs). Keep in sync with the
 * dispatch() call sites across the domain services.
 */
final class CoreEvent
{
	public const OBJECT_CREATED     = 'object.created';
	public const OBJECT_UPDATED     = 'object.updated';
	public const OBJECT_DELETED     = 'object.deleted';
	public const COLLECTION_CREATED = 'collection.created';
	public const COLLECTION_UPDATED = 'collection.updated';
	public const COLLECTION_DELETED = 'collection.deleted';
	public const SCHEMA_SAVED       = 'schema.saved';
	public const SCHEMA_DELETED     = 'schema.deleted';
	public const TEMPLATE_SAVED     = 'template.saved';
	public const USER_LOGIN         = 'user.login';
	public const USER_LOGOUT        = 'user.logout';
	public const IMPORT_CREATED     = 'import.created';
	public const IMPORT_UPDATED     = 'import.updated';
	public const IMPORT_COMPLETED   = 'import.completed';
	public const BULK_DELETED       = 'bulk.deleted';
	public const EXTENSION_ENABLED  = 'extension.enabled';
	public const EXTENSION_DISABLED = 'extension.disabled';
	public const DEVMODE_ENABLED    = 'devmode.enabled';
	public const DEVMODE_DISABLED   = 'devmode.disabled';
	public const CACHE_CLEARED      = 'cache.cleared';

	/**
	 * Every core event, in a sensible display order.
	 *
	 * @var list<string>
	 */
	public const ALL = [
		self::OBJECT_CREATED,
		self::OBJECT_UPDATED,
		self::OBJECT_DELETED,
		self::COLLECTION_CREATED,
		self::COLLECTION_UPDATED,
		self::COLLECTION_DELETED,
		self::SCHEMA_SAVED,
		self::SCHEMA_DELETED,
		self::TEMPLATE_SAVED,
		self::USER_LOGIN,
		self::USER_LOGOUT,
		self::IMPORT_CREATED,
		self::IMPORT_UPDATED,
		self::IMPORT_COMPLETED,
		self::BULK_DELETED,
		self::EXTENSION_ENABLED,
		self::EXTENSION_DISABLED,
		self::DEVMODE_ENABLED,
		self::DEVMODE_DISABLED,
		self::CACHE_CLEARED,
	];

	/**
	 * `{value, label}` options for the `events` propertyOptions source.
	 *
	 * @return array<int,array{value: string, label: string}>
	 */
	public static function options(): array
	{
		return array_map(static fn (string $event): array => ['value' => $event, 'label' => $event], self::ALL);
	}
}
