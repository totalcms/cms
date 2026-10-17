#!/bin/bash

bin/build-assets.sh

echo "Watching..."
echo ""

DEBOUNCE=2
# resources/bundle is rewritten by the loop itself; ignoring it stops a
# rebuild from re-triggering the watcher.
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

    # Keep the integrity manifest current. BundleMiddleware compares the
    # tree against it on every write, so an edit under config/, resources/
    # or the covered src/ folders would otherwise 400 with "installation has
    # been corrupted" until someone ran `composer run bundle`. Unconditional
    # because the loop only sees the first file of a batch, and a rebuild
    # takes under half a second.
    php bin/make-bundle.php
    last_build=$(date +%s)

    echo "Watching..."
    echo ""
done
