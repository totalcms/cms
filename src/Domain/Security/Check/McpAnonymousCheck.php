<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Support\Config;

class McpAnonymousCheck implements SecurityCheck
{
	private const ID   = 'mcp-anonymous';
	private const DOCS = 'operations/security#mcp-anonymous';

	public function __construct(
		private readonly Config $config,
		private readonly CollectionLister $collections,
		private readonly McpSchemaResolver $resolver,
		private readonly SchemaFetcher $schemas,
	) {
	}

	public function makesRequests(): bool
	{
		return false;
	}

	public function id(): string
	{
		return self::ID;
	}

	public function title(): string
	{
		return 'Anonymous MCP access';
	}

	public function run(SecurityContext $context): array
	{
		$mcp = (array)$this->config->mcp;
		if (!($mcp['enabled'] ?? true) || !($mcp['publicAccess'] ?? false)) {
			return [SecurityFinding::ok(self::ID, 'The MCP server is not open to anonymous callers')];
		}

		// Same rule the live server applies: a collection is public when its `mcp.access` resolves to `public`.
		$public    = [];
		$sensitive = [];
		foreach ($this->collections->listAllCollections() as $collection) {
			if ($this->resolver->forCollection($collection)['access'] !== 'public') {
				continue;
			}
			$public[] = $collection->id;
			if ($this->hasSensitiveField($collection)) {
				$sensitive[] = $collection->id;
			}
		}

		if ($public === []) {
			return [SecurityFinding::ok(self::ID, 'No collection is public over MCP')];
		}

		if ($sensitive !== []) {
			return [SecurityFinding::found(
				self::ID,
				Severity::Warning,
				'Anonymous MCP callers can read collections with email or password fields',
				'Public over MCP: ' . implode(', ', $public) . '. These have email or password fields: ' . implode(', ', $sensitive) . '.',
				'Set the collection\'s MCP access to something other than public, or mark those properties as not exposed to MCP.',
				self::DOCS,
			)];
		}

		return [SecurityFinding::found(
			self::ID,
			Severity::Info,
			'Collections are public over MCP',
			'Anonymous AI agents can read: ' . implode(', ', $public) . '.',
			'Make sure these collections hold only content you publish. Set `mcp.publicAccess` to false to close it.',
			self::DOCS,
		)];
	}

	private function hasSensitiveField(CollectionData $collection): bool
	{
		$schema = $this->schemas->fetchSchemaForCollection($collection->id);
		foreach ($schema->properties as $name => $property) {
			// Only fields MCP actually returns count: password fields are hidden by default and `mcp.expose: false` hides any field.
			if (is_array($property)
				&& in_array($property['field'] ?? '', ['password', 'email'], true)
				&& $this->resolver->isPropertyExposed($schema, (string)$name)) {
				return true;
			}
		}

		return false;
	}
}
