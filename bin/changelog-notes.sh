#!/usr/bin/env bash
#
# Print the CHANGELOG.md section for one version, without its heading.
#
#   bin/changelog-notes.sh 3.5.1
#
# Split out from the release flow so the notes that reach a GitHub release,
# the license API and anything else all come from one place. prepare-release.sh
# had its own inline awk for this, capped at 50 lines — fine for the API field
# it fed, wrong for release notes.
#
# Matching is by exact prefix rather than regex: a version is full of dots, and
# `3.1.0` as a pattern would also match `3x1y0`.

set -euo pipefail

version="${1:-}"
changelog="${2:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/CHANGELOG.md}"

if [ -z "$version" ]; then
	echo "usage: $(basename "$0") <version> [changelog-path]" >&2
	exit 2
fi

if [ ! -f "$changelog" ]; then
	echo "changelog not found: $changelog" >&2
	exit 1
fi

notes=$(awk -v prefix="## [${version}]" '
	# The heading for the version we want: start capturing after it.
	substr($0, 1, length(prefix)) == prefix { found = 1; next }
	# The next version heading ends the section.
	found && substr($0, 1, 4) == "## [" { exit }
	found { print }
' "$changelog")

# Trim leading and trailing blank lines; the section is bounded by them.
notes=$(printf '%s\n' "$notes" | sed -e '/./,$!d' | sed -e :a -e '/^\n*$/{$d;N;};/\n$/ba')

if [ -z "$notes" ]; then
	echo "no CHANGELOG section found for version ${version}" >&2
	exit 1
fi

printf '%s\n' "$notes"
