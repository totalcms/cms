#!/bin/bash

composer install --no-dev --optimize-autoloader

# remove imagine libs that are not required and take up too much space
find vendor -not -name '*php' -not -name '*pem' -type f -delete
find vendor -name "*phpstorm*" -delete
find vendor -empty -type d -delete
find vendor -name bin -type d | xargs rm -rf
find vendor -name test -type d | xargs rm -rf