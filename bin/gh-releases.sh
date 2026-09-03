#!/usr/bin/env bash
#
# Create or update GitHub releases from CHANGELOG.md.
#
#   bin/gh-releases.sh --dry-run --since 3.1.0   # show what would happen
#   bin/gh-releases.sh --since 3.1.0             # backfill every tagged release
#   bin/gh-releases.sh 3.5.2                     # one release
#
# Notes come from bin/changelog-notes.sh, so a release page says exactly what
# CHANGELOG.md says and there is no second copy to drift.
#
# Pre-release tags (beta/rc/alpha) are skipped: they are build checkpoints, not
# something to publish a release page for.
#
# Safe to re-run. An existing release is updated in place rather than
# duplicated, so fixing a typo in CHANGELOG.md and re-running is the intended
# way to correct published notes.

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo_root"

DRY_RUN=0
SINCE=""
VERSIONS=()

while [ $# -gt 0 ]; do
	case "$1" in
		--dry-run) DRY_RUN=1; shift ;;
		--since)   SINCE="${2:-}"; shift 2 ;;
		-h|--help)
			sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
			exit 0
			;;
		-*) echo "unknown option: $1" >&2; exit 2 ;;
		*)  VERSIONS+=("$1"); shift ;;
	esac
done

if ! command -v gh >/dev/null 2>&1; then
	echo "gh CLI not found. Install it or create the releases by hand." >&2
	exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
	echo "gh is not authenticated. Run: gh auth login" >&2
	exit 1
fi

# Build the version list. --since walks the tags; otherwise use what was named.
if [ -n "$SINCE" ]; then
	while IFS= read -r tag; do
		VERSIONS+=("$tag")
	done < <(git tag --sort=v:refname \
		| grep -vE 'beta|rc|alpha' \
		| awk -v since="$SINCE" '$0 >= since')
fi

if [ ${#VERSIONS[@]} -eq 0 ]; then
	echo "nothing to do: pass a version or --since <version>" >&2
	exit 2
fi

# The highest version is the one GitHub should point at as "Latest". Without
# this a backfill leaves whichever release was created last wearing the badge.
newest="${VERSIONS[${#VERSIONS[@]}-1]}"

created=0
updated=0
skipped=0

for version in "${VERSIONS[@]}"; do
	if ! git rev-parse "$version" >/dev/null 2>&1; then
		echo "  SKIP  ${version} — no such tag"
		skipped=$((skipped + 1))
		continue
	fi

	if ! notes=$(bin/changelog-notes.sh "$version" 2>/dev/null); then
		echo "  SKIP  ${version} — no CHANGELOG section"
		skipped=$((skipped + 1))
		continue
	fi

	latest_flag="--latest=false"
	[ "$version" = "$newest" ] && latest_flag="--latest"

	if gh release view "$version" >/dev/null 2>&1; then
		action="UPDATE"
		verb="edit"
	else
		action="CREATE"
		verb="create"
	fi

	if [ "$DRY_RUN" -eq 1 ]; then
		printf '  %-6s %-8s %s  (%s lines of notes)\n' \
			"$action" "$version" "$latest_flag" "$(printf '%s\n' "$notes" | wc -l | tr -d ' ')"
		continue
	fi

	notes_file=$(mktemp)
	printf '%s\n' "$notes" > "$notes_file"

	if [ "$verb" = "create" ]; then
		gh release create "$version" \
			--title "$version" \
			--notes-file "$notes_file" \
			--verify-tag \
			$latest_flag >/dev/null
		created=$((created + 1))
	else
		gh release edit "$version" \
			--title "$version" \
			--notes-file "$notes_file" \
			$latest_flag >/dev/null
		updated=$((updated + 1))
	fi

	rm -f "$notes_file"
	echo "  ${action}  ${version}"
done

if [ "$DRY_RUN" -eq 1 ]; then
	echo
	echo "dry run — nothing was published. Drop --dry-run to apply."
else
	echo
	echo "created: ${created}  updated: ${updated}  skipped: ${skipped}"
fi
