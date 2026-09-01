#!/bin/bash

echo "Building the application..."

# Parse arguments
# --release: Official release build (production assets, preserves version.txt)
# No flags: Beta/dev build (dev assets, generates version from git)
RELEASE=0

for arg in "$@"; do
    if [ "$arg" = "--release" ] || [ "$arg" = "--production" ]; then
        RELEASE=1
    fi
done

# Update git submodules (locale translations)
echo "Updating locale translations..."
git submodule update --init --remote vendor-locales/cakephp-localized

# Build frontend assets
if [ $RELEASE -eq 1 ]; then
    # Release build: production mode (no sourcemaps)
    bin/build-assets.sh --release
else
    # Beta/dev build: development mode (with sourcemaps)
    bin/build-assets.sh
fi

if [ $? -ne 0 ]; then
    echo "Failed to build frontend assets. Exiting..."
    exit 1
fi

# The documentation indexes need BOTH vendor/autoload.php and a dev dependency
# (nikic/php-parser, used to parse src/), so they can only be built between a
# full install and the --no-dev install that produces the shipped tree.
#
# Running them after --no-dev fatals with "Class PhpParser\ParserFactory not
# found"; running them before any install fatals on a missing autoload.php,
# which is invisible on a dev machine with a populated vendor/ and fails
# immediately on a fresh CI checkout. Hence the explicit dev install here.
echo "Installing dependencies..."
composer install --quiet

if [ $? -ne 0 ]; then
    echo "Failed to install dependencies. Exiting..."
    exit 1
fi

# Before the dist copy, so the release ships the ranges as they stood at build
# time. Never fails the build: if cloudflare.com is unreachable the committed
# ranges are kept, because yesterday's correct list beats today's empty one.
echo "Updating Cloudflare IP ranges..."
CLOUDFLARE_IPS_STALE=""
php bin/update-cloudflare-ips.php
if [ $? -eq 2 ]; then
    # Not fatal — the committed ranges still ship, and they are almost certainly
    # still correct. But it must not scroll past: if this keeps failing the list
    # rots silently and every Cloudflare site's rate limiting degrades.
    CLOUDFLARE_IPS_STALE="yes"
fi

echo "Building documentation search index..."
php bin/build-docs-index.php

if [ $? -ne 0 ]; then
    echo "Failed to build documentation indexes. Exiting..."
    exit 1
fi

echo "Building the application..."
composer install --no-dev --optimize-autoloader --quiet

if [ $? -ne 0 ]; then
    echo "Failed to build Total CMS application. Exiting..."
    exit 1
fi

# remove imagine libs that are not required and take up too much space
# find vendor -not -name '*.php' -not -name '*.pem' -not -name '*.json' -not -name '*.xsl' -type f -delete
# find vendor -name "*phpstorm*" -delete
# find vendor -empty -type d -delete
# find vendor -name bin -type d | xargs rm -rf
# find vendor -name test -type d | xargs rm -rf

# Enhanced cleanup for smaller distribution
# echo "Performing enhanced vendor cleanup..."
# find vendor -name "tests" -type d -exec rm -rf {} + 2>/dev/null
# find vendor -name "Tests" -type d -exec rm -rf {} + 2>/dev/null
# # Only remove PHPUnit test files, not core library classes
# find vendor -path "*/tests/*" -name "*.php" -type f -delete 2>/dev/null
# find vendor -path "*/Tests/*" -name "*.php" -type f -delete 2>/dev/null
# find vendor -name "phpunit*" -type f -delete
# find vendor -name "docs" -type d -exec rm -rf {} + 2>/dev/null
# find vendor -name "doc" -type d -exec rm -rf {} + 2>/dev/null
# find vendor -name "examples" -type d -exec rm -rf {} + 2>/dev/null
# find vendor -name "demo" -type d -exec rm -rf {} + 2>/dev/null
# find vendor -name "samples" -type d -exec rm -rf {} + 2>/dev/null
# find vendor -name ".php-cs-fixer*" -delete
# find vendor -name ".cs.php" -delete
# find vendor -name "phpcs.xml*" -delete
# find vendor -name "phpunit.xml*" -delete
# find vendor -name "rector.php" -delete
# find vendor -name "psalm.xml*" -delete
# find vendor -name "phpstan.neon*" -delete
# find vendor -name ".github" -type d -exec rm -rf {} + 2>/dev/null
# find vendor -name ".circleci" -type d -exec rm -rf {} + 2>/dev/null
# find vendor -name ".travis.yml" -delete
# find vendor -name ".scrutinizer.yml" -delete
# find vendor -name "CHANGELOG*" -delete
# find vendor -name "CHANGES*" -delete
# find vendor -name "UPGRADE*" -delete
# find vendor -name "HISTORY*" -delete
# find vendor -name "NEWS*" -delete
# find vendor -name "composer.lock" -delete
# find vendor -name "composer.json" -delete
# find vendor -name ".gitignore" -delete
# find vendor -name ".gitattributes" -delete
# find vendor -name ".editorconfig" -delete
# find vendor -empty -type d -delete 2>/dev/null

# Trim symfony/intl locale data to supported locales only
# Only keep exact base locales and specific sub-locales from settings/general.json
echo "Trimming symfony/intl to supported locales..."
INTL_DATA="vendor/symfony/intl/Resources/data"
if [ -d "$INTL_DATA" ]; then
    # Base locales + exact sub-locales from settings/general.json + meta files
    INTL_KEEP="^(ar|cs|da|de|en|es|fr|hu|it|ja|km|nl|no|pl|pt|ru|tr|uk|vi|zh|meta|en_US|en_GB|en_CA|en_AU|en_SG|ar_SA|cs_CZ|da_DK|de_DE|es_ES|es_MX|fr_FR|fr_CA|hu_HU|it_IT|ja_JP|km_KH|nl_NL|no_NO|pl_PL|pt_BR|pt_PT|ru_RU|tr_TR|uk_UA|vi_VN|zh_CN)\."
    for subdir in currencies languages locales regions scripts timezones; do
        if [ -d "$INTL_DATA/$subdir" ]; then
            for file in "$INTL_DATA/$subdir"/*.php; do
                filename=$(basename "$file")
                if ! echo "$filename" | grep -qE "$INTL_KEEP"; then
                    rm -f "$file"
                fi
            done
        fi
    done
fi

# Trim fakerphp/faker to supported locales only
echo "Trimming Faker to supported locales..."
FAKER_PROVIDERS="vendor/fakerphp/faker/src/Faker/Provider"
if [ -d "$FAKER_PROVIDERS" ]; then
    FAKER_KEEP="en_US|en_GB|en_CA|en_AU|en_SG|ar_SA|cs_CZ|da_DK|de_DE|es_ES|es_MX|fr_FR|fr_CA|hu_HU|it_IT|ja_JP|km_KH|nl_NL|no_NO|nb_NO|pl_PL|pt_BR|pt_PT|ru_RU|tr_TR|uk_UA|vi_VN|zh_CN"
    for dir in "$FAKER_PROVIDERS"/*/; do
        dirname=$(basename "$dir")
        if ! echo "$dirname" | grep -qE "^($FAKER_KEEP)$"; then
            rm -rf "$dir"
        fi
    done
fi

# Remove unused vendor packages
echo "Removing unused vendor packages..."
rm -rf vendor/ssnepenthe/color-utils

# move required files to dist
echo "Moving required files to dist..."
rm -rf dist
mkdir dist
cp -r config public resources src vendor autoload.php .htaccess dist

# copy distribution gitignore as .gitignore
cp .gitignore-dist dist/.gitignore

# remove all public dev/test files
rm -rf dist/public/test
rm -rf dist/public/false

# install all required composer packages for dev environment
echo "Installing all required composer packages back for dev environment..."
composer install --quiet

# Ensure these do not get shipped
rm -f dist/resources/.bundle
rm -f dist/resources/jobqueue
rm -f dist/resources/bin/.processJobs
rm -f dist/config/local.dev.php
rm -f dist/config/local.test.php

# Generate bundle hash against the final dist (after all cleanup)
echo "Generating bundle integrity hash..."
php bin/make-bundle.php dist

# remove write permissions from all files
find dist/resources -type f -exec chmod 444 {} +
chmod +x dist/resources/bin/tcms

# Handle version.json
if [ $RELEASE -eq 1 ]; then
    # Official release: use existing version.json (from prepare-release.sh)
    if [ -f "version.json" ]; then
        cp version.json dist
        VERSION=$(php -r "echo json_decode(file_get_contents('version.json'))->version;")
        BUILD=$(php -r "echo json_decode(file_get_contents('version.json'))->build;")
        echo "Official release build using version: $VERSION-$BUILD"
    else
        echo "ERROR: --release flag used but version.json does not exist"
        echo "Run prepare-release.sh first to create an official release"
        exit 1
    fi
else
    # Beta/dev build: generate version from git
    # git describe --long gives: v3.2.1-4-gbd6cf78 (tag-commits-ghash)
    DESCRIBE=$(git describe --tags --long 2>/dev/null)
    if [ -n "$DESCRIBE" ]; then
        VERSION=$(echo "$DESCRIBE" | sed 's/^v//' | sed 's/-[0-9]*-g[a-f0-9]*$//')
        COMMITS=$(echo "$DESCRIBE" | sed 's/.*-\([0-9]*\)-g[a-f0-9]*$/\1/')
        BUILD=$(echo "$DESCRIBE" | sed 's/.*-g//')
    else
        # Fallback if no tags exist
        VERSION="0.0.0"
        COMMITS="0"
        BUILD=$(git rev-parse --short HEAD)
    fi
    if ! php bin/generate-version.php "$VERSION" "$BUILD" version.json "$COMMITS"; then
        echo "ERROR: Failed to generate version.json"
        exit 1
    fi
    cp version.json dist
    echo "Beta build for v$VERSION ($BUILD) is complete."
fi

if [ -n "$CLOUDFLARE_IPS_STALE" ]; then
    echo ""
    echo "############################################################"
    echo "# WARNING: Cloudflare IP ranges were NOT refreshed"
    echo "#"
    echo "# cloudflare.com could not be reached, or returned something"
    echo "# unexpected. This build ships the previously committed"
    echo "# ranges, which are probably still correct — but if this"
    echo "# keeps happening the list will rot, and every site behind"
    echo "# Cloudflare will quietly collapse into one rate-limit"
    echo "# bucket."
    echo "#"
    echo "# Check https://www.cloudflare.com/ips-v4 and re-run:"
    echo "#   php bin/update-cloudflare-ips.php"
    echo "############################################################"
    echo ""
fi
