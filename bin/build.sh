#!/bin/bash

yarn build

composer install --no-dev --optimize-autoloader

# remove imagine libs that are not required and take up too much space
find vendor -not -name '*php' -not -name '*pem' -type f -delete
find vendor -name "*phpstorm*" -delete
find vendor -empty -type d -delete
find vendor -name bin -type d | xargs rm -rf
find vendor -name test -type d | xargs rm -rf

# move required files to dist
rm -rf dist
mkdir dist
cp -r config public resources schemas src templates vendor autoload.php dist

# install all required composer packages for dev environment
composer install
