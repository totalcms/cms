<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\WebMcp;

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Builds a form through TotalFormFactory with the declarative WebMCP
 * attributes on the <form> tag and on every control.
 *
 * Options beyond the four below pass straight through to
 * `cms.form.builder()`:
 *
 * - `name`        the tool name (default `create_{collection}`, or
 *                 `update_{collection}` when an `id` is given)
 * - `description` what the tool does (default from the collection's own
 *                 name and singular label)
 * - `autosubmit`  let the agent submit without the visitor confirming.
 *                 Off by default, and never on a form that changes data
 *                 the operator cares about
 * - `params`      map of property → description, overriding the schema's
 *                 help text for that control. A `{title, description}` pair
 *                 overrides the label as well
 *
 * A registration form (`register: true`) is refused outright: public
 * registration auto-logs the new user in, and an agent-callable,
 * session-minting form is the worst combination the spec's security section
 * describes.
 */
final readonly class WebMcpFormBuilder
{
	public const EXTENSION_ID = 'totalcms/webmcp';

	private const OWN_OPTIONS = ['name', 'description', 'autosubmit', 'params'];

	public function __construct(
		private TotalFormFactory $forms,
		private SchemaFetcher $schemas,
		private CollectionFetcher $collections,
		private ExtensionSettingsManager $settings,
	) {
	}

	/** @param array<string,mixed> $options */
	public function build(string $collection, array $options = []): string
	{
		if (($options['register'] ?? false) === true) {
			throw new \DomainException('webmcp_form() refuses a registration form: an agent-callable form that creates and signs in a user is not something to expose.');
		}

		$formOptions = array_diff_key($options, array_flip(self::OWN_OPTIONS));

		if ($this->settings->getSetting(self::EXTENSION_ID, 'declarativeForms', true) !== true) {
			return $this->forms->builder($collection, $formOptions)->autoBuild();
		}

		$collectionData = $this->collections->fetchCollection($collection);
		if (!$collectionData instanceof CollectionData) {
			throw new \DomainException("webmcp_form(): unknown collection '{$collection}'.");
		}

		$editing = trim((string)($options['id'] ?? '')) !== '';

		$attributes = [
			WebMcpAttributes::FORM_NAME        => WebMcpAttributes::toolName((string)($options['name'] ?? ($editing ? "update_{$collection}" : "create_{$collection}"))),
			WebMcpAttributes::FORM_DESCRIPTION => WebMcpAttributes::description((string)($options['description'] ?? $this->defaultDescription($collectionData, $editing))),
		];
		if (($options['autosubmit'] ?? false) === true) {
			$attributes[WebMcpAttributes::FORM_AUTOSUBMIT] = '';
		}

		$formOptions['attributes'] = array_merge($formOptions['attributes'] ?? [], $attributes);

		$overrides = $options['params'] ?? [];
		if (!is_array($overrides)) {
			$overrides = [];
		}

		$fieldOptions = [];
		foreach ($this->schemas->fetchSchemaForCollection($collection)->properties as $property => $schema) {
			$schema   = is_array($schema) ? $schema : [];
			$override = $overrides[$property] ?? null;

			// The label names the parameter, the help text explains it. A
			// string override replaces the description; a map can replace
			// either half.
			$title       = WebMcpAttributes::paramTitle($schema);
			$description = WebMcpAttributes::paramDescription($schema);

			if (is_string($override)) {
				$description = WebMcpAttributes::description($override);
			} elseif (is_array($override)) {
				if (is_string($override['title'] ?? null)) {
					$title = WebMcpAttributes::description($override['title']);
				}
				if (is_string($override['description'] ?? null)) {
					$description = WebMcpAttributes::description($override['description']);
				}
			}

			$params = array_filter([
				WebMcpAttributes::PARAM_TITLE       => $title,
				WebMcpAttributes::PARAM_DESCRIPTION => $description,
			], static fn (string $value): bool => $value !== '');

			if ($params === []) {
				continue;
			}
			$fieldOptions[$property] = ['settings' => ['attributes' => $params]];
		}

		return $this->forms->builder($collection, $formOptions)->autoBuild('', $fieldOptions);
	}

	private function defaultDescription(CollectionData $collection, bool $editing): string
	{
		$record = $collection->toArray();
		$label  = (string)($record['labelSingular'] ?? 'record');
		$name   = $collection->name !== '' ? $collection->name : $collection->id;

		return $editing
			? "Update a {$label} in the {$name} collection"
			: "Create a new {$label} in the {$name} collection";
	}
}
