#!/bin/bash

echo "Building the application..."

# Build frontend assets
bin/build-assets.sh

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

# remove all public dev/test files
rm -rf dist/public/test

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

bin/code-report.sh > code-report.txt

echo "$VERSION ($BUILD)" > dist/version

echo "Build for v$VERSION ($BUILD) is complete."