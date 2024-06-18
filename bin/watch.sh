#!/bin/bash

echo "Watching..."

ignore=("totalform.twig" "content.twig")

fswatch -0 templates css javascript | while read -d "" file; do
    echo Detected Change on ${file}...

    for i in "${ignore[@]}"; do
        if [[ $file == *$i* ]]; then
            echo "Ignoring $file"
            continue 2
        fi
    done

    bin/build-assets.sh
	echo "Watching..."
done