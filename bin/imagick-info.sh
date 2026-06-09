#!/usr/bin/env bash
#
# imagick-info.sh — dump the full Imagick/ImageMagick/libheif build chain so you
# can diff two servers and tell your admins exactly which library to update.
#
# Usage:
#   bash imagick-info.sh php8.5  [optional/path/to/sample.heic]
#   bash imagick-info.sh php8.4  [optional/path/to/sample.heic]
#
# Run on BOTH servers, save each to a file, and diff:
#   bash imagick-info.sh php8.5 > imagick-8.5.txt
#   bash imagick-info.sh php8.4 > imagick-8.4.txt
#   diff imagick-8.4.txt imagick-8.5.txt
#
# Nothing here uses PHP exec(); the shell commands run in your interactive login.

PHPBIN="${1:-php}"
SAMPLE="${2:-}"

hr() { printf '\n==== %s ====\n' "$1"; }

hr "PHP binary"
command -v "$PHPBIN"
"$PHPBIN" -v | head -1

hr "imagick module info (php --ri imagick)"
# The authoritative record of which ImageMagick version imagick was built against,
# plus the full supported-format list.
"$PHPBIN" --ri imagick 2>&1

hr "Imagick runtime: version + HEIC decode test"
"$PHPBIN" -r '
$v = \Imagick::getVersion();
echo "ImageMagick (via imagick): ", $v["versionString"], "\n";
echo "queryFormats(HEIC): ", json_encode(\Imagick::queryFormats("HEIC")), "\n";
echo "queryFormats(HEIF): ", json_encode(\Imagick::queryFormats("HEIF")), "\n";
$sample = $argv[1] ?? "";
if ($sample !== "" && is_file($sample)) {
    try {
        $im = new \Imagick($sample);
        $im->setImageFormat("jpeg");
        $tmp = sys_get_temp_dir() . "/imagick-info-test.jpg";
        $im->writeImage($tmp);
        echo "Real HEIC decode: OK (", filesize($tmp), " bytes)\n";
        @unlink($tmp);
    } catch (\Throwable $e) {
        echo "Real HEIC decode: FAIL ", get_class($e), ": ", $e->getMessage(), "\n";
    }
} else {
    echo "Real HEIC decode: skipped (pass a .heic path as arg 2 to test)\n";
}
' "$SAMPLE" 2>&1

hr "Shared-library chain: imagick.so -> ImageMagick -> libheif -> libde265"
EXTDIR="$("$PHPBIN" -r 'echo ini_get("extension_dir");' 2>/dev/null)"
IMAGICK_SO="$EXTDIR/imagick.so"
echo "extension_dir: $EXTDIR"
if [ ! -f "$IMAGICK_SO" ]; then
    IMAGICK_SO="$(find / -name 'imagick.so' 2>/dev/null | grep -i "$("$PHPBIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" | head -1)"
fi
echo "imagick.so: ${IMAGICK_SO:-not found}"
if [ -f "$IMAGICK_SO" ]; then
    echo "--- ldd imagick.so (MagickCore/Wand) ---"
    ldd "$IMAGICK_SO" 2>/dev/null | grep -iE 'magick'
    MAGICKCORE="$(ldd "$IMAGICK_SO" 2>/dev/null | grep -iE 'magickcore' | awk '{print $3}' | head -1)"
    if [ -n "$MAGICKCORE" ] && [ -f "$MAGICKCORE" ]; then
        echo "--- ldd $MAGICKCORE (heif) ---"
        ldd "$MAGICKCORE" 2>/dev/null | grep -iE 'heif'
        LIBHEIF="$(ldd "$MAGICKCORE" 2>/dev/null | grep -iE 'heif' | awk '{print $3}' | head -1)"
        if [ -n "$LIBHEIF" ] && [ -f "$LIBHEIF" ]; then
            echo "--- ldd $LIBHEIF (de265/decoders) ---"
            ldd "$LIBHEIF" 2>/dev/null | grep -iE 'de265|aom|x265|kvazaar|dav1d'
        fi
    fi
fi

hr "ldconfig: heif / de265 / hevc libraries known to the linker"
ldconfig -p 2>/dev/null | grep -iE 'heif|de265|hevc|aom|x265'

hr "libheif decoder PLUGINS on disk (libheif 1.16+ loads these dynamically)"
ls -la /usr/lib/*/libheif/ 2>/dev/null
ls -la /usr/lib64/libheif/ 2>/dev/null
ls -la /usr/local/lib/libheif/ 2>/dev/null
echo "(empty above = no plugins dir; decoder may be linked directly instead)"

hr "Installed packages (imagemagick / heif / de265)"
if command -v dpkg >/dev/null 2>&1; then
    dpkg -l 2>/dev/null | grep -iE 'imagemagick|magickcore|magickwand|libheif|heif-plugin|de265|libaom|x265' | awk '{print $1, $2, $3}'
elif command -v rpm >/dev/null 2>&1; then
    rpm -qa 2>/dev/null | grep -iE 'ImageMagick|heif|de265|aom|x265'
else
    echo "(no dpkg/rpm found)"
fi

hr "ImageMagick CLI build delegates (DELEGATES line should list heic + a HEVC decoder)"
if command -v magick >/dev/null 2>&1; then
    magick -version 2>&1 | grep -iE 'Version|Delegates'
    echo "--- magick -list format | heic ---"
    magick -list format 2>/dev/null | grep -i heic
elif command -v convert >/dev/null 2>&1; then
    convert -version 2>&1 | grep -iE 'Version|Delegates'
    convert -list format 2>/dev/null | grep -i heic
else
    echo "(no magick/convert CLI on PATH)"
fi

hr "Done"
echo "Hand imagick-8.4.txt and imagick-8.5.txt to your admins. The line that"
echo "differs (libde265 present in the ldd/ldconfig/plugins/packages output on"
echo "8.4 but absent on 8.5) is exactly what they need to install."
