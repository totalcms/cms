<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Infrastructure\Filesystem\FileUtils;

class DepotField extends FormField
{
	protected string $defaultFieldType = 'file';
	protected string $defaultInputType = 'file';

	public function init(): void
	{
		parent::init();

		$this->icon = false; // No icon for file fields
	}

	public function buildFormField(): string
	{
		$depot = is_array($this->value) ? $this->value : []; // Depot data is stored in the value field

		$input            = HTMLUtils::inlineElement('input', ['id' => 'field-' . $this->uuid, 'type' => 'text', 'name' => $this->name]);
		$browser          = $this->buildLayout($depot['files'] ?? []);
		$folderDialog     = $this->folderDialog();
		$addFolder        = $this->addFolderDialog();
		$fileTemplate     = $this->fileTemplate();
		$folderTemplate   = $this->folderTemplate();
		$protectionDialog = $this->protectionDialog($depot);

		return $input . $browser . $addFolder . $folderDialog . $fileTemplate . $folderTemplate . $protectionDialog;
	}

	/** @param array<array<string,mixed>> $files */
	private function buildLayout(array $files): string
	{
		$browser    = $this->buildBrowser($files);
		$preview    = $this->depotPreview();
		$layout     = HTMLUtils::element('div', $browser . $preview, ['class' => 'depot-layout']);
		$editButton = HTMLUtils::button('', ['class' => 'protect', 'title' => 'Edit Depot Protection']);

		return HTMLUtils::element('div', $editButton . $layout, ['class' => 'depot-layout-container']);
	}

	/** @param array<string,mixed> $depot */
	private function protectionDialog(array $depot): string
	{
		// Determine default protected value from settings or default to true
		$defaultProtected = $this->settings['protectedByCollection'] ?? true;

		$content = $this->form->field('protected', [
			'field'       => 'checkbox',
			'label'       => 'Protected by Collection',
			'help'        => 'Access group protection is set in the Collection.',
			'value'       => $depot['protected'] ?? $defaultProtected,
		]);
		$content .= $this->form->field('password', [
			'field'    => 'password',
			'label'    => 'Password',
			'help'     => 'Require a password to download files from this depot. This overrides all collection level access controls.',
			'value'    => $depot['password'] ?? '',
			'required' => false,
		]);
		$content .= $this->closeSection();

		return HTMLUtils::dialog($content, 'protection-dialog');
	}

	/** @param array<array<string,mixed>> $files */
	private function buildBrowser(array $files): string
	{
		$filter  = '<div class="depot-filter-wrapper">'
			. '<input type="text" class="depot-filter">'
			. '<button type="button" class="depot-filter-reset cms-hide">&times;</button>'
			. '</div>';
		$browser = HTMLUtils::element('ul', $this->buildFolder($files), ['class' => 'depot-browser']);

		return HTMLUtils::element('div', $filter . $browser, ['class' => 'depot-browser-wrapper']);
	}

	/** @param array<array<string,mixed>> $files */
	private function buildFolder(array $files, string $path = ''): string
	{
		$content = '';
		$files   = $this->sortFiles($files);

		foreach ($files as $file) {
			if ($file['mime'] === 'folder') {
				$folderPath   = $path . $file['name'] . '/';
				$buildFolder  = $this->buildFolder($file['files'] ?? [], $folderPath);
				$folderFiles  = HTMLUtils::element('ul', $buildFolder, ['class' => 'folder-contents']);
				$summary      = HTMLUtils::element('summary', $file['name'], [
					'class'     => 'folder',
					'data-path' => trim($folderPath, '/'),
				]);
				$details  = HTMLUtils::element('details', $summary . $folderFiles);
				$content .= HTMLUtils::element('li', $details);
				continue;
			}
			$content .= $this->buildFile($file, $path);
		}

		return $content;
	}

	/** @param array<string,mixed> $file */
	private function buildFile(array $file = [], string $path = ''): string
	{
		// <li>
		// <div class="file file-icon icon-png">BrazilHeart.png</div>
		// <div class="size">3MB</div>
		// </li>

		$name = $file['name'] ?? 'Unknown';
		$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$size = FileUtils::fileSizeString($file['size'] ?? 0);

		$fileName = HTMLUtils::element('div', $name, ['class' => "file file-icon icon-$ext"]);
		$size     = HTMLUtils::element('div', $size, ['class' => 'size']);

		$linkDialog = $this->linkDialog($name, $path);
		$fileDialog = $this->fileDialog($file);

		return HTMLUtils::element('li', $fileName . $size . $linkDialog . $fileDialog);
	}

	protected function depotPreview(): string
	{
		$previews = $this->folderPreview() . $this->filePreview() . $this->actionbar();

		return HTMLUtils::element('div', $previews, ['class' => 'depot-preview']);
	}

	protected function folderPreview(): string
	{
		return <<<HTML
		<div class="folder-preview dz-clickable">
			<div class="dz-overlay"></div>
			<h4 class="folder-name"></h4>
		</div>
		HTML;
	}

	protected function folderTemplate(): string
	{
		$folderFiles = HTMLUtils::element('ul', '', ['class' => 'folder-contents']);
		$summary     = HTMLUtils::element('summary', '', ['class' => 'folder', 'data-path' => '']);
		$details     = HTMLUtils::element('details', $summary . $folderFiles);
		$folder      = HTMLUtils::element('li', $details);

		return HTMLUtils::element('template', $folder, ['class' => 'folder-template']);
	}

	protected function fileTemplate(): string
	{
		return HTMLUtils::element('template', $this->buildFile(), ['class' => 'file-template']);
	}

	protected function filePreview(): string
	{
		return <<<HTML
		<div class="file-preview cms-hide">
			<div class="file file-icon">
				<h4 class="file-name"></h4>
			</div>
			<div class="file-info">
				<div>
					<div class="info"><h6>Size</h6><span class="file-size"></span></div>
					<div class="info"><h6>Date</h6><span class="file-date"></span></div>
					<div class="info"><h6>D.Count</h6><span class="file-count"></span></div>
					<div class="info"><h6>D.Name</h6><span class="file-download"></span></div>
				</div>
				<div>
					<h6>Comments</h6>
					<p class="file-comments"></p>
				</div>
				<div>
					<h6>Tags</h6>
					<div class="file-tags"></div>
				</div>
			</div>
			<button type="button" class="preview-file" disabled>Preview</button>
		</div>
		<dialog class="cms-modal preview-dialog">
			<div class="preview-content"></div>
		</dialog>
		HTML;
	}

	protected function actionbar(): string
	{
		$addFolderDisabled = $this->form->isEditMode() ? '' : 'disabled';

		return <<<HTML
		<div class="actionbar">
			<button type="button" class="edit" title="Edit File Info" disabled></button>
			<button type="button" class="links" title="Download Links" disabled></button>
			<button type="button" class="download" title="Download File" disabled></button>
			<button type="button" class="upload dz-clickable" title="Upload"></button>
			<button type="button" class="add-folder" title="New Folder" {$addFolderDisabled}></button>
			<button type="button" class="trash" title="Delete File" disabled></button>
		</div>
		HTML;
	}

	protected function addFolderDialog(): string
	{
		$content = $this->form->field('addpath', [
			'field' => 'text',
			'label' => 'Folder path',
			'help'  => 'The name and path to the folder that you want to create.',
		]);
		$button   = HTMLUtils::button('Add Folder');
		$content .= HTMLUtils::element('section', $button);

		return HTMLUtils::dialog($content, 'folder-add-dialog');
	}

	protected function linkDialog(string $filename, string $path = ''): string
	{
		$query = http_build_query(array_filter([
			'id'         => $this->form->id,
			'collection' => $this->form->collection,
			'property'   => $this->name,
			'name'       => $filename,
			'path'       => trim($path, '/'),
		]));
		// 	The cms.api may have a ? because of the Stacks Preview server
		$join = str_contains($this->form->api, '?') ? '&' : '?';

		$iframe = HTMLUtils::iframe("{$this->form->api}/admin/filelinks{$join}{$query}");

		return HTMLUtils::dialog($iframe, 'file-links-dialog');
	}

	/** @param array<string,mixed> $data */
	protected function folderDialog(array $data = []): string
	{
		$content = $this->form->field('name', [
			'field'    => 'text',
			'label'    => 'Folder Name',
			'help'     => 'The name of the folder.',
			'value'    => $data['name'] ?? '',
			'required' => false, // Not required - folder renaming feature is incomplete, validation will be done in JS when implemented
		]);

		$content .= $this->closeSection();

		return HTMLUtils::dialog($content, 'folder-edit-dialog');
	}

	/** @param array<string,mixed> $fileData */
	protected function fileDialog(array $fileData): string
	{
		$content = $this->fileFieldsSection($fileData);
		$content .= $this->closeSection();

		return HTMLUtils::dialog($content, 'file-edit-dialog');
	}

	/** @param array<string,mixed> $fileData */
	private function fileFieldsSection(array $fileData): string
	{
		$fields  = $this->infoFields($fileData);
		$fields .= $this->metaFields($fileData);

		return HTMLUtils::scroller($fields);
	}

	private function closeSection(): string
	{
		$button = HTMLUtils::button('Close', ['class' => 'close']);

		return HTMLUtils::element('section', $button);
	}

	/** @param array<string,mixed> $fileData */
	private function infoFields(array $fileData): string
	{
		$content = $this->form->field('download', [
			'field' => 'text',
			'label' => 'Download Name',
			'help'  => 'The name of the file when it gets downloaded.',
			'value' => $fileData['download'] ?? $fileData['name'] ?? '',
		]);
		$content .= $this->form->field('comments', [
			'field'       => 'textarea',
			'label'       => 'Comments',
			'help'        => 'Comments about this file',
			'value'       => $fileData['comments'] ?? '',
		]);
		$content .= $this->form->field('tags', [
			'field'       => 'list',
			'label'       => 'Tags',
			'help'        => 'Add tags to help organize your files.',
			'placeholder' => 'Add Tags',
			'value'       => $fileData['tags'] ?? [],
		]);

		return HTMLUtils::details('Info', $content);
	}

	/** @param array<string,mixed> $fileData */
	private function metaFields(array $fileData): string
	{
		$content = $this->form->field('name', [
			'field'    => 'text',
			'label'    => 'Filename',
			'icon'     => false,
			'readonly' => true,
			'value'    => $fileData['name'] ?? '',
		]);
		$content .= $this->form->field('ext', [
			'field'    => 'text',
			'label'    => 'Extension',
			'icon'     => false,
			'readonly' => true,
			'value'    => $fileData['ext'] ?? '',
		]);
		$content .= $this->form->field('size', [
			'field'    => 'number',
			'label'    => 'Size',
			'icon'     => false,
			'readonly' => true,
			'value'    => $fileData['size'] ?? '',
		]);
		$content .= $this->form->field('count', [
			'field'    => 'number',
			'label'    => 'Download Count',
			'icon'     => false,
			'readonly' => true,
			'value'    => $fileData['count'] ?? '',
		]);
		$content .= $this->form->field('mime', [
			'field'    => 'text',
			'label'    => 'MIME Type',
			'icon'     => false,
			'readonly' => true,
			'value'    => $fileData['mime'] ?? '',
		]);
		$content .= $this->form->field('uploadDate', [
			'field'    => 'datetime',
			'label'    => 'Upload Date',
			'icon'     => false,
			'readonly' => true,
			'value'    => $fileData['uploadDate'] ?? '',
		]);

		return HTMLUtils::details('Meta (Readonly)', $content);
	}

	/**
	 * @param array<array<string,mixed>> $files
	 *
	 * @return array<array<string,mixed>>
	 */
	private function sortFiles(array $files): array
	{
		// Sort folders first, then files by name
		usort($files, function (array $a, array $b): int {
			if ($a['mime'] === 'folder' && $b['mime'] !== 'folder') {
				return -1;
			} elseif ($a['mime'] !== 'folder' && $b['mime'] === 'folder') {
				return 1;
			}

			return strcmp((string)$a['name'], (string)$b['name']);
		});

		return $files;
	}
}
