#!/bin/bash

# One watcher per checkout. Two at once run every rebuild twice in parallel,
# and the overlapping builds clobber each other's output in public/assets.
PIDFILE=".watch.pid"
if [ -f "$PIDFILE" ]; then
    other=$(cat "$PIDFILE")
    if kill -0 "$other" 2>/dev/null && ps -o command= -p "$other" | grep -q "watch.sh"; then
        echo "watch.sh is already running (PID $other). Stop it first: kill $other"
        exit 1
    fi
fi
echo $$ > "$PIDFILE"
# Take fswatch and the loop down with us so they don't outlive the script
trap 'rm -f "$PIDFILE"; trap - EXIT; pkill -P $$' EXIT INT TERM

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
done &

# In the background with a wait, not in the foreground: bash holds a trap until
# the foreground pipeline exits, which fswatch never does, so a plain `kill`
# would leave the watcher running.
wait
