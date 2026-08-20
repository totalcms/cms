#!/bin/sh
#
# Installs the tracked hooks in .githooks/ into .git/hooks/.
#
# Deliberately not `git config core.hooksPath .githooks`: that replaces the
# hooks directory wholesale and would disable hooks other tools install of
# their own accord (graphify writes post-commit and post-checkout). Copying
# file by file leaves those alone.
#
# Safe to re-run. Refuses to clobber an existing hook it did not write.

set -e

root=$(git rev-parse --show-toplevel)
cd "$root"

src=".githooks"
dest=".git/hooks"

if [ ! -d "$src" ]; then
	echo "No $src directory — nothing to install."
	exit 0
fi

if [ ! -d "$dest" ]; then
	echo "No $dest directory — is this a git checkout?"
	exit 1
fi

marker="# totalcms-hook"

for hook in "$src"/*; do
	[ -f "$hook" ] || continue
	name=$(basename "$hook")
	target="$dest/$name"

	if [ -f "$target" ] && ! grep -q "$marker" "$target" 2>/dev/null; then
		if cmp -s "$hook" "$target"; then
			echo "unchanged: $name"
			continue
		fi
		echo "SKIPPED:   $name — $target exists and was not installed by this script."
		echo "           Merge it by hand, or delete it and re-run."
		continue
	fi

	# Plain copy: the marker lives inside the hook itself, so it cannot be
	# prepended here without displacing the shebang.
	cp "$hook" "$target"
	chmod +x "$target"
	echo "installed: $name"
done
