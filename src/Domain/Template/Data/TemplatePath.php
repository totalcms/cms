<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Data;

final class TemplatePath
{
	/**
	 * The builder category every user template lives in.
	 *
	 * One of TemplateRepository::BUILDER_CATEGORIES. The 3.5 migration moved
	 * `tcms-data/templates/*` here (see TemplateMigrationService), so this is
	 * where the admin UI, the Template Designer, and `cms.render.*` all agree
	 * a template is found — no exceptions.
	 */
	public const TEMPLATES_FOLDER = 'templates';

	/**
	 * Split a template path into folder and template name on the last slash.
	 * A path with no slash is treated as a bare template name with no folder.
	 *
	 * @return array{0: string|null, 1: string}
	 */
	public static function parse(string $path): array
	{
		$lastSlash = strrpos($path, '/');

		if ($lastSlash === false) {
			return [null, $path];
		}

		return [
			substr($path, 0, $lastSlash),
			substr($path, $lastSlash + 1),
		];
	}

	/**
	 * Split a user template path into folder + name, rooted at the `templates`
	 * builder category.
	 *
	 * Every surface that takes a template id from a site author resolves it
	 * through here, so they cannot disagree about where a template lives:
	 *
	 *   {% templatedesigner for 'myblog' %}          (local save)
	 *   PUT /api/designer/templates/myblog           (remote save)
	 *   cms.render.loadMore('blog', {template: 'myblog'})   (first page)
	 *   /api/.../query?template=myblog               (load-more pages 2+)
	 *
	 * All four address `builder/templates/myblog.twig`.
	 *
	 * Getting this wrong is silent rather than loud: the local half of the
	 * Designer sync happily CREATES a template at whatever path it computes
	 * (reporting a green "Local: ✓") while the remote half only ever UPDATES
	 * an existing one. Two different answers meant a stray file on one side
	 * and "Template not found" on the other.
	 *
	 * A redundant leading `templates/` is stripped, so ids written against the
	 * pre-3.5 layout — and against the builder-relative form — resolve to the
	 * same file.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function parseInTemplates(string $path): array
	{
		$path = ltrim($path, '/');

		// Strip a redundant category prefix — `templates/myblog` and `myblog`
		// address the same file. Requires the trailing slash, so a template
		// legitimately named `templates` is left alone.
		if (str_starts_with($path, self::TEMPLATES_FOLDER . '/')) {
			$path = substr($path, strlen(self::TEMPLATES_FOLDER) + 1);
		}

		[$folder, $name] = self::parse($path);

		return [
			$folder === null || $folder === ''
				? self::TEMPLATES_FOLDER
				: self::TEMPLATES_FOLDER . '/' . $folder,
			$name,
		];
	}

	/**
	 * Builder-relative path a user template id resolves to, ready for the Twig
	 * loader (whose roots are the builder read layers).
	 *
	 * `myblog` and `myblog.twig` both yield `templates/myblog.twig`.
	 */
	public static function loaderPath(string $path): string
	{
		if (str_ends_with($path, '.twig')) {
			$path = substr($path, 0, -strlen('.twig'));
		}

		[$folder, $name] = self::parseInTemplates($path);

		return $folder . '/' . $name . '.twig';
	}
}
