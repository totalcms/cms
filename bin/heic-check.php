<?php

declare(strict_types=1);

/**
 * HEIC capability diagnostic — run on each server with the matching PHP binary:
 *
 *   php8.4 bin/heic-check.php
 *   php8.5 bin/heic-check.php
 *
 * It mirrors exactly what TotalCMS\Domain\Media\Service\HeicConverter probes,
 * so the OK/FAIL lines tell you which branch each server takes and why one
 * converts HEIC while the other does not.
 */
function line(string $label, string $value): void
{
	printf("%-34s %s\n", $label . ':', $value);
}

function yn(bool $b): string
{
	return $b ? 'YES' : 'no';
}

echo "==== HEIC capability diagnostic ====\n";
line('PHP version', PHP_VERSION);
line('PHP SAPI', PHP_SAPI);
line('php.ini', php_ini_loaded_file() ?: '(none)');

echo "\n-- Imagick PHP extension (preferred path) --\n";
$imagickLoaded = extension_loaded('imagick');
line('extension_loaded("imagick")', yn($imagickLoaded));

if ($imagickLoaded) {
	$ver = Imagick::getVersion();
	line('ImageMagick (via extension)', (string)($ver['versionString'] ?? 'unknown'));

	$heic = Imagick::queryFormats('HEIC');
	$heif = Imagick::queryFormats('HEIF');
	line('queryFormats("HEIC")', $heic === [] ? '(empty)' : implode(', ', $heic));
	line('queryFormats("HEIF")', $heif === [] ? '(empty)' : implode(', ', $heif));

	$extensionWillWork = $heic !== [];
	line('>> extension path usable', yn($extensionWillWork));
} else {
	echo "  imagick extension NOT loaded for this PHP — this is the usual\n";
	echo "  8.4-works / 8.5-fails cause. The PECL extension is built per PHP\n";
	echo "  version; a new PHP often ships before imagick is rebuilt for it.\n";
}

echo "\n-- ImageMagick CLI (fallback path) --\n";
$execAvailable = function_exists('exec');
line('function_exists("exec")', yn($execAvailable));
$disabled = (string)ini_get('disable_functions');
line('exec in disable_functions', yn(str_contains($disabled, 'exec')));

if ($execAvailable) {
	foreach (['magick', 'convert'] as $bin) {
		$out = [];
		$rc  = 0;
		@exec('command -v ' . escapeshellarg($bin) . ' 2>&1', $out, $rc);
		line("which {$bin}", $rc === 0 ? trim(implode(' ', $out)) : '(not found)');
	}
	$verOut = [];
	@exec('magick -version 2>&1 || convert -version 2>&1', $verOut);
	$verStr  = implode("\n", $verOut);
	$cliHeic = stripos($verStr, 'heic') !== false || stripos($verStr, 'heif') !== false;
	echo "\n  CLI -version delegates line:\n";
	foreach ($verOut as $l) {
		if (stripos($l, 'Delegates') !== false || stripos($l, 'Version') !== false) {
			echo '    ' . $l . "\n";
		}
	}
	line('CLI reports heic/heif delegate', yn($cliHeic));
} else {
	echo "  exec() unavailable — CLI fallback cannot run on this server.\n";
}

echo "\n-- Verdict --\n";
$canExtension = $imagickLoaded && Imagick::queryFormats('HEIC') !== [];
$canCli       = $execAvailable; // optimistic; the -version check above shows the delegate
if ($canExtension) {
	echo "  HEIC conversion WILL use the Imagick extension. ✅\n";
} elseif ($canCli) {
	echo "  Imagick extension path unavailable; will TRY the CLI fallback.\n";
	echo "  Confirm the 'heic/heif delegate' line above says YES.\n";
} else {
	echo "  No conversion path available — HEIC uploads will fail here. ❌\n";
	echo "  Fix: enable the imagick extension for this PHP version, or enable\n";
	echo "  exec() with magick/convert in PATH (and a libheif delegate).\n";
}
