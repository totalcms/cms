<?php

namespace TotalCMS\Domain\Admin\FormField;

/**
 * Total Form Field Builder.
 */
final class FormField
{
	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.Superglobals)
	 */
	public function __construct()
	{
	}

	public function build(): string
	{
		$attributes = [
			// 'class'           => "totalform {$this->class}",
			// 'data-schema'     => $this->collectionData->schema,
			// 'data-collection' => $this->collection,
			// 'data-method'     => $this->method,
			// 'data-api'        => $this->api,
			// 'data-route'      => $this->route,
		];

		return self::createHTMLElement('form', $this->fieldContent(), $attributes);
	}

	public static function createHTMLElement(string $tag, string $content, array $attributes = []): string
	{
		// Start the element with the opening tag
		$element = "<$tag";

		// Add attributes to the tag
		foreach ($attributes as $attr => $value) {
			if ($value !== false) { // Example condition: add attribute if its value is not false
				$element .= " $attr=\"" . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
			}
		}

		// Close the opening tag and add content
		$element .= ">$content</$tag>";

		return $element;
	}
}
