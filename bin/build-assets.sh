#!/bin/bash

# echo "Building Twig Templates..."
# cat templates/totalform/* > templates/totalform.twig
# cat templates/content/* > templates/content.twig

# Check for --release or --production flag
PRODUCTION=0
for arg in "$@"; do
    if [ "$arg" = "--release" ] || [ "$arg" = "--production" ]; then
        PRODUCTION=1
    fi
done

echo "Building frontend assets..."
yarn install
PRODUCTION=$PRODUCTION node esbuild.config.js
cp -r resources/fonts public/assets/fonts

if [ $? -ne 0 ]; then
    echo "Failed to build frontend assets."
    exit 1
fi
