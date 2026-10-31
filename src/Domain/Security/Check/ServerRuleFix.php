<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;

/** Server-specific wording for the fix text of the file exposure checks. */
final class ServerRuleFix
{
	/** $shipsHtaccess: the folder comes with a deny-all .htaccess (only the data folder does). */
	public static function denyFolder(SecurityContext $context, string $urlPath, bool $shipsHtaccess = false): string
	{
		$path = '/' . trim($urlPath, '/');

		if ($context->isApache()) {
			return $shipsHtaccess
				? sprintf('Block web access to %s. On Apache, keep the .htaccess file that ships in that folder in place and make sure AllowOverride is enabled for this site.', $path)
				: sprintf('Block web access to %s. On Apache, add a .htaccess file with `Require all denied` to that folder (AllowOverride must be enabled for this site), or move it outside the web folder.', $path);
		}

		return sprintf(
			".htaccess files are ignored on this server, so the folder is not protected. Add a rule to your Nginx config and reload:\n\nlocation ^~ %s/ {\n\tdeny all;\n\treturn 404;\n}",
			$path,
		);
	}

	public static function denyFile(SecurityContext $context, string $urlPath): string
	{
		$path = '/' . ltrim($urlPath, '/');

		if ($context->isApache()) {
			return sprintf('Block web access to %s, or move it above the docroot. On Apache, add a <Files> deny rule to .htaccess.', $path);
		}

		return sprintf(
			".htaccess files are ignored on this server. Add a rule to your Nginx config and reload:\n\nlocation = %s {\n\tdeny all;\n\treturn 404;\n}",
			$path,
		);
	}

	public static function noListing(SecurityContext $context): string
	{
		if ($context->isApache()) {
			return 'Turn off directory listing by adding "Options -Indexes" to your .htaccess file.';
		}

		return ".htaccess files are ignored on this server. Turn off directory listing in your Nginx config (autoindex off;) and reload.";
	}
}
