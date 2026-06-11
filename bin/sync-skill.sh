#!/bin/bash
# Sync the Total CMS agent skill from this repo's source of truth into the
# totalcms-project skeleton, then lint it. Never commits the skeleton — mirrors
# the docs-sync rule in prepare-release.sh (surface, don't auto-commit).

set -e

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$REPO_ROOT/resources/skill"
SKEL_PATH="${TOTALCMS_PROJECT_PATH:-$HOME/Developer/totalcms-project}"
DEST="$SKEL_PATH/.claude/skills/totalcms"

# --- lint the source before copying ---
fail() { echo "[sync-skill] LINT FAIL: $1" >&2; exit 1; }

[ -f "$SRC/SKILL.md" ] || fail "missing $SRC/SKILL.md"
head -5 "$SRC/SKILL.md" | grep -q '^name:'        || fail "SKILL.md missing 'name:' frontmatter"
head -5 "$SRC/SKILL.md" | grep -q '^description:' || fail "SKILL.md missing 'description:' frontmatter"

for ref in cli site-builder frontend data-model; do
    [ -f "$SRC/references/$ref.md" ] || fail "missing references/$ref.md"
    grep -q "references/$ref.md" "$SRC/SKILL.md" || fail "SKILL.md does not link references/$ref.md"
done

echo "[sync-skill] lint OK"

# --- copy into the skeleton ---
if [ ! -d "$SKEL_PATH" ]; then
    echo "[sync-skill] skeleton not found at $SKEL_PATH — set TOTALCMS_PROJECT_PATH. Skipping." >&2
    exit 0
fi

mkdir -p "$DEST/references"
cp "$SRC/SKILL.md" "$DEST/SKILL.md"
cp "$SRC/references/"*.md "$DEST/references/"

echo "[sync-skill] copied skill -> $DEST"
echo "[sync-skill] REVIEW + COMMIT the skeleton repo manually:"
echo "    cd \"$SKEL_PATH\" && git add .claude && git status"
