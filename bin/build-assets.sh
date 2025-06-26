#!/bin/bash

# echo "Building Twig Templates..."
# cat templates/totalform/* > templates/totalform.twig
# cat templates/content/* > templates/content.twig

echo "Building frontend assets..."
yarn install
node esbuild.config.js
cp -r resources/fonts public/assets/fonts

if [ $? -ne 0 ]; then
    echo "Failed to build frontend assets."
    exit 1
fi
