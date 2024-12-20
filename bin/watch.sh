#!/bin/bash

bin/build-assets.sh
php bin/make-bundle.php

echo "Watching..."
echo ""

ignore=("totalform.twig" "content.twig")

fswatch -0 templates css javascript src | while read -d "" file; do
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Detected change"
    echo $file

    for i in "${ignore[@]}"; do
        if [[ $file == *$i* ]]; then
            echo "Ignoring $file"
            continue 2
        fi
    done

    bin/build-assets.sh
    php bin/make-bundle.php

	echo "Watching..."
    echo ""
done