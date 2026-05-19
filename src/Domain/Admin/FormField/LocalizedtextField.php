<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Admin form field for the `localizedtext` property type.
 *
 * Renders a tab strip with one tab per configured locale; clicking a tab
 * shows the matching `<input type="text">`. The `defaultLocale` tab is active
 * on first render. RTL locales get `dir="rtl"` on both the tab label and the
 * input so caret/text alignment render correctly.
 *
 * Stored value is `array<string,string>` keyed by locale code.
 * Pro edition only — gated at the schema-builder layer.
 */
class LocalizedtextField extends FormField
{
	protected string $defaultFieldType = 'localizedtext';
	protected string $defaultInputType = 'text';

	/**
	 * Skip the default form-group wrapper that FormField::build() adds around
	 * the whole buildFormField() output. Each locale-pane emits its own
	 * form-group + form-group-icon (inside `buildPane()`) so the icon sits
	 * next to the input the user is actively editing instead of stranded at
	 * the bottom of the tab stack. The field-level label and help text are
	 * still produced by `createFormField()`.
	 */
	public function build(): string
	{
		return $this->createFormField($this->buildFormField());
	}

	public function buildFormField(): string
	{
		$locales = $this->form->getLocales();
		if ($locales === []) {
			return HTMLUtils::element(
				'div',
				'Localized field types require `locales` to be configured in tcms.php.',
				['class' => 'localized-field-error']
			);
		}

		$values     = $this->coerceToDict($this->value);
		$activeCode = $this->resolveActiveLocale($locales);

		// Property-name carrier. `TotalField` base class reads the first
		// input/textarea inside the container and uses its `name` attribute
		// to populate `this.property` — which becomes the key in the form
		// payload's outer object. Per-locale inputs intentionally don't
		// carry the property name (they identify their locale via
		// `data-locale`), so without this hidden input every localized
		// field would serialize under an empty key and clobber the next.
		$nameCarrier = HTMLUtils::inlineElement('input', [
			'type' => 'hidden',
			'name' => $this->name,
		]);

		$tabs  = '';
		$panes = '';

		foreach ($locales as $locale) {
			$code     = $locale['code'] ?? '';
			$isActive = $code === $activeCode;
			$tabs    .= $this->buildTab($locale, $isActive);
			$panes   .= $this->buildPane($locale, $values[$code] ?? '', $isActive);
		}

		$tabsRow = HTMLUtils::element('div', $tabs, ['class' => 'locale-tabs', 'role' => 'tablist']);

		return HTMLUtils::element('div', $nameCarrier . $tabsRow . $panes, ['class' => 'localized-stack']);
	}

	/**
	 * Pick the configured locale whose code matches the site's defaultLocale;
	 * fall back to the first configured locale.
	 *
	 * @param array<int,array<string,string>> $locales
	 */
	protected function resolveActiveLocale(array $locales): string
	{
		$default = $this->form->getDefaultLocale();
		if ($default !== '') {
			foreach ($locales as $locale) {
				if (($locale['code'] ?? '') === $default) {
					return $default;
				}
			}
		}

		return (string)($locales[0]['code'] ?? '');
	}

	/**
	 * Build a single tab button.
	 *
	 * @param array<string,string> $locale
	 */
	protected function buildTab(array $locale, bool $isActive): string
	{
		$code  = $locale['code']  ?? '';
		$label = $locale['label'] ?? $code;
		$dir   = $locale['dir']   ?? 'ltr';
		$slug  = $this->slugifyLocale($code);

		$attributes = [
			'type'             => 'button',
			'id'               => "tab-{$this->uuid}-{$slug}",
			'class'            => 'locale-tab' . ($isActive ? ' active' : ''),
			'role'             => 'tab',
			'data-locale-tab'  => $code,
			'dir'              => $dir,
			'aria-selected'    => $isActive ? 'true' : 'false',
			'aria-controls'    => "pane-{$this->uuid}-{$slug}",
			'tabindex'         => $isActive ? '0' : '-1',
		];

		return HTMLUtils::element('button', $label, $attributes);
	}

	/**
	 * Build the tabpanel that hosts the per-locale input. The pane is
	 * `hidden` unless its tab is active; the JS field class toggles `hidden`
	 * on tab clicks.
	 *
	 * @param array<string,string> $locale
	 */
	protected function buildPane(array $locale, string $value, bool $isActive): string
	{
		$code  = $locale['code'] ?? '';
		$dir   = $locale['dir']  ?? 'ltr';
		$slug  = $this->slugifyLocale($code);
		$id    = "field-{$this->uuid}-{$slug}";

		$input = $this->buildLocaleInput($code, $dir, $id, $value);
		$icon  = $this->icon ? HTMLUtils::element('div', '', ['class' => 'form-group-icon']) : '';
		$group = HTMLUtils::element('div', $input . $icon, ['class' => 'form-group']);

		$paneAttrs = [
			'class'            => 'locale-pane',
			'id'               => "pane-{$this->uuid}-{$slug}",
			'role'             => 'tabpanel',
			'data-locale-pane' => $code,
			'aria-labelledby'  => "tab-{$this->uuid}-{$slug}",
		];
		if (!$isActive) {
			$paneAttrs['hidden'] = '';
		}

		return HTMLUtils::element('div', $group, $paneAttrs);
	}

	/**
	 * Render the actual editable control for one locale. Plain-text field
	 * emits an `<input type="text">`; the styled-text subclass overrides this
	 * to emit a Tiptap-ready textarea.
	 */
	protected function buildLocaleInput(string $code, string $dir, string $id, string $value): string
	{
		$attributes = [
			'type'         => $this->inputType,
			'id'           => $id,
			'data-locale'  => $code,
			'dir'          => $dir,
			'value'        => $value === '' ? null : $value,
			'placeholder'  => $this->placeholder === '' ? null : $this->placeholder,
			'maxlength'    => $this->maxlength > 0 ? (string)$this->maxlength : null,
			'autocomplete' => 'nofill',
		];
		$attributes = array_filter($attributes, static fn ($x): bool => $x !== null);

		return HTMLUtils::inlineElement('input', $attributes);
	}

	/**
	 * Coerce the incoming value (which may be the stored dict, a JSON string,
	 * or an empty placeholder) into a locale-keyed dict for rendering.
	 *
	 * @return array<string,string>
	 */
	protected function coerceToDict(mixed $value): array
	{
		if (is_array($value)) {
			$out = [];
			foreach ($value as $locale => $text) {
				if (is_string($locale) && is_scalar($text)) {
					$out[$locale] = (string)$text;
				}
			}

			return $out;
		}

		if (is_string($value) && $value !== '' && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
			$decoded = json_decode($value, true);
			if (is_array($decoded)) {
				return $this->coerceToDict($decoded);
			}
		}

		return [];
	}

	/**
	 * Build an HTML-id-safe suffix from a locale code (`en_US` → `en_us`).
	 */
	protected function slugifyLocale(string $code): string
	{
		return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $code) ?? '');
	}
}
