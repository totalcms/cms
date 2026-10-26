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
vp install
# Copy the folder's contents, not the folder: `cp -r resources/fonts
# public/assets/fonts` nests a second fonts/ inside when the target already
# exists (two overlapping builds did exactly that).
if ! PRODUCTION=$PRODUCTION node esbuild.config.js \
    || ! mkdir -p public/assets/fonts \
    || ! cp -R resources/fonts/. public/assets/fonts/; then
    echo "Failed to build frontend assets."
    exit 1
fi
