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
