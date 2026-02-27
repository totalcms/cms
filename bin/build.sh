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

echo "Building the application..."
composer install --no-dev --optimize-autoloader --quiet

if [ $? -ne 0 ]; then
    echo "Failed to build Total CMS application. Exiting..."
    exit 1
fi

# remove imagine libs that are not required and take up too much space
find vendor -not -name '*.php' -not -name '*.pem' -not -name '*.json' -not -name '*.xsl' -type f -delete
find vendor -name "*phpstorm*" -delete
find vendor -empty -type d -delete
find vendor -name bin -type d | xargs rm -rf
find vendor -name test -type d | xargs rm -rf

# Enhanced cleanup for smaller distribution
echo "Performing enhanced vendor cleanup..."
find vendor -name "tests" -type d -exec rm -rf {} + 2>/dev/null
find vendor -name "Tests" -type d -exec rm -rf {} + 2>/dev/null
# Only remove PHPUnit test files, not core library classes
find vendor -path "*/tests/*" -name "*.php" -type f -delete 2>/dev/null
find vendor -path "*/Tests/*" -name "*.php" -type f -delete 2>/dev/null
find vendor -name "phpunit*" -type f -delete
find vendor -name "docs" -type d -exec rm -rf {} + 2>/dev/null
find vendor -name "doc" -type d -exec rm -rf {} + 2>/dev/null
find vendor -name "examples" -type d -exec rm -rf {} + 2>/dev/null
find vendor -name "demo" -type d -exec rm -rf {} + 2>/dev/null
find vendor -name "samples" -type d -exec rm -rf {} + 2>/dev/null
find vendor -name ".php-cs-fixer*" -delete
find vendor -name ".cs.php" -delete
find vendor -name "phpcs.xml*" -delete
find vendor -name "phpunit.xml*" -delete
find vendor -name "rector.php" -delete
find vendor -name "psalm.xml*" -delete
find vendor -name "phpstan.neon*" -delete
find vendor -name ".github" -type d -exec rm -rf {} + 2>/dev/null
find vendor -name ".circleci" -type d -exec rm -rf {} + 2>/dev/null
find vendor -name ".travis.yml" -delete
find vendor -name ".scrutinizer.yml" -delete
find vendor -name "CHANGELOG*" -delete
find vendor -name "CHANGES*" -delete
find vendor -name "UPGRADE*" -delete
find vendor -name "HISTORY*" -delete
find vendor -name "NEWS*" -delete
find vendor -name "composer.lock" -delete
find vendor -name ".gitignore" -delete
find vendor -name ".gitattributes" -delete
find vendor -name ".editorconfig" -delete
find vendor -empty -type d -delete 2>/dev/null

# Trim symfony/intl locale data to supported languages only (~1300 -> ~120 files)
echo "Trimming symfony/intl to supported locales..."
INTL_DATA="vendor/symfony/intl/Resources/data"
if [ -d "$INTL_DATA" ]; then
    # Locales to keep (matching settings/general.json locale options)
    # ar=Arabic, cs=Czech, da=Danish, de=German, en=English, es=Spanish,
    # fr=French, hu=Hungarian, it=Italian, ja=Japanese, km=Khmer, nl=Dutch, no=Norwegian,
    # pl=Polish, pt=Portuguese, ru=Russian, tr=Turkish, uk=Ukrainian, vi=Vietnamese, zh=Chinese
    KEEP_PATTERN="^(ar|cs|da|de|en|es|fr|hu|it|ja|km|nl|no|pl|pt|ru|tr|uk|vi|zh|meta)"
    for subdir in currencies languages locales regions scripts timezones; do
        if [ -d "$INTL_DATA/$subdir" ]; then
            for file in "$INTL_DATA/$subdir"/*.php; do
                filename=$(basename "$file")
                if ! echo "$filename" | grep -qE "$KEEP_PATTERN"; then
                    rm -f "$file"
                fi
            done
        fi
    done
fi

# generate documentation search index
echo "Building documentation search index..."
php bin/build-docs-index.php

# generate bundle to verify installation
php bin/make-bundle.php

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

# remove write permissions from all files
find dist/resources -type f -exec chmod 444 {} +

# Ensure this does not get shipped
rm -f dist/resources/.bundle
rm -f dist/resources/jobqueue
rm -f dist/resources/bin/.processJobs

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
    VERSION=$(git describe --tags $(git rev-list --tags --max-count=1))
    BUILD=$(git rev-parse --short HEAD)
    php bin/generate-version.php "$VERSION" "$BUILD"
    cp version.json dist
    echo "Beta build for v$VERSION ($BUILD) is complete."
fi