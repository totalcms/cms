<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Infrastructure\Filesystem\FileUtils;

class DepotField extends UploadField
{
	protected string $defaultFieldType = 'file';

	public function buildFormField(): string
	{
		$depot = is_array($this->value) ? $this->value : []; // Depot data is stored in the value field

		$input            = $this->proxyInput(['id' => 'field-' . $this->uuid, 'type' => 'text', 'name' => $this->name]);
		$browser          = $this->buildLayout($depot['files'] ?? []);
		$folderDialog     = $this->folderDialog();
		$addFolder        = $this->addFolderDialog();
		$fileTemplate     = $this->fileTemplate();
		$folderTemplate   = $this->folderTemplate();
		$protectionDialog = $this->protectionDialog($depot);

		return $input . $browser . $addFolder . $folderDialog . $fileTemplate . $folderTemplate . $protectionDialog;
	}

	protected function linkTool(): string
	{
		return 'filelinks';
	}

	protected function linkDialogClass(): string
	{
		return 'file-links-dialog';
	}

	/** @param array<array<string,mixed>> $files */
	private function buildLayout(array $files): string
	{
		$browser    = $this->buildBrowser($files);
		$preview    = $this->depotPreview();
		$layout     = HTMLUtils::element('div', $browser . $preview, ['class' => 'depot-layout']);
		$editButton = HTMLUtils::button('', [
			'class' => 'protect',
			'title' => $this->t('depot.edit_protection', 'Edit Depot Protection'),
		]);

		return HTMLUtils::element('div', $editButton . $layout, ['class' => 'depot-layout-container']);
	}

	/** @param array<string,mixed> $depot */
	private function protectionDialog(array $depot): string
	{
		$passwordHelp = $this->t('depot.password_help', 'Require a password to download files from this depot. This overrides all collection level access controls.');
		$content      = $this->protectionFields($depot, $passwordHelp) . $this->closeSection();

		return HTMLUtils::dialog($content, 'protection-dialog');
	}

	/** @param array<array<string,mixed>> $files */
	private function buildBrowser(array $files): string
	{
		$search  = HTMLUtils::inlineElement('input', [
			'type'        => 'search',
			'class'       => 'depot-filter',
			'placeholder' => $this->t('depot.filter_placeholder', 'Filter files...'),
		]);
		$filter  = HTMLUtils::element('div', $search, ['class' => 'depot-filter-wrapper']);
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

		$name = $file['name'] ?? $this->t('depot.unknown_file', 'Unknown');
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
		$preview  = $this->esc('depot.preview', 'Preview');
		$size     = $this->esc('upload.size', 'Size');
		$date     = $this->esc('depot.date', 'Date');
		$count    = $this->esc('depot.count_short', 'D.Count');
		$download = $this->esc('depot.download_short', 'D.Name');
		$comments = $this->esc('upload.comments', 'Comments');
		$tags     = $this->esc('upload.tags', 'Tags');

		return <<<HTML
		<div class="file-preview cms-hide">
			<div class="file file-icon">
				<h4 class="file-name"></h4>
			</div>
			<div class="file-info">
				<div>
					<div class="info"><h6>{$size}</h6><span class="file-size"></span></div>
					<div class="info"><h6>{$date}</h6><span class="file-date"></span></div>
					<div class="info"><h6>{$count}</h6><span class="file-count"></span></div>
					<div class="info"><h6>{$download}</h6><span class="file-download"></span></div>
				</div>
				<div>
					<h6>{$comments}</h6>
					<p class="file-comments"></p>
				</div>
				<div>
					<h6>{$tags}</h6>
					<div class="file-tags"></div>
				</div>
			</div>
			<button type="button" class="preview-file" disabled>{$preview}</button>
		</div>
		<dialog class="cms-modal preview-dialog">
			<div class="preview-content"></div>
		</dialog>
		HTML;
	}

	protected function actionbar(): string
	{
		$addFolderDisabled = $this->form->isEditMode() ? '' : 'disabled';

		$edit      = $this->esc('upload.edit_file_info', 'Edit File Info');
		$links     = $this->esc('upload.download_links', 'Download Links');
		$download  = $this->esc('upload.download_file', 'Download File');
		$upload    = $this->esc('depot.upload', 'Upload');
		$newFolder = $this->esc('depot.new_folder', 'New Folder');
		$trash     = $this->esc('upload.delete_file', 'Delete File');

		return <<<HTML
		<div class="actionbar">
			<button type="button" class="edit" title="{$edit}" disabled></button>
			<button type="button" class="links" title="{$links}" disabled></button>
			<button type="button" class="download" title="{$download}" disabled></button>
			<button type="button" class="upload dz-clickable" title="{$upload}"></button>
			<button type="button" class="add-folder" title="{$newFolder}" {$addFolderDisabled}></button>
			<button type="button" class="trash" title="{$trash}" disabled></button>
		</div>
		HTML;
	}

	protected function addFolderDialog(): string
	{
		$content = $this->form->subField('addpath', [
			'field' => 'text',
			'label' => $this->t('depot.folder_path_label', 'Folder path'),
			'help'  => $this->t('depot.folder_path_help', 'The name and path to the folder that you want to create.'),
		]);
		$button   = HTMLUtils::button($this->esc('depot.add_folder', 'Add Folder'));
		$content .= HTMLUtils::element('section', $button);

		return HTMLUtils::dialog($content, 'folder-add-dialog');
	}

	/** @param array<string,mixed> $data */
	protected function folderDialog(array $data = []): string
	{
		$content = $this->form->subField('name', [
			'field'    => 'text',
			'label'    => $this->t('depot.folder_name_label', 'Folder Name'),
			'help'     => $this->t('depot.folder_name_help', 'The name of the folder.'),
			'value'    => $data['name'] ?? '',
			'required' => false, // Not required - folder renaming feature is incomplete, validation will be done in JS when implemented
		]);

		$content .= $this->closeSection();

		return HTMLUtils::dialog($content, 'folder-edit-dialog');
	}

	/** @param array<string,mixed> $data */
	protected function dialogFields(array $data): string
	{
		return $this->infoFields($data) . $this->metaFields($data);
	}

	/** Depot files are not indexed as media, so the tags field gets no suggestions. */
	protected function tagSuggestions(): ?array
	{
		return null;
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
			}
			if ($a['mime'] !== 'folder' && $b['mime'] === 'folder') {
				return 1;
			}

			return strcmp((string)$a['name'], (string)$b['name']);
		});

		return $files;
	}
}
