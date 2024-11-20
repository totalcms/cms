<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

final class DepotField extends FormField
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

		$browser = HTMLUtils::element('ul',
			$this->buildFolder($depot['files'] ?? []),
			['class' => 'depot-browser']
		);
		return $browser;
	}

	/** @param array<array<string,mixed>> $files */
	private function buildFolder(array $files): string
	{
		$content = '';
		$files   = self::sortFiles($files);

		foreach ($files as $file) {
			if ($file['mime'] === 'folder') {
				$folderFiles = HTMLUtils::element('ul',
					$this->buildFolder($file['files'] ?? []),
					['class' => 'folder-contents']
				);
				$summary = HTMLUtils::element('summary', $file['name']);
				$details = HTMLUtils::element('details', $summary . $folderFiles);
				$content .= HTMLUtils::element('li', $details, ['class' => 'folder']);
				continue;
			}
			$content .= $this->buildFile($file);
		}

		return $content;
	}

	/** @param array<string,mixed> $file */
	private function buildFile(array $file): string
	{
		$content = HTMLUtils::element('li', $file["name"] ?? "Unknown", ['class' => 'file']);
		return $content;
	}

	/**
	 * @param array<array<string,mixed>> $files
	 *
	 * @return array<array<string,mixed>>
	 */
	private static function sortFiles(array $files): array
	{
		// Sort folders first, then files by name
		usort($files, function ($a, $b) {
			if ($a['mime'] === 'folder' && $b['mime'] !== 'folder') {
				return -1;
			} elseif ($a['mime'] !== 'folder' && $b['mime'] === 'folder') {
				return 1;
			}
			return strcmp($a['name'], $b['name']);
		});

		return $files;
	}

}

/**
<ul class="depot-browser">

    <li>
        <details>
            <summary>myfolder 2</summary>
            <ul>

				<li>file.txt</li>
                <li>file2.txt</li>
                <li>
                    <details>
                        <summary>mysub</summary>
                        <ul>
                            <li>file.txt</li>
                            <li>file2.txt</li>
                        </ul>
                    </details>
                </li>

            </ul>
        </details>
    </li>

	<li>
        <details>
            <summary>myfolder</summary>
            <ul>
                <li>file.txt</li>
                <li>file2.txt</li>
                <li>
                    <details>
                        <summary>mysub</summary>
                        <ul>
                            <li>file.txt</li>
                            <li>file2.txt</li>
                        </ul>
                    </details>
                </li>

            </ul>
        </details>
    </li>
    <li>file.txt</li>
    <li>file2.txt</li>
</ul>
*/