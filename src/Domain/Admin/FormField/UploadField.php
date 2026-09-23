<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Shared shell for the Dropzone-backed fields: file, image, gallery, depot.
 *
 * Holds the proxy input + overlay + preview + <template> markup, the
 * link-tool dialog (filelinks / imageworks), and the edit dialog's info,
 * protection and meta sections. Every string goes through the admin catalog
 * with its English default beside it, so a subclass never ships a
 * hard-coded label.
 */
abstract class UploadField extends FormField
{
	protected string $defaultInputType = 'file';

	public function init(): void
	{
		parent::init();

		$this->icon = false; // The Dropzone shell has no room for a field icon
	}

	/** Admin tool the "links" dialog loads: `filelinks` or `imageworks`. */
	abstract protected function linkTool(): string;

	abstract protected function linkDialogClass(): string;

	/**
	 * The <details> sections of the edit dialog, in order.
	 *
	 * @param array<string,mixed> $data
	 */
	abstract protected function dialogFields(array $data): string;

	/**
	 * Translated string escaped for interpolation into a heredoc template.
	 * HTMLUtils escapes attribute values itself, so this is only needed where
	 * we assemble markup by hand rather than through the builders.
	 */
	protected function esc(string $key, string $default): string
	{
		return htmlspecialchars($this->t($key, $default), ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Dot-notation property path: `mycard.file` for a card child,
	 * `mydeck.item-3.image` for a deck child, the bare name at top level.
	 * The link tools resolve nested media from it and emit the matching macro.
	 */
	protected function propertyPath(): string
	{
		return $this->nestedPath !== null ? "{$this->nestedPath}.{$this->name}" : $this->name;
	}

	/** The hidden proxy input the JS field writes its JSON value into. */
	protected function uploadInput(): string
	{
		$attributes = ['id' => 'field-' . $this->uuid, 'type' => 'text', 'name' => $this->name];
		if ($this->required) {
			$attributes['required'] = '';
		}

		return $this->proxyInput($attributes);
	}

	protected function dropzoneOverlay(): string
	{
		return HTMLUtils::element('div', '', ['class' => 'dz-overlay dz-clickable']);
	}

	/** Input + overlay + the live preview + the <template> Dropzone clones for a new upload. */
	protected function dropzoneShell(string $previewTemplate): string
	{
		$preview  = HTMLUtils::element('div', $previewTemplate, ['class' => 'total-preview']);
		$template = HTMLUtils::element('template', $previewTemplate, ['id' => 'template-' . $this->uuid]);

		return $this->uploadInput() . $this->dropzoneOverlay() . $preview . $template;
	}

	/**
	 * The dialog that loads the admin link tool for one file. Gallery and
	 * depot pass the file's name (and depot its folder); a single-file field
	 * passes nothing and the tool resolves the property.
	 */
	protected function linkDialog(?string $name = null, string $path = ''): string
	{
		$query = http_build_query(array_filter([
			'id'         => $this->form->id,
			'collection' => $this->form->collection,
			'property'   => $this->propertyPath(),
			'name'       => $name,
			'path'       => trim($path, '/'),
		], fn (?string $v): bool => $v !== null && $v !== ''));
		// The cms.api may have a ? because of the Stacks Preview server
		$join = str_contains($this->form->api, '?') ? '&' : '?';

		$iframe = HTMLUtils::iframe("{$this->form->baseApi()}/admin/{$this->linkTool()}{$join}{$query}");

		return HTMLUtils::dialog($iframe, $this->linkDialogClass());
	}

	/** @param array<string,mixed> $data */
	protected function fileDialog(array $data): string
	{
		$content = HTMLUtils::scroller($this->dialogFields($data));
		$content .= $this->closeSection();

		return HTMLUtils::dialog($content, 'file-edit-dialog');
	}

	protected function closeSection(): string
	{
		$button = HTMLUtils::button($this->esc('btn.close', 'Close'), ['class' => 'close']);

		return HTMLUtils::element('section', $button);
	}

	/** @param array<string,mixed> $data */
	protected function infoFields(array $data): string
	{
		$content = $this->form->subField('download', [
			'field' => 'text',
			'label' => $this->t('upload.download_name_label', 'Download Name'),
			'help'  => $this->t('upload.download_name_help', 'The name of the file when it gets downloaded.'),
			'value' => $data['download'] ?? $data['name'] ?? '',
		]);
		$content .= $this->form->subField('comments', [
			'field' => 'textarea',
			'label' => $this->t('upload.comments', 'Comments'),
			'help'  => $this->t('upload.comments_help', 'Comments about this file'),
			'value' => $data['comments'] ?? '',
		]);
		$content .= $this->form->subField('tags', $this->tagFieldSettings($data));

		return HTMLUtils::details($this->t('upload.section_info', 'Info'), $content);
	}

	/**
	 * Settings for the generated `tags` sub-field, with tag suggestions from
	 * the collection index when {@see tagSuggestions()} finds any.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	protected function tagFieldSettings(array $data): array
	{
		$settings = [
			'field'       => 'list',
			'label'       => $this->t('upload.tags', 'Tags'),
			'help'        => $this->tagsHelp(),
			'placeholder' => $this->t('upload.tags_placeholder', 'Add Tags'),
			'value'       => $data['tags'] ?? [],
		];

		$options = $this->tagSuggestions();
		if ($options !== null) {
			$settings['settings'] = ['propertyOptions' => $options];
		}

		return $settings;
	}

	protected function tagsHelp(): string
	{
		return $this->t('upload.tags_help', 'Add tags to help organize your files.');
	}

	/**
	 * Suggestions sourced from the collection index when this property is
	 * top-level and indexed. Suggestions are additive; null attaches none.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function tagSuggestions(): ?array
	{
		return $this->mediaTagOptions();
	}

	/**
	 * The two protection controls. The password help differs per field (one
	 * file vs. a whole depot), so the caller supplies it.
	 *
	 * @param array<string,mixed> $data
	 */
	protected function protectionFields(array $data, string $passwordHelp): string
	{
		// Determine default protected value from settings or default to true
		$defaultProtected = $this->settings['protectedByCollection'] ?? true;

		$content = $this->form->subField('protected', [
			'field' => 'checkbox',
			'label' => $this->t('upload.protected_label', 'Protected by Collection'),
			'help'  => $this->t('upload.protected_help', 'Access group protection is set in the Collection.'),
			'value' => $data['protected'] ?? $defaultProtected,
		]);
		$content .= $this->form->subField('password', [
			'field'    => 'password',
			'label'    => $this->t('upload.password_label', 'Password'),
			'help'     => $passwordHelp,
			'value'    => $data['password'] ?? '',
			'required' => false,
			'settings' => ['ignoreManagers' => true],
		]);

		return $content;
	}

	/**
	 * The readonly meta section for a file: name, extension, size, download
	 * count, MIME type, upload date.
	 *
	 * @param array<string,mixed> $data
	 */
	protected function metaFields(array $data): string
	{
		$content = $this->readonlyField('name', $this->t('upload.filename_label', 'Filename'), $data['name'] ?? '');
		$content .= $this->readonlyField('ext', $this->t('upload.extension_label', 'Extension'), $data['ext'] ?? '');
		// Integers in file.json — an empty input serializes as null and fails
		// validation, so cast like FileData does: an absent file, a null, or
		// an empty string all read as 0.
		$content .= $this->readonlyField('size', $this->t('upload.size', 'Size'), intval($data['size'] ?? 0), 'number');
		$content .= $this->readonlyField('count', $this->t('upload.download_count_label', 'Download Count'), intval($data['count'] ?? 0), 'number');
		$content .= $this->readonlyField('mime', $this->t('upload.mime_label', 'MIME Type'), $data['mime'] ?? '');
		$content .= $this->readonlyField('uploadDate', $this->t('upload.upload_date_label', 'Upload Date'), $data['uploadDate'] ?? '', 'datetime');

		return HTMLUtils::details($this->t('upload.section_meta', 'Meta (Readonly)'), $content);
	}

	/** One readonly, icon-less sub-field of the meta section. */
	protected function readonlyField(string $name, string $label, mixed $value, string $field = 'text'): string
	{
		return $this->form->subField($name, [
			'field'    => $field,
			'label'    => $label,
			'icon'     => false,
			'readonly' => true,
			'value'    => $value,
		]);
	}
}
