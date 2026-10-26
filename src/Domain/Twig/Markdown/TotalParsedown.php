<?php

namespace TotalCMS\Domain\Twig\Markdown;

/**
 * ParsedownExtra with two title shortcuts: captioned figures and new-tab links.
 *
 * An image alone in its paragraph that carries a title becomes
 * <figure><img><figcaption>{title}</figcaption></figure>. Inline images and
 * images without a title render as plain Parsedown output.
 *
 * A title of "caption" or "alt" (any case) means "use the alt text as the
 * caption". The img then drops its alt attribute: an img inside a figure with
 * a non-empty figcaption may omit alt, and a screen reader reads the sentence
 * once instead of twice.
 *
 * A link titled "external" or "_blank" (any case) opens in a new tab: it gets
 * target="_blank" and no title attribute.
 */
class TotalParsedown extends \ParsedownExtra
{
	private const ALT_AS_CAPTION = ['caption', 'alt'];
	private const NEW_TAB        = ['external', '_blank'];

	// Images are parsed through inlineLink(); this keeps the new-tab rule off them
	private bool $inImage = false;

	/**
	 * @param array<string,mixed> $Excerpt
	 *
	 * @return array<string,mixed>|null
	 */
	protected function inlineImage($Excerpt)
	{
		$this->inImage = true;
		try {
			return parent::inlineImage($Excerpt);
		} finally {
			$this->inImage = false;
		}
	}

	/**
	 * @param array<string,mixed> $Excerpt
	 *
	 * @return array<string,mixed>|null
	 */
	protected function inlineLink($Excerpt)
	{
		$Link  = parent::inlineLink($Excerpt);
		$title = $Link['element']['attributes']['title'] ?? null;

		if ($this->inImage || !is_string($title) || !in_array(strtolower(trim($title)), self::NEW_TAB, true)) {
			return $Link;
		}

		unset($Link['element']['attributes']['title']);
		$Link['element']['attributes']['target'] = '_blank';

		return $Link;
	}

	/**
	 * @param array<string,mixed> $Element
	 *
	 * @return array<string,mixed>
	 */
	protected function handle(array $Element)
	{
		$Element = parent::handle($Element);

		if (($Element['name'] ?? null) !== 'p' || !is_array($Element['elements'] ?? null)) {
			return $Element;
		}

		$image = $this->soleImage($Element['elements']);
		if ($image === null) {
			return $Element;
		}

		return $this->figure($image) ?? $Element;
	}

	/**
	 * The paragraph's only image when everything around it is whitespace and it
	 * has a title, null otherwise.
	 *
	 * @param array<int|string,mixed> $elements
	 *
	 * @return array<string,mixed>|null
	 */
	private function soleImage(array $elements): ?array
	{
		$image = null;
		foreach ($elements as $element) {
			if (!is_array($element)) {
				return null;
			}
			if (($element['name'] ?? null) === 'img') {
				if ($image !== null) {
					return null;
				}
				$image = $element;

				continue;
			}
			if (!$this->isBlank($element)) {
				return null;
			}
		}

		$title = $image['attributes']['title'] ?? null;

		return is_string($title) && trim($title) !== '' ? $image : null;
	}

	/** @param array<string,mixed> $element */
	private function isBlank(array $element): bool
	{
		if (isset($element['name']) || isset($element['rawHtml'])) {
			return false;
		}
		if (isset($element['text']) && (!is_string($element['text']) || trim($element['text']) !== '')) {
			return false;
		}
		if (isset($element['elements'])) {
			if (!is_array($element['elements'])) {
				return false;
			}
			foreach ($element['elements'] as $child) {
				if (!is_array($child) || !$this->isBlank($child)) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * The figure for $image, or null when it asks for its alt as the caption
	 * and has no alt to give.
	 *
	 * @param array<string,mixed> $image
	 *
	 * @return array<string,mixed>|null
	 */
	private function figure(array $image): ?array
	{
		$caption = trim((string)$image['attributes']['title']);
		unset($image['attributes']['title']);

		if (in_array(strtolower($caption), self::ALT_AS_CAPTION, true)) {
			$caption = trim((string)($image['attributes']['alt'] ?? ''));
			if ($caption === '') {
				return null;
			}
			unset($image['attributes']['alt']);
		}

		return [
			'name'     => 'figure',
			'elements' => [
				$image,
				['name' => 'figcaption', 'text' => $caption],
			],
		];
	}
}
