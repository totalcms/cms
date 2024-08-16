#!/bin/bash

bin/build-assets.sh
php bin/make-bundle.php

echo "Watching..."

ignore=("totalform.twig" "content.twig")

fswatch -0 templates css javascript src | while read -d "" file; do
    echo Detected Change on ${file}...

    for i in "${ignore[@]}"; do
        if [[ $file == *$i* ]]; then
            echo "Ignoring $file"
            continue 2
        fi
    done

    bin/build-assets.sh
    php bin/make-bundle.php

	echo "Watching..."
done