#!/bin/bash

bin/build-assets.sh
php bin/make-bundle.php

echo "Watching..."
echo ""

DEBOUNCE=2
ignore=("totalform.twig" "content.twig" "resources/bundle")
last_build=0

fswatch -0 css javascript src config resources | while read -d "" file; do

    for i in "${ignore[@]}"; do
        if [[ $file == *$i* ]]; then
            continue 2
        fi
    done

    now=$(date +%s)
    if (( now - last_build < DEBOUNCE )); then
        echo "  ↳ $file (batched)"
        continue
    fi

    # Small delay to let additional file events arrive
    sleep 1

    echo "$(date '+%Y-%m-%d %H:%M:%S') - Rebuilding..."
    echo "  ↳ $file"

    bin/build-assets.sh
    php bin/make-bundle.php
    last_build=$(date +%s)

    echo "Watching..."
    echo ""
done
