<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Starter\Service;

use TotalCMS\Domain\Starter\Data\StarterExportOptions;
use TotalCMS\Domain\Starter\Data\StarterExportResult;

/**
 * The project files that turn an exported site into a Composer starter.
 *
 * composer.json is the site's own, renamed, with the totalcms/cms constraint
 * set to the running minor and the post-install script pointed at the
 * starter's. config/tcms.php is always replaced with a blank one: it can hold
 * credentials and paths for the author's machine, so the keys it set are
 * reported instead for the author to move into settings. The README and
 * .gitignore come from resources/starter/; a README the author wrote
 * survives a re-export. CREDITS.md is built from the image credits
 * ({@see StarterCredits}) unless the author wrote their own.
 */
final readonly class StarterProjectWriter
{
	/** The first line of the plain Total CMS skeleton's README. */
	private const SKELETON_README = 'This is the project skeleton for [Total CMS]';

	public function __construct(
		private string $templatesDir,
		private string $cmsVersion,
	) {
	}

	public function write(StarterExportOptions $options, string $projectRoot, string $dest, StarterExportResult $result): void
	{
		$this->writeComposerJson($options, $projectRoot, $dest);

		$result->configKeys = $this->configKeys($projectRoot . '/config/tcms.php');
		$this->ensureDir($dest . '/config');
		copy($this->templatesDir . '/tcms.php', $dest . '/config/tcms.php');

		$this->ensureDir($dest . '/bin');
		file_put_contents($dest . '/bin/post-install.php', $this->render('post-install.php', $options));
		file_put_contents($dest . '/.gitignore', $this->render('gitignore', $options));

		if ($this->isSkeletonReadme($dest . '/README.md')) {
			file_put_contents($dest . '/README.md', $this->render('README.md', $options));
		}

		$credits                  = (new StarterCredits())->collect($dest . '/tcms-data');
		$result->creditedImages   = $credits->count();
		$result->uncreditedImages = $credits->uncredited();
		if ($this->isGeneratedCredits($dest . '/CREDITS.md')) {
			file_put_contents($dest . '/CREDITS.md', $credits->markdown());
		}
	}

	private function writeComposerJson(StarterExportOptions $options, string $projectRoot, string $dest): void
	{
		$composer = json_decode((string)@file_get_contents($projectRoot . '/composer.json'), true);
		$composer = is_array($composer) ? $composer : [];

		$composer['name']        = $options->name;
		$composer['description'] = $options->description !== '' ? $options->description : ($composer['description'] ?? '');
		$composer['type']        = 'project';

		$keywords             = is_array($composer['keywords'] ?? null) ? $composer['keywords'] : [];
		$composer['keywords'] = array_values(array_unique([...$keywords, 'totalcms-starter']));

		$composer['require']                 = is_array($composer['require'] ?? null) ? $composer['require'] : [];
		$composer['require']['totalcms/cms'] = $this->cmsConstraint($composer['require']['totalcms/cms'] ?? '^3.6');

		$composer['scripts']                            = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
		$composer['scripts']['post-create-project-cmd'] = ['@php bin/post-install.php'];

		$json = (string)json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		// The skeleton indents its composer.json with tabs; keep the starter's the same.
		$json = (string)preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat("\t", intdiv(strlen($m[0]), 4)), $json);
		file_put_contents($dest . '/composer.json', $json . "\n");
	}

	/** `^major.minor` of the running CMS, or the site's own constraint when the version is unknown (a dev checkout). */
	private function cmsConstraint(mixed $current): string
	{
		if (preg_match('/^(\d+)\.(\d+)/', $this->cmsVersion, $m) === 1) {
			return "^{$m[1]}.{$m[2]}";
		}

		return is_string($current) ? $current : '^3.6';
	}

	/**
	 * The top-level keys a config file sets. The file is the author's own,
	 * loaded the same way the app loads it.
	 *
	 * @return list<string>
	 */
	private function configKeys(string $file): array
	{
		if (!is_file($file)) {
			return [];
		}

		try {
			$config = (static fn (string $path): mixed => include $path)($file);
		} catch (\Throwable) {
			return [];
		}

		return is_array($config) ? array_map(strval(...), array_keys($config)) : [];
	}

	private function isSkeletonReadme(string $path): bool
	{
		return !is_file($path) || str_contains((string)file_get_contents($path), self::SKELETON_README);
	}

	/** Missing, or one a previous export wrote: either way ours to (re)write. */
	private function isGeneratedCredits(string $path): bool
	{
		return !is_file($path) || str_contains((string)file_get_contents($path), StarterCredits::MARKER);
	}

	private function render(string $template, StarterExportOptions $options): string
	{
		return strtr((string)file_get_contents($this->templatesDir . '/' . $template), [
			'{{name}}'        => $options->name,
			'{{description}}' => $options->description,
		]);
	}

	private function ensureDir(string $dir): void
	{
		if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
			throw new \RuntimeException("Could not create {$dir}.");
		}
	}
}
