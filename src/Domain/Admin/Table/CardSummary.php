<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Table;

use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Which one of a card's fields stands for the card in the collection table.
 *
 * A card cell used to join every scalar sub-field, so an SEO card read as
 * "About Us, Who we are, 1, 1". The table now leads with the first text
 * field in the card's schema order — a title, a name, a street — and the
 * cell template falls back to the old join, trimmed, when a card has none.
 */
readonly class CardSummary
{
	/** Fields whose string value is not a one-line summary of anything. */
	private const SKIPPED_FIELDS = ['code', 'svg', 'password', 'json', 'styledtext', 'markdown', 'styledmarkdown', 'hidden'];

	public function __construct(private SchemaFetcher $schemaFetcher)
	{
	}

	/**
	 * The name of the card's first text-like property, or null when it has
	 * none (or its schema cannot be read).
	 *
	 * @param array<string,mixed> $cardProperty the card's property definition
	 */
	public function field(array $cardProperty): ?string
	{
		$schemaref = PropertyDefinition::extractSchemaRef($cardProperty);
		if ($schemaref === null) {
			return null;
		}

		try {
			$schema = $this->schemaFetcher->fetchSchema(SchemaFetcher::extractSchemaId($schemaref));
		} catch (\Throwable) {
			return null;
		}

		foreach ($schema->properties as $name => $property) {
			if ($name === 'id' || !is_array($property)) {
				continue;
			}
			$definition = PropertyDefinition::fromArray($property);
			if (($property['hide'] ?? false) === true || in_array($definition->field, self::SKIPPED_FIELDS, true)) {
				continue;
			}
			if (in_array($definition->resolveType(), ['string', 'slug', 'email', 'url', 'phone'], true)) {
				return (string)$name;
			}
		}

		return null;
	}
}
