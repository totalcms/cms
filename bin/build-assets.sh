#!/bin/bash

echo "Building Twig Templates..."
cat templates/totalform/* > templates/totalform.twig

echo "Building frontend assets..."
node esbuild.config.js

if [ $? -ne 0 ]; then
    echo "Failed to build frontend assets."
    exit 1
fi
