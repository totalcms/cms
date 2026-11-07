<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Media\Data\PublicUploadGrant;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Security\BotProtection;

class DepotDropField extends FormField
{
	protected string $defaultFieldType = 'depotDrop';
	protected string $defaultInputType = 'file';

	private const COMMENT_MAX_CHARS = 1000;
	private const COMMENT_ROWS      = 3;
	private const BYTES_PER_MB      = 1048576;

	/** Set for an anonymous visitor on a depot that grants public uploads. */
	private ?PublicUploadGrant $grant = null;

	public function init(): void
	{
		parent::init();

		$this->icon  = false;
		$this->grant = $this->form->publicUploadGrant($this->name);

		// Hints for the browser only; the server checks every file again. Only
		// the accept list, the per-file cap and the comments flag are handed
		// over, taken from the grant. The form lists no files of this depot (the
		// drop is write-only), though the form builder itself still loads the
		// object, as it does for every edit form.
		if ($this->grant instanceof PublicUploadGrant) {
			$this->settings['acceptedFiles'] = implode(',', array_map(static fn (string $ext): string => '.' . $ext, $this->grant->extensions));
			$this->settings['maxFilesize']   = $this->grant->maxFileBytes / self::BYTES_PER_MB;
			// A template can hide the box, never add one the grant does not take.
			$this->settings['comments']      = $this->grant->comments && ($this->settings['comments'] ?? true) !== false;
			// A public page has no JS translation catalog, so the visitor's
			// messages are rendered here, in the page's locale.
			$this->settings['messages']      = [
				'file_too_big'      => $this->t('depot.file_too_big', 'This file is too big ({{filesize}}MB). The limit is {{maxFilesize}}MB.'),
				'invalid_file_type' => $this->t('depot.invalid_file_type', 'This type of file is not accepted.'),
				'upload_failed'     => $this->t('depot.upload_failed', 'The upload did not go through. Please try again.'),
				'files_sent'        => $this->t('depot.files_sent', 'Your files were sent.'),
				'processing'        => $this->t('depot.processing', 'Processing...'),
				'upload_expired'    => $this->t('depot.upload_expired', 'This form has expired. Reload the page and try again.'),
				'remove_file'       => $this->t('depot.remove_file', 'Remove'),
			];
		}
	}

	private function wantsComments(): bool
	{
		return ($this->settings['comments'] ?? false) === true;
	}

	/**
	 * The tag menu's options, from the `tags` field setting: plain strings, or
	 * `value` and `label` pairs as select options use.
	 *
	 * @return array<string,string> Label by value
	 */
	private function tagOptions(): array
	{
		$raw = $this->settings['tags'] ?? [];
		if (!is_array($raw)) {
			return [];
		}

		$options = [];
		foreach ($raw as $option) {
			$value = is_array($option) ? ($option['value'] ?? '') : $option;
			if (!is_string($value) || trim($value) === '') {
				continue;
			}
			$label = is_array($option) && is_string($option['label'] ?? null) ? $option['label'] : $value;

			$options[trim($value)] = $label;
		}

		return $options;
	}

	public function buildFormField(): string
	{
		$input    = HTMLUtils::inlineElement('input', ['id' => 'field-' . $this->uuid, 'type' => 'text', 'name' => $this->name]);
		$dropzone = $this->dropzone();
		$template = $this->fileTemplate();

		return $input . $this->botFields() . $dropzone . $template;
	}

	/** The tag menu, comment box and Send button follow the zone's own help text. */
	public function createFormField(string $content): string
	{
		return HTMLUtils::element('div', $this->createFieldLabel() . $content . $this->createHelpText() . $this->batchFields(), $this->buildFieldAttributes());
	}

	/**
	 * The honeypot and the signed time-trap token, for an anonymous visitor on
	 * a granted depot only. Plain inputs, not `.form-field`s: nothing submits
	 * them with the form, the script reads them and sends them with every chunk.
	 */
	private function botFields(): string
	{
		if (!$this->grant instanceof PublicUploadGrant) {
			return '';
		}

		$id       = 'field-' . bin2hex(random_bytes(6));
		$honeypot = HTMLUtils::element('label', 'Leave this field empty', ['for' => $id])
			. HTMLUtils::inlineElement('input', [
				'type'           => 'text',
				'id'             => $id,
				'name'           => BotProtection::HONEYPOT_FIELD,
				'tabindex'       => '-1',
				'autocomplete'   => 'off',
				'data-1p-ignore' => '',
				'data-lpignore'  => 'true',
				'data-bwignore'  => '',
				'data-form-type' => 'other',
			]);
		$token = HTMLUtils::inlineElement('input', [
			'type'  => 'hidden',
			'name'  => BotProtection::TIMESTAMP_FIELD,
			'value' => (new BotProtection())->issue($this->form->collection, 'upload'),
		]);

		return HTMLUtils::element('div', $honeypot . $token, ['class' => 'depot-drop-bot', 'aria-hidden' => 'true']);
	}

	/** What applies to the whole batch, and the button that starts the upload. */
	private function batchFields(): string
	{
		$fields = $this->tagMenu() . $this->commentBox();
		if ($fields === '') {
			return '';
		}

		$send = HTMLUtils::element('button', $this->escape($this->t('depot.send_files', 'Send Files')), [
			'type'  => 'button',
			'class' => 'depot-drop-send cms-button no-icon',
		]);

		return HTMLUtils::element('div', $fields . $send, ['class' => 'depot-drop-comment']);
	}

	/** Pick one tag; a blank first option leaves the batch untagged. */
	private function tagMenu(): string
	{
		$tags = $this->tagOptions();
		if ($tags === []) {
			return '';
		}

		$options = HTMLUtils::element('option', '', ['value' => '']);
		foreach ($tags as $value => $label) {
			$options .= HTMLUtils::element('option', $this->escape($label), ['value' => (string)$value]);
		}

		$id = 'tag-' . $this->uuid;

		return HTMLUtils::element('label', $this->escape($this->label('tagLabel', 'depot.tag_label', 'Tag')), ['for' => $id])
			. HTMLUtils::element('select', $options, ['id' => $id, 'class' => 'depot-drop-tag']);
	}

	/** One comment for the whole batch. */
	private function commentBox(): string
	{
		if (!$this->wantsComments()) {
			return '';
		}

		$id    = 'comment-' . $this->uuid;
		$label = HTMLUtils::element('label', $this->escape($this->label('commentLabel', 'depot.comment_label', 'Comment')), ['for' => $id]);
		$area  = HTMLUtils::element('textarea', '', [
			'id'            => $id,
			'class'         => 'depot-drop-comment-text',
			'rows'          => self::COMMENT_ROWS,
			'maxlength'     => self::COMMENT_MAX_CHARS,
			// Grows with the text, as TextareaField's do; never below its rows.
			'data-autosize' => '',
			'style'         => '--rows:' . self::COMMENT_ROWS,
		]);
		$help = HTMLUtils::element('p', $this->escape($this->t('depot.comment_help', 'Optional. Added to every file you send in this batch.')), ['class' => 'help']);

		return $label . $area . $help;
	}

	/** The template's own wording when it set some, otherwise the translated default. */
	private function label(string $setting, string $key, string $fallback): string
	{
		$custom = $this->settings[$setting] ?? null;

		return is_string($custom) && trim($custom) !== '' ? $custom : $this->t($key, $fallback);
	}

	private function escape(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}

	private function dropzone(): string
	{
		$overlay = HTMLUtils::element('div', '', ['class' => 'dz-overlay']);
		$preview = HTMLUtils::element('ul', '', ['class' => 'total-preview']);
		$upload  = $this->uploadButton();
		$hint    = $this->help === '' ? HTMLUtils::element('p', $this->escape($this->t('depot.drop_hint', 'Drop files here or click to browse')), ['class' => 'depot-drop-hint']) : '';

		return HTMLUtils::element('div', $overlay . $hint . $preview . $upload, ['class' => 'depot-drop-zone dz-clickable']);
	}

	private function uploadButton(): string
	{
		$button = HTMLUtils::element('button', '', [
			'type'  => 'button',
			'title' => $this->t('depot.upload_files', 'Upload Files'),
		]);

		return HTMLUtils::element('div', $button, ['class' => 'depot-drop-upload dz-clickable']);
	}

	private function fileTemplate(): string
	{
		// Card structure: icon on top, filename below, progress bar added by JS
		$icon    = HTMLUtils::element('div', '', ['class' => 'file-icon']);
		$name    = HTMLUtils::element('p', '', ['class' => 'filename']);
		$preview = HTMLUtils::element('div', $icon . $name, ['class' => 'dz-preview']);
		$card    = HTMLUtils::element('li', $preview, ['class' => 'depot-drop-card']);

		return HTMLUtils::element('template', $card, ['class' => 'file-template']);
	}
}
