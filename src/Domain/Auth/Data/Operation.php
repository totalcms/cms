<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Data;

/**
 * What an actor wants to do to a collection, in domain terms. Each entry
 * point maps its own vocabulary (REST route names, MCP tool names) onto
 * these, so the rules about what can and cannot be limited to the actor's
 * own objects live in one place: {@see isUnscopable()}.
 */
enum Operation: string
{
	case Read   = 'read';
	case List   = 'list';
	case Create = 'create';
	case Update = 'update';
	case Delete = 'delete';

	/** Delete many objects in one call. */
	case BulkDelete = 'bulk-delete';

	/** Copy an existing object, with every value it holds. */
	case Clone = 'clone';

	/** Upsert whole objects from a file or factory. */
	case Import = 'import';

	/** Read across every object: export, report, sitemap, feeds. */
	case Export = 'export';

	/** Rebuild the collection's index. */
	case Reindex = 'reindex';

	/**
	 * Operations that act across owners, or copy values the actor could not
	 * write, so an actor scoped to their own objects is refused them.
	 */
	public function isUnscopable(): bool
	{
		return match ($this) {
			self::BulkDelete, self::Clone, self::Import, self::Export, self::Reindex => true,
			default                                                                  => false,
		};
	}

	/**
	 * The access-group operation (read, create, update, delete) that grants it.
	 */
	public function groupOperation(): string
	{
		return match ($this) {
			self::Read, self::List, self::Export     => 'read',
			self::Create, self::Clone, self::Import  => 'create',
			self::Update, self::Reindex              => 'update',
			self::Delete, self::BulkDelete           => 'delete',
		};
	}
}
