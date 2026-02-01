<?php

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Service for rendering public depot file browser components.
 */
class DepotBrowserRenderer
{
	/**
	 * Render a depot browser component.
	 *
	 * @param string $id Object ID
	 * @param array<string,mixed> $depot Depot data (password, protected, files)
	 * @param array<string,mixed> $options Rendering options
	 * @param callable(string, string, array<string,mixed>): string $downloadUrl
	 * @param callable(string, string, array<string,mixed>): string $streamUrl
	 */
	public function render(
		string $id,
		array $depot,
		array $options,
		callable $downloadUrl,
		callable $streamUrl,
	): string {
		$files = is_array($depot['files'] ?? null) ? $depot['files'] : [];
		if (empty($files)) {
			return '';
		}

		$dataOptions = json_encode(array_filter([
			'preview' => $options['preview'],
		]), JSON_THROW_ON_ERROR);

		$classes = HTMLUtils::mergeClasses('cms-depot-browser', $options['class']);
		$html    = "<div class=\"{$classes}\" data-options='" . htmlspecialchars($dataOptions, ENT_QUOTES, 'UTF-8') . "'>";

		if ($options['filter']) {
			$html .= $this->buildFilter();
		}

		if ($options['folders']) {
			$html .= $this->buildFileTree($files, '', $id, $options, $downloadUrl, $streamUrl);
		} else {
			$flat  = $this->flattenFiles($files, '');
			$html .= $this->buildFileTree($flat, '', $id, $options, $downloadUrl, $streamUrl);
		}

		if ($options['preview']) {
			$html .= $this->buildPreviewDialog();
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Build the file tree as a <ul> structure.
	 *
	 * @param array<int,array<string,mixed>> $files
	 * @param array<string,mixed> $options
	 * @param callable(string, string, array<string,mixed>): string $downloadUrl
	 * @param callable(string, string, array<string,mixed>): string $streamUrl
	 */
	private function buildFileTree(
		array $files,
		string $path,
		string $id,
		array $options,
		callable $downloadUrl,
		callable $streamUrl,
	): string {
		$html = '<ul class="depot-browser-tree">';

		foreach ($files as $file) {
			/** @phpstan-ignore function.alreadyNarrowedType */
			if (!is_array($file)) {
				continue;
			}
			$mime = $file['mime'] ?? '';
			if ($mime === 'folder') {
				$html .= $this->buildFolder($file, $path, $id, $options, $downloadUrl, $streamUrl);
			} else {
				$html .= $this->buildFile($file, $path, $id, $options, $downloadUrl, $streamUrl);
			}
		}

		$html .= '</ul>';

		return $html;
	}

	/**
	 * Build a single file item.
	 *
	 * @param array<string,mixed> $file
	 * @param array<string,mixed> $options
	 * @param callable(string, string, array<string,mixed>): string $downloadUrl
	 * @param callable(string, string, array<string,mixed>): string $streamUrl
	 */
	private function buildFile(
		array $file,
		string $path,
		string $id,
		array $options,
		callable $downloadUrl,
		callable $streamUrl,
	): string {
		$name = (string)($file['name'] ?? '');
		if ($name === '') {
			return '';
		}

		$ext      = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$size     = (int)($file['size'] ?? 0);
		$fullName = $path !== '' ? $path . '/' . $name : $name;

		$dlUrl     = $downloadUrl($id, $fullName, []);
		$streamSrc = $streamUrl($id, $fullName, []);

		$html = "<li class=\"depot-browser-item\" data-ext=\"{$ext}\" data-stream-url=\"" . htmlspecialchars($streamSrc, ENT_QUOTES, 'UTF-8') . '">';

		if ($options['download']) {
			$html .= "<a href=\"" . htmlspecialchars($dlUrl, ENT_QUOTES, 'UTF-8') . "\" class=\"file file-icon icon-{$ext}\" download>{$this->escape($name)}</a>";
		} else {
			$html .= "<span class=\"file file-icon icon-{$ext}\">{$this->escape($name)}</span>";
		}

		if ($size > 0) {
			$html .= '<span class="file-size">' . $this->formatSize($size) . '</span>';
		}

		if ($options['comments']) {
			$comments = trim((string)($file['comments'] ?? ''));
			if ($comments !== '') {
				$html .= '<p class="file-comments">' . $this->escape($comments) . '</p>';
			}
		}

		if ($options['tags']) {
			$tags = $file['tags'] ?? [];
			if (is_array($tags) && !empty($tags)) {
				$html .= '<div class="file-tags">';
				foreach ($tags as $tag) {
					$html .= '<span>' . $this->escape((string)$tag) . '</span>';
				}
				$html .= '</div>';
			}
		}

		$html .= '</li>';

		return $html;
	}

	/**
	 * Build a folder with nested contents.
	 *
	 * @param array<string,mixed> $folder
	 * @param array<string,mixed> $options
	 * @param callable(string, string, array<string,mixed>): string $downloadUrl
	 * @param callable(string, string, array<string,mixed>): string $streamUrl
	 */
	private function buildFolder(
		array $folder,
		string $path,
		string $id,
		array $options,
		callable $downloadUrl,
		callable $streamUrl,
	): string {
		$name     = (string)($folder['name'] ?? '');
		$children = is_array($folder['files'] ?? null) ? $folder['files'] : [];
		$subPath  = $path !== '' ? $path . '/' . $name : $name;

		$html  = '<li>';
		$html .= '<details>';
		$html .= '<summary class="folder">' . $this->escape($name) . '</summary>';
		$html .= $this->buildFileTree($children, $subPath, $id, $options, $downloadUrl, $streamUrl);
		$html .= '</details>';
		$html .= '</li>';

		return $html;
	}

	/**
	 * Flatten nested file structure into a single-level array.
	 *
	 * @param array<int,array<string,mixed>> $files
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function flattenFiles(array $files, string $path): array
	{
		$result = [];

		foreach ($files as $file) {
			/** @phpstan-ignore function.alreadyNarrowedType */
			if (!is_array($file)) {
				continue;
			}
			if (($file['mime'] ?? '') === 'folder') {
				$subPath  = $path !== '' ? $path . '/' . ($file['name'] ?? '') : ($file['name'] ?? '');
				$children = is_array($file['files'] ?? null) ? $file['files'] : [];
				$result   = array_merge($result, $this->flattenFiles($children, (string)$subPath));
			} else {
				if ($path !== '') {
					$file['name'] = $path . '/' . ($file['name'] ?? '');
				}
				$result[] = $file;
			}
		}

		return $result;
	}

	private function buildFilter(): string
	{
		return '<div class="depot-browser-filter">'
			. '<input type="text" placeholder="Filter files...">'
			. '<button type="button" class="depot-browser-filter-reset cms-hide">&times;</button>'
			. '</div>';
	}

	private function buildPreviewDialog(): string
	{
		return '<dialog class="cms-modal depot-browser-preview">'
			. '<div class="preview-content"></div>'
			. '</dialog>';
	}

	private function formatSize(int $bytes): string
	{
		if ($bytes <= 0) {
			return '0 B';
		}

		$sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
		$i     = (int)floor(log($bytes) / log(1024));

		return round($bytes / (1024 ** $i), 1) . ' ' . $sizes[$i];
	}

	private function escape(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}
}
