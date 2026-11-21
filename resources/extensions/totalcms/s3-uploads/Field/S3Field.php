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

	/** File name extensions by kind, for files whose type alone does not say. */
	private const KINDS = [
		'image'   => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'heic', 'heif', 'tiff', 'bmp', 'psd', 'raw'],
		'audio'   => ['mp3', 'wav', 'aiff', 'flac', 'm4a', 'ogg', 'aac', 'wma'],
		'video'   => ['mp4', 'm4v', 'mov', 'avi', 'wmv', 'mkv', 'webm', 'mpg', 'mpeg'],
		'doc'     => ['pdf', 'doc', 'docx', 'odt', 'rtf', 'pages'],
		'text'    => ['txt', 'md', 'markdown', 'epub', 'mobi'],
		'sheet'   => ['csv', 'xls', 'xlsx', 'ods', 'numbers'],
		'slides'  => ['ppt', 'pptx', 'key', 'odp'],
		'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'dmg', 'pkg', 'iso'],
		'code'    => ['html', 'css', 'js', 'json', 'xml', 'php', 'py', 'yaml', 'yml'],
	];

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
	 * One file as a row: what kind of file it is, its name, date and downloads,
	 * its size, a progress bar and its buttons. The buttons are icons; each
	 * keeps its name as a tooltip and as text for screen readers.
	 *
	 * @param array<string,string|bool|null> $attributes extra attributes for the row element
	 * @param bool                           $replace    offer Replace: a single file has it, a file in a list is removed and added
	 * @param bool                           $handle     show a grip to drag the row by, in a list that can be reordered
	 */
	protected function card(?Pointer $pointer, string $tag = 'div', array $attributes = [], bool $replace = true, bool $handle = false): string
	{
		$grip      = $handle ? HTMLUtils::element('span', '', ['class' => 's3-handle', 'aria-hidden' => 'true']) : '';
		$icon      = HTMLUtils::element('span', '', ['class' => 's3-icon', 'aria-hidden' => 'true']);
		$name      = HTMLUtils::element('strong', self::text($pointer->name ?? ''), ['class' => 's3-name']);
		$date      = HTMLUtils::element('span', self::text(self::day($pointer->uploadedAt ?? '')), ['class' => 's3-date']);
		$downloads = HTMLUtils::element('span', '', ['class' => 's3-downloads']);
		$details   = HTMLUtils::element('span', $date . $downloads, ['class' => 's3-details']);
		$meta      = HTMLUtils::element('div', $name . $details, ['class' => 's3-meta']);
		$size      = HTMLUtils::element('span', $pointer instanceof Pointer ? UploadRules::formatSize($pointer->size) : '', ['class' => 's3-size']);

		$progress = HTMLUtils::element('progress', '', ['class' => 's3-progress', 'max' => '100', 'value' => '0', 'hidden' => true]);

		$actions = HTMLUtils::element(
			'div',
			self::action('a', 'open', 'Open', ['target' => '_blank', 'rel' => 'noopener'])
			. ($replace ? self::action('button', 'replace', 'Replace') : '')
			. self::action('button', 'remove', 'Remove')
			. self::action('button', 'cancel', 'Cancel', ['hidden' => true])
			. self::action('button', 'retry', 'Try again', ['hidden' => true]),
			['class' => 's3-actions'],
		);

		return HTMLUtils::element(
			$tag,
			$grip . $icon . $meta . $size . $progress . $actions,
			['class' => 's3-card', 'data-kind' => self::kind($pointer->name ?? '', $pointer->mime ?? '')] + $attributes,
		);
	}

	/**
	 * What kind of file this is, for its icon: image, audio, video, doc, text,
	 * sheet, slides, archive, code or file. The type decides for media; the
	 * name's extension decides the rest, as the core file fields do. The
	 * browser script has the same table (`fileKind`).
	 */
	public static function kind(string $name, string $mime): string
	{
		$mime = strtolower($mime);
		foreach (['image', 'audio', 'video'] as $media) {
			if (str_starts_with($mime, $media . '/')) {
				return $media;
			}
		}

		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		foreach (self::KINDS as $kind => $extensions) {
			if (in_array($extension, $extensions, true)) {
				return $kind;
			}
		}

		return 'file';
	}

	/**
	 * An icon button of a row. `button btn` is left off on purpose: those are
	 * the admin's text-button styles.
	 *
	 * @param array<string,string|bool|null> $attributes
	 */
	private static function action(string $tag, string $name, string $label, array $attributes = []): string
	{
		return HTMLUtils::element(
			$tag,
			HTMLUtils::element('span', $label, ['class' => 's3-label']),
			($tag === 'button' ? ['type' => 'button'] : []) + ['class' => 's3-action s3-' . $name, 'title' => $label] + $attributes,
		);
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
