<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Field;

use TotalCMS\Bundled\S3Uploads\Storage\Pointer;
use TotalCMS\Bundled\S3Uploads\Upload\UploadRules;
use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * What the s3file and s3depot fields share.
 *
 * The markup is a hidden input holding the stored value as JSON, a drop zone,
 * and a card per file. assets/s3-field.js does the upload: it asks this
 * extension for a link, PUTs the file straight to the bucket, and commits the
 * result. The server writes the pointer; nothing uploaded passes through here.
 */
abstract class S3Field extends FormField
{
	/** Why uploads are off for the whole site, or null when they are on. */
	private static ?string $offNotice = null;

	/**
	 * Fields are built with `new`, with no container to ask, so the extension
	 * hands this over when it registers.
	 */
	public static function configure(?string $offNotice): void
	{
		self::$offNotice = $offNotice;
	}

	/** No input icon: the field is a drop zone and cards, not a text box. */
	public function build(): string
	{
		return $this->createFormField($this->createFormGroup($this->buildFormField()));
	}

	/** Why this field cannot upload, or null when it can. */
	protected function notice(): ?string
	{
		return $this->nestedPath !== null ? 'S3 fields work only as top-level properties.' : self::$offNotice;
	}

	/** The hidden input the browser-side field reads. First in the markup: that field takes the first input as its own. */
	protected function valueInput(): string
	{
		return $this->proxyInput([
			'id'    => 'field-' . $this->uuid,
			'type'  => 'text',
			'name'  => $this->name,
			'value' => $this->storedJson(),
		]);
	}

	/** The field's visible part, wrapped with its state for the stylesheet and the script. */
	protected function wrap(string $content, string $state, string $class = 's3-field'): string
	{
		$notice = $this->notice();

		return HTMLUtils::element(
			'div',
			($notice === null ? '' : HTMLUtils::element('p', self::text($notice), ['class' => 's3-notice']))
				. $content
				. HTMLUtils::element('p', '', ['class' => 's3-error', 'role' => 'alert', 'hidden' => true]),
			['class' => $class, 'data-state' => $state, 'data-uploads' => $notice === null ? null : 'off'],
		);
	}

	protected function dropZone(bool $multiple = false): string
	{
		$choose = HTMLUtils::button($multiple ? 'Choose files' : 'Choose file', ['class' => 's3-choose']);
		$text   = HTMLUtils::element('p', ($multiple ? 'Drop files here, or ' : 'Drop a file here, or ') . $choose);
		$file   = HTMLUtils::inlineElement('input', [
			'type' => 'file', 'class' => 's3-input', 'tabindex' => '-1', 'aria-hidden' => 'true', 'hidden' => true, 'multiple' => $multiple,
		]);

		return HTMLUtils::element('div', $text . $file, ['class' => 's3-drop']);
	}

	/**
	 * One file's card: name, size, date, downloads, a progress bar and its buttons.
	 *
	 * @param array<string,string|bool|null> $attributes extra attributes for the card element
	 */
	protected function card(?Pointer $pointer, string $tag = 'div', array $attributes = [], bool $replace = true): string
	{
		$name      = HTMLUtils::element('strong', self::text($pointer->name ?? ''), ['class' => 's3-name']);
		$size      = HTMLUtils::element('span', $pointer instanceof Pointer ? UploadRules::formatSize($pointer->size) : '', ['class' => 's3-size']);
		$date      = HTMLUtils::element('span', self::text(self::day($pointer->uploadedAt ?? '')), ['class' => 's3-date']);
		$downloads = HTMLUtils::element('span', '', ['class' => 's3-downloads']);
		$details   = HTMLUtils::element('span', $size . $date . $downloads, ['class' => 's3-details']);
		$meta      = HTMLUtils::element('div', $name . $details, ['class' => 's3-meta']);

		$progress = HTMLUtils::element('progress', '', ['class' => 's3-progress', 'max' => '100', 'value' => '0', 'hidden' => true]);

		$actions = HTMLUtils::element(
			'div',
			HTMLUtils::element('a', 'Open', ['class' => 's3-open button btn', 'target' => '_blank', 'rel' => 'noopener'])
			. ($replace ? HTMLUtils::button('Replace', ['class' => 's3-replace']) : '')
			. HTMLUtils::button('Remove', ['class' => 's3-remove'])
			. HTMLUtils::button('Cancel', ['class' => 's3-cancel', 'hidden' => true])
			. HTMLUtils::button('Try again', ['class' => 's3-retry', 'hidden' => true]),
			['class' => 's3-actions'],
		);

		return HTMLUtils::element($tag, $meta . $progress . $actions, ['class' => 's3-card'] + $attributes);
	}

	/** The stored value as JSON for the hidden input; null when there is none. */
	private function storedJson(): ?string
	{
		if (is_string($this->value)) {
			return trim($this->value) === '' ? null : $this->value;
		}
		if (!is_array($this->value) || $this->value === []) {
			return null;
		}

		$json = json_encode($this->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return $json === false ? null : $json;
	}

	private static function text(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}

	/** "2026-09-05" from an ISO date; '' when it is not one. */
	private static function day(string $iso): string
	{
		return preg_match('/^\d{4}-\d{2}-\d{2}/', $iso, $match) === 1 ? $match[0] : '';
	}
}
