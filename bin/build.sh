#!/bin/bash

echo "Building the application..."

PRODUCTION=0
for arg in "$@"; do
    if [ "$arg" = "--production" ]; then
		# Build frontend assets (production mode = no sourcemaps)
		bin/build-assets.sh --production
	else
		# Build frontend assets (development mode = with sourcemaps)
		bin/build-assets.sh
    fi
done

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
find vendor -not -name '*php' -not -name '*pem' -type f -delete
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

# generate bundle to verify installation
php bin/make-bundle.php

# generate beta expiration date
EXPIRE=`date -v +45d +"%Y-%m-%d"`
echo "Beta expiration date: $EXPIRE"
echo $EXPIRE | base64 > resources/beta

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

# Most recent version shipped. Would be nice to have the version bump automated.
#git describe --tags `git rev-list --tags --max-count=1`

VERSION=`git describe --tags $(git rev-list --tags --max-count=1)`
BUILD=`git rev-parse --short HEAD`

echo "$VERSION-$BUILD" > version.txt
cp version.txt dist

echo "Build for v$VERSION ($BUILD) is complete."