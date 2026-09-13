<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;

/**
 * The `instructions` text the MCP server sends in its initialize response.
 *
 * Every client of every install receives this on connect and keeps it in
 * context for the conversation, so it is the one place the judgment that
 * the terminal skill carries — how to model a schema, how to write for the
 * site, patch rather than replace — reaches people who only ever meet Total
 * CMS through an MCP client and will never install a skill. It has to stay
 * short: it is prepended to every conversation. Depth lives in the docs
 * tools it points at.
 *
 * Persona-aware so a read-only connection is not told how to write.
 */
final class McpInstructions
{
	public static function for(McpPersona $persona): string
	{
		$parts = [self::orientation(), self::reading(), self::lookup()];

		$parts[] = match ($persona) {
			McpPersona::PUBLIC_ => 'This connection can only read. Writing and the admin tools (schemas, collections, cache) need an API key or an OAuth token; tell the user so rather than trying.',
			McpPersona::AUTHENTICATED => 'This connection writes within the approving user\'s scopes and access groups; a refused write means the user is not allowed it, not that the tool is broken. ' . self::writing(),
			McpPersona::ADMIN => self::writing() . ' ' . self::modelling(),
		};

		return implode(' ', $parts);
	}

	private static function orientation(): string
	{
		return 'Total CMS site exposed over the Model Context Protocol. Content is collections of objects shaped by schemas, stored as files; there is no database. '
			. 'Vocabulary: collection (not table), object (not row), schema (not model), Site Builder page (not template).';
	}

	private static function reading(): string
	{
		return 'Discover before you act: list_collections returns every collection with its filterable fields; describe_collection and get_schema give the shape and each property\'s help text. '
			. 'Read with query_collection, get_object and search_collection, or the resources tcms://{collection}/ and tcms://{collection}/{id}. '
			. 'Drafts are hidden from anonymous callers. search and fetch exist for ChatGPT and deep-research clients. Site Builder templates are readable, never writable, over MCP.';
	}

	private static function lookup(): string
	{
		return 'Look things up instead of guessing — this install serves its own documentation, matched to its version: docs_search then docs_get for prose, docs_lookup(kind, name) for exact Twig signatures, field types, schema options and CLI commands. '
			. 'The tcms_* prompts walk the common jobs: research a question, model a collection, write content, build a page, write Twig, audit SEO, troubleshoot MCP.';
	}

	private static function writing(): string
	{
		return 'Writing: read the object and the schema\'s help text first — help is the brief for each field. '
			. 'Edit with patch_object and send only the fields you change; update_object replaces the whole object and drops whatever you omit. '
			. 'Respect shapes: a list is an array, a date is ISO 8601, styledtext is HTML, a select takes one of its options, a deck is keyed by item id. '
			. 'Never invent ids — use the schema\'s autogen rule or ask. Never write password or secret fields. Confirm before creating or deleting.';
	}

	private static function modelling(): string
	{
		return 'Modelling (create_schema / update_schema): match the field to the shape of the value — text is the last resort; omit type and let the field decide, or use a property reference, and use integer for whole numbers. '
			. 'Every property needs help text and the schema a description: agents read them. '
			. 'Anything that renders as a page needs the seo card, seo in the index and a URL on its collection; add created and updated datetime fields when dates matter. '
			. 'Start from the reserved totalcms schema, which demonstrates every field type.';
	}
}
