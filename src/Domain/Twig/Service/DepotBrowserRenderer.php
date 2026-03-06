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
		if ($files === []) {
			return '';
		}

		/** @var array<string> $filterTags */
		$filterTags = is_array($options['filterTags'] ?? null) ? $options['filterTags'] : [];
		if ($filterTags !== []) {
			$files = $this->filterByTags($files, $filterTags);
			if ($files === []) {
				return '';
			}
		}

		$dataOptions = json_encode(array_filter([
			'preview' => $options['preview'],
		]), JSON_THROW_ON_ERROR);

		$content = '';

		if ($options['filter']) {
			$content .= $this->buildFilter();
		}

		if ($options['folders']) {
			$content .= $this->buildFileTree($files, '', $id, $options, $downloadUrl, $streamUrl);
		} else {
			$flat     = $this->flattenFiles($files, '');
			$content .= $this->buildFileTree($flat, '', $id, $options, $downloadUrl, $streamUrl);
		}

		if ($options['preview']) {
			$content .= $this->buildPreviewDialog();
		}

		return HTMLUtils::element('div', $content, [
			'class'         => HTMLUtils::mergeClasses('cms-depot-browser', $options['class']),
			'data-settings' => $dataOptions,
		]);
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
		// Folders first, then files
		$folders = [];
		$items   = [];
		foreach ($files as $file) {
			/** @phpstan-ignore function.alreadyNarrowedType */
			if (!is_array($file)) {
				continue;
			}
			if (($file['mime'] ?? '') === 'folder') {
				$folders[] = $file;
			} else {
				$items[] = $file;
			}
		}

		usort($folders, fn (array $a, array $b): int => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
		usort($items, fn (array $a, array $b): int => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));

		if (!empty($options['reverseSort'])) {
			$folders = array_reverse($folders);
			$items   = array_reverse($items);
		}

		$content = '';
		foreach ($folders as $folder) {
			$content .= $this->buildFolder($folder, $path, $id, $options, $downloadUrl, $streamUrl);
		}
		foreach ($items as $file) {
			$content .= $this->buildFile($file, $path, $id, $options, $downloadUrl, $streamUrl);
		}

		return HTMLUtils::element('ul', $content, ['class' => 'depot-browser-tree']);
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

		$displayName = $options['humanize'] ? $this->humanizeFilename($name) : $name;

		$fileClass = "file file-icon icon-{$ext}";
		$content   = '';

		if ($options['download']) {
			$content .= HTMLUtils::link($this->escape($displayName), $dlUrl, [
				'class'    => $fileClass,
				'download' => $name,
			]);
		} else {
			$content .= HTMLUtils::element('span', $this->escape($displayName), ['class' => $fileClass]);
		}

		// File actions (preview button, size)
		$actions = '';
		if ($options['preview']) {
			$actions .= HTMLUtils::element('button', '', ['type' => 'button', 'class' => 'action-preview', 'title' => 'Preview']);
		}
		if ($size > 0) {
			$actions .= HTMLUtils::element('span', $this->formatSize($size), ['class' => 'file-size']);
		}
		$content .= HTMLUtils::element('span', $actions, ['class' => 'file-actions']);

		// Comments
		if ($options['comments']) {
			$comments = trim((string)($file['comments'] ?? ''));
			if ($comments !== '') {
				$content .= HTMLUtils::element('p', $this->escape($comments), ['class' => 'file-comments']);
			}
		}

		// Tags
		if ($options['tags']) {
			$tags = $file['tags'] ?? [];
			if (is_array($tags) && $tags !== []) {
				$tagHtml = '';
				foreach ($tags as $tag) {
					$tagHtml .= HTMLUtils::element('span', $this->escape((string)$tag));
				}
				$content .= HTMLUtils::element('div', $tagHtml, ['class' => 'file-tags']);
			}
		}

		return HTMLUtils::element('li', $content, [
			'class'           => 'depot-browser-item',
			'data-ext'        => $ext,
			'data-stream-url' => $streamSrc,
		]);
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

		$summary = HTMLUtils::element('summary', $this->escape($name), ['class' => 'folder']);
		$tree    = $this->buildFileTree($children, $subPath, $id, $options, $downloadUrl, $streamUrl);

		return HTMLUtils::element('li', HTMLUtils::element('details', $summary . $tree));
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

	/**
	 * Recursively filter files by tags (OR logic, case-insensitive).
	 *
	 * @param array<int,array<string,mixed>> $files
	 * @param array<string> $tags
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function filterByTags(array $files, array $tags): array
	{
		$normalizedTags = array_map(mb_strtolower(...), $tags);
		$result         = [];

		foreach ($files as $file) {
			/** @phpstan-ignore function.alreadyNarrowedType */
			if (!is_array($file)) {
				continue;
			}

			if (($file['mime'] ?? '') === 'folder') {
				$children = is_array($file['files'] ?? null) ? $file['files'] : [];
				$filtered = $this->filterByTags($children, $tags);
				if ($filtered !== []) {
					$file['files'] = $filtered;
					$result[]      = $file;
				}
				continue;
			}

			$fileTags = $file['tags'] ?? [];
			if (!is_array($fileTags)) {
				continue;
			}
			foreach ($fileTags as $fileTag) {
				if (in_array(mb_strtolower((string)$fileTag), $normalizedTags, true)) {
					$result[] = $file;
					break;
				}
			}
		}

		return $result;
	}

	private function buildFilter(): string
	{
		$input = HTMLUtils::inlineElement('input', ['type' => 'search', 'placeholder' => 'Filter files...']);

		return HTMLUtils::element('div', $input, ['class' => 'depot-browser-filter']);
	}

	private function buildPreviewDialog(): string
	{
		$content = HTMLUtils::element('div', '', ['class' => 'preview-content']);

		return HTMLUtils::dialog($content, 'depot-browser-preview');
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

	private function humanizeFilename(string $filename): string
	{
		// Remove extension
		$name = pathinfo($filename, PATHINFO_FILENAME);
		// Replace dashes, underscores, dots with spaces
		$name = str_replace(['-', '_', '.'], ' ', $name);
		// Collapse multiple spaces
		$name = (string)preg_replace('/\s+/', ' ', $name);

		return mb_convert_case(trim($name), MB_CASE_TITLE);
	}

	private function escape(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}
}
