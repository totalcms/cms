<?php

/**
 * Runs once, after `composer create-project` installs this starter.
 *
 * A starter arrives complete: templates, content and settings are already in
 * place, so there is nothing to choose. This prints where to go next and then
 * deletes itself, the same as the plain Total CMS skeleton's installer.
 *
 * Written by `tcms starter:export`.
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

echo "\n";
echo "  {{name}} is installed in {$projectRoot}\n";
echo "\n";
echo "  1. Point your web server's document root at public/\n";
echo "  2. Visit the site. The setup wizard creates your admin account.\n";
echo "  3. Read the walkthrough page, if this starter has one, at /readme\n";
echo "\n";

@unlink(__FILE__);
