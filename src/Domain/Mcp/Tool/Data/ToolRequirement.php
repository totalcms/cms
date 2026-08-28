<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Data;

use TotalCMS\Domain\Auth\Data\UserAuthority;

/**
 * Declares the access-group permission an MCP tool needs, in the same
 * domain/operation vocabulary UserAuthority already speaks.
 *
 * Attached to McpToolDefinition::$requires. Task 6 (this class) only wires
 * requirement metadata into tools/list visibility filtering — a UX concern,
 * hiding tools an AUTHENTICATED caller has no chance of using. Task 7 adds
 * the call-time guard that actually enforces the requirement against the
 * tool's real argument value; Task 8 declares $requires on the shipped
 * tools. Until Task 8 lands, no tool sets $requires, so none of this changes
 * runtime behavior yet.
 */
final readonly class ToolRequirement
{
	/**
	 * @param string      $domain        'objects' | 'schemas' | 'collections-meta' | 'cache' | 'site' | 'builder'
	 * @param string      $operation     'create' | 'read' | 'update' | 'delete'
	 * @param string|null $collectionArg Name of the tool's input argument that carries the target
	 *                                   collection/schema id. Unused by Task 6; read by Task 7's
	 *                                   call-time guard to resolve $target from the actual call args.
	 */
	public function __construct(
		public string $domain,
		public string $operation,
		public ?string $collectionArg = null,
	) {
	}

	/**
	 * OAuth scope class this requirement demands. Mirrors the REST Bearer
	 * scope mapping: read-only content access is 'cms:read', content writes
	 * are 'cms:write', and every structural/admin-adjacent domain (schemas,
	 * collections-meta, cache, site) is 'cms:admin'.
	 */
	public function requiredScope(): string
	{
		if ($this->domain === 'objects') {
			return $this->operation === 'read' ? 'cms:read' : 'cms:write';
		}

		return 'cms:admin';
	}

	/**
	 * Call-time check: does $a grant $this->operation on the specific $target
	 * (a collection id, schema id, or util name depending on domain)?
	 *
	 * This is the real enforcement primitive — Task 7 wires it into the
	 * tool-dispatch guard once $target is resolved from the call arguments.
	 */
	public function isSatisfiedFor(UserAuthority $a, string $target): bool
	{
		return match ($this->domain) {
			'objects'          => $a->canCollection($this->operation, $target),
			'collections-meta' => $a->canCollectionMeta($this->operation, $target),
			'schemas'          => $a->canSchema($this->operation, $target),
			'cache'            => $a->canUtil('cache'),
			'site'             => $a->isAdmin,
			// Builder templates are a single page-level grant, not a
			// per-target one — there is no id to check, so $target is
			// ignored and the boolean permission is the whole answer.
			'builder'          => $a->canBuilder(),
			default            => false,
		};
	}

	/**
	 * Visibility check: does $a grant this requirement's operation on AT
	 * LEAST one target? Used by McpToolDefinition::isVisibleTo() to decide
	 * whether an AUTHENTICATED caller should see the tool in tools/list at
	 * all — no specific target (collection/schema id) is known yet at
	 * listing time, only the operation. This is deliberately UX-level
	 * filtering, not enforcement — the call-time guard added in Task 7 is
	 * what actually blocks a call against a collection/schema the caller
	 * can't touch, using isSatisfiedFor() with the real target.
	 *
	 * Rule chosen (documented here since the domain spec left this
	 * underspecified): reuse UserAuthority's existing "bulk" permission
	 * checks — canCollectionsOperation() / canCollectionsMetaOperation() /
	 * canSchemasOperation() — which exist for exactly this "no specific
	 * target" shape (they already back routes like `GET /collections`).
	 * Each answers "does ANY group grant this operation on ANY target in
	 * this domain", which is precisely "is this operation ever possible for
	 * this caller" — the right question for whether to show the tool at
	 * all. This is why, unlike isSatisfiedFor(), a viewer-shaped authority
	 * (read-only, unrestricted) is correctly invisible to an 'update'
	 * requirement: canCollectionsOperation('update') is false because no
	 * group's collections.operations includes 'update', independent of how
	 * broad their 'read' access is.
	 *
	 * 'cache' has no bulk/target concept (utils are page-based, not
	 * per-collection) so it reuses the same canUtil('cache') check as
	 * isSatisfiedFor(). 'site' has no non-admin grant at all — isAdmin is
	 * already handled above, so it's simply false here.
	 */
	public function isSatisfiedForAny(UserAuthority $a): bool
	{
		if ($a->isAdmin) {
			return true;
		}

		return match ($this->domain) {
			'objects'          => $a->canCollectionsOperation($this->operation),
			'collections-meta' => $a->canCollectionsMetaOperation($this->operation),
			'schemas'          => $a->canSchemasOperation($this->operation),
			'cache'            => $a->canUtil('cache'),
			'site'             => false,
			// Same boolean grant as isSatisfiedFor() — 'builder' has no
			// per-target concept, so "possible for any target" and "possible
			// for this target" are the same question.
			'builder'          => $a->canBuilder(),
			default            => false,
		};
	}
}
