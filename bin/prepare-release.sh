#!/bin/bash

# TotalCMS Release Preparation Script
# This script automates the release preparation process

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Sentry configuration
# Auth token is stored in ~/.sentryclirc via `sentry-cli login`
SENTRY_ORG="aspect-services-llc"
SENTRY_PROJECTS=("total-cms" "totalcms-dashboard")  # Backend and frontend projects

# Function to print colored output
print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Function to upload source maps to Sentry
upload_sourcemaps() {
    local version=$1
    local release_version="totalcms@${version}"

    if ! command_exists sentry-cli; then
        print_warning "sentry-cli not installed - skipping source map upload"
        print_info "Install with: brew install getsentry/tools/sentry-cli"
        return 0
    fi

    # Check if sentry-cli is authenticated (via ~/.sentryclirc or SENTRY_AUTH_TOKEN)
    if ! sentry-cli info >/dev/null 2>&1; then
        print_warning "sentry-cli not authenticated - skipping source map upload"
        print_info "Run: sentry-cli login"
        return 0
    fi

    # Upload source maps for frontend project only
    print_info "Uploading source maps to Sentry..."

    # ~/assets matches URLs like https://domain.com/anything/assets/file.js
    # The ~ is a wildcard for protocol+host, and Sentry matches from the end
    if sentry-cli sourcemaps upload \
        --org "$SENTRY_ORG" \
        --project "totalcms-dashboard" \
        --release "$release_version" \
        --url-prefix "~/assets" \
        dist/public/assets; then
        print_success "Source maps uploaded to Sentry"
    else
        print_warning "Failed to upload source maps to Sentry"
        return 1
    fi

    # Delete source maps from dist (keep them private)
    print_info "Removing source maps from distribution..."
    find dist/public/assets -name "*.map" -type f -delete
    print_success "Source maps removed from distribution"
}

# Function to notify Sentry of new release
notify_sentry_release() {
    local version=$1
    local git_hash=$2
    local release_version="totalcms@${version}"

    if ! command_exists sentry-cli; then
        print_warning "sentry-cli not installed - skipping Sentry release notification"
        print_info "Install with: brew install getsentry/tools/sentry-cli"
        return 0
    fi

    # Check if sentry-cli is authenticated (via ~/.sentryclirc or SENTRY_AUTH_TOKEN)
    if ! sentry-cli info >/dev/null 2>&1; then
        print_warning "sentry-cli not authenticated - skipping Sentry release notification"
        print_info "Run: sentry-cli login"
        return 0
    fi

    # Create release for each Sentry project (backend and frontend)
    for project in "${SENTRY_PROJECTS[@]}"; do
        print_info "Creating Sentry release for $project: $release_version"

        # Create the release
        if sentry-cli releases new "$release_version" \
            --org "$SENTRY_ORG" \
            --project "$project"; then
            print_success "Sentry release created for $project"
        else
            print_warning "Failed to create Sentry release for $project"
            continue
        fi

        # Associate commits with the release
        if sentry-cli releases set-commits "$release_version" --auto \
            --org "$SENTRY_ORG"; then
            print_success "Commits associated with $project release"
        else
            print_warning "Failed to associate commits for $project (optional)"
        fi

        # Mark the release as deployed
        if sentry-cli releases deploys "$release_version" new \
            --org "$SENTRY_ORG" \
            --env production; then
            print_success "$project release marked as deployed"
        else
            print_warning "Failed to mark $project release as deployed"
        fi

        # Finalize the release
        if sentry-cli releases finalize "$release_version" \
            --org "$SENTRY_ORG"; then
            print_success "$project release finalized"
        else
            print_warning "Failed to finalize $project release"
        fi
    done
}

# Check prerequisites
print_info "Checking prerequisites..."

if ! command_exists git; then
    print_error "git is not installed"
    exit 1
fi

if ! command_exists composer; then
    print_error "composer is not installed"
    exit 1
fi

if ! command_exists yarn; then
    print_error "yarn is not installed"
    exit 1
fi

if ! command_exists php; then
    print_error "php is not installed"
    exit 1
fi

if ! command_exists sentry-cli; then
    print_error "sentry-cli is not installed"
    print_info "Install with: brew install getsentry/tools/sentry-cli"
    exit 1
fi

if ! sentry-cli info >/dev/null 2>&1; then
    print_error "sentry-cli is not authenticated"
    print_info "Run: sentry-cli login"
    exit 1
fi

print_success "All prerequisites are installed"

# Get current version from version.json or version.txt
if [ -f "version.json" ]; then
    CURRENT_VERSION=$(php -r "echo json_decode(file_get_contents('version.json'))->version ?? 'unknown';")
elif [ -f "version.txt" ]; then
    CURRENT_VERSION=$(head -n 1 version.txt | sed -E 's/^([0-9]+\.[0-9]+\.[0-9]+).*$/\1/')
else
    CURRENT_VERSION="unknown"
fi
print_info "Current version: $CURRENT_VERSION"

read -p "Enter new version number (or press Enter to keep current): " NEW_VERSION
if [ -z "$NEW_VERSION" ]; then
    NEW_VERSION=$CURRENT_VERSION
fi

# Check git status
print_info "Checking git status..."
if [ -n "$(git status --porcelain)" ]; then
    print_warning "You have uncommitted changes:"
    git status --short
    read -p "Do you want to continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        print_info "Aborting release preparation"
        exit 0
    fi
fi

# Pull latest changes
print_info "Pulling latest changes from remote..."
git pull || print_warning "Failed to pull latest changes"

# Clean build artifacts
print_info "Cleaning build artifacts..."
rm -rf vendor/ node_modules/ public/assets/
print_success "Build artifacts cleaned"

# Install dependencies
print_info "Installing PHP dependencies..."
composer install
print_success "PHP dependencies installed"

print_info "Installing JavaScript dependencies..."
yarn install --production=false  # Need dev dependencies for building
print_success "JavaScript dependencies installed"

# Run quality checks
print_info "Running quality checks..."

print_info "Running code style fixer..."
if composer run cs:fix; then
    print_success "Code style fixed"

    # Check if cs:fix made any changes
    if [ -n "$(git status --porcelain)" ]; then
        print_warning "Code style fixer made changes:"
        git status --short
        read -p "Do you want to commit these changes? (Y/n) " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Nn]$ ]]; then
            git add .
            git commit -m "Fix code style for release preparation"
            print_success "Code style changes committed"
        else
            print_warning "Code style changes not committed - they will be included in release"
        fi
    fi
else
    print_warning "Code style fixer had issues"
fi

print_info "Running PHPStan..."
if composer run stan; then
    print_success "PHPStan passed"
else
    print_error "PHPStan failed"
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

print_info "Running tests..."
if composer run test; then
    print_success "Tests passed"
else
    print_error "Tests failed"
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

print_info "Installing PHP dependencies for release..."
composer install --no-dev --optimize-autoloader
print_success "PHP dependencies installed"

# Build assets
print_info "Building production assets..."
composer run build
print_success "Assets built"

bin/code-report.sh > code-report.txt

# Get current git commit hash (needed for version and Sentry)
GIT_HASH=$(git rev-parse --short HEAD)

# Update version if changed (after build to prevent overwriting)
print_info "Comparing versions: '$NEW_VERSION' vs '$CURRENT_VERSION'"
if [ "$NEW_VERSION" != "$CURRENT_VERSION" ]; then
    print_info "Updating version to $NEW_VERSION..."
    # Generate version.json with HMAC signature
    php bin/generate-version.php "$NEW_VERSION" "$GIT_HASH"
    cp version.json dist/version.json
    print_success "Version updated to $NEW_VERSION ($GIT_HASH)"
else
    # Regenerate version.json with today's date for same version
    print_info "Regenerating version.json with current date..."
    php bin/generate-version.php "$NEW_VERSION" "$GIT_HASH"
    cp version.json dist/version.json
    print_info "Version unchanged ($CURRENT_VERSION)"
fi

# Dump autoloader for production
print_info "Optimizing composer autoloader..."
composer dump-autoload --optimize --no-dev
print_success "Autoloader optimized"

# Clear any caches
print_info "Clearing caches..."
rm -rf cache/* tmp/* logs/*
print_success "Caches cleared"

# Set proper permissions
print_info "Setting proper file permissions..."
find . -type f -name "*.php" -exec chmod 644 {} +
find . -type d -exec chmod 755 {} +
chmod +x bin/*.sh bin/*.php resources/bin/tcms
print_success "File permissions set"

# Generate checksums
print_info "Generating file checksums..."
find . -type f \( -name "*.php" -o -name "*.js" -o -name "*.css" \) -not -path "./vendor/*" -not -path "./node_modules/*" -not -path "./cache/*" -not -path "./tmp/*" -exec sha256sum {} \; > checksums.txt
print_success "Checksums generated"

# Sync docs to docs site
DOCS_SYNC="$HOME/Websites/docs.totalcms.co/bin/sync-from-totalcms.sh"
if [ -x "$DOCS_SYNC" ]; then
    print_info "Syncing documentation to docs site..."
    if "$DOCS_SYNC" resources/docs; then
        print_success "Documentation synced to docs site"
    else
        print_warning "Failed to sync documentation"
    fi
else
    print_warning "Docs sync script not found at $DOCS_SYNC - skipping"
fi

# Upload source maps to Sentry (before deleting them from dist)
upload_sourcemaps "$NEW_VERSION"

# Notify Sentry of new release
notify_sentry_release "$NEW_VERSION" "$GIT_HASH"

# Generate Composer package manifest for dist
print_info "Generating Composer package manifest..."
php bin/make-dist-composer.php dist
print_success "Composer package manifest generated"

# Create dist zip for update system
print_info "Creating distribution zip..."
DIST_ZIP="totalcms-${NEW_VERSION}.zip"
(cd dist && zip -qr "../${DIST_ZIP}" .)
print_success "Distribution zip created: ${DIST_ZIP}"

# Determine severity from version comparison
determine_severity() {
    local old=$1 new=$2
    local old_major old_minor new_major new_minor
    old_major=$(echo "$old" | cut -d. -f1)
    old_minor=$(echo "$old" | cut -d. -f2)
    new_major=$(echo "$new" | cut -d. -f1)
    new_minor=$(echo "$new" | cut -d. -f2)

    if [ "$new_major" != "$old_major" ]; then
        echo "major"
    elif [ "$new_minor" != "$old_minor" ]; then
        echo "minor"
    else
        echo "patch"
    fi
}

SEVERITY=$(determine_severity "$CURRENT_VERSION" "$NEW_VERSION")

# Extract changelog (latest section from CHANGELOG.md)
CHANGELOG=""
if [ -f "CHANGELOG.md" ]; then
    CHANGELOG=$(awk '/^## /{if(found)exit; found=1; next} found{print}' CHANGELOG.md | head -50)
fi

# Register version with license API
TOTALCMS_RELEASE_KEY="${TOTALCMS_RELEASE_KEY:-}"
if [ -n "$TOTALCMS_RELEASE_KEY" ]; then
    print_info "Registering version with license API..."
    RELEASE_DATE=$(date +%Y-%m-%d)

    CHANGELOG_JSON=$(echo "$CHANGELOG" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' 2>/dev/null || echo '""')

    JSON_BODY=$(cat <<EOF
{
    "versionNumber": "${NEW_VERSION}",
    "releaseDate": "${RELEASE_DATE}",
    "buildHash": "${GIT_HASH}",
    "severity": "${SEVERITY}",
    "changelog": ${CHANGELOG_JSON}
}
EOF
)

    REGISTER_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "https://license.totalcms.co/version" \
        -H "X-API-Key: ${TOTALCMS_RELEASE_KEY}" \
        -H "Content-Type: application/json" \
        -A "TotalCMS-Release/1.0" \
        -d "$JSON_BODY")

    HTTP_CODE=$(echo "$REGISTER_RESPONSE" | tail -1)
    RESPONSE_BODY=$(echo "$REGISTER_RESPONSE" | sed '$d')
    if [ "$HTTP_CODE" = "200" ]; then
        print_success "Version registered with license API"
    else
        print_warning "Failed to register version with license API (HTTP $HTTP_CODE)"
        print_warning "Response: $RESPONSE_BODY"
    fi
else
    print_warning "TOTALCMS_RELEASE_KEY not set — skipping version registration"
    print_info "Set TOTALCMS_RELEASE_KEY environment variable to auto-register releases"
fi

# Upload dist zip to S3
S3_BUCKET="s3://totalcms-archive"
print_info "Uploading dist zip to S3..."
if aws s3 cp "${DIST_ZIP}" "${S3_BUCKET}/releases/totalcms-${NEW_VERSION}.zip"; then
    print_success "Dist zip uploaded to ${S3_BUCKET}/releases/totalcms-${NEW_VERSION}.zip"
	rm -f "${DIST_ZIP}"
	print_success "Dist zip uploaded and cleaned up"
else
    print_warning "Failed to upload dist zip to S3"
fi

# Project skeleton constraint check
#
# The Composer project skeleton (totalcms/totalcms) declares a major.minor
# constraint on totalcms/cms (e.g. "^3.5"). Patch releases resolve cleanly
# under the existing constraint; minor/major releases need the skeleton's
# constraint to be bumped to match, otherwise `composer create-project`
# can't resolve the new version.
#
# We don't touch the skeleton repo automatically — cross-repo commits from
# a release script are too easy to get wrong. The check just surfaces the
# gap with the exact commands to run.
PROJECT_SKEL_PATH="${TOTALCMS_PROJECT_PATH:-$HOME/Developer/totalcms-project}"
if [ -f "$PROJECT_SKEL_PATH/composer.json" ]; then
    SKEL_CONSTRAINT=$(php -r "\$c = json_decode(file_get_contents('$PROJECT_SKEL_PATH/composer.json'), true); echo \$c['require']['totalcms/cms'] ?? '';" 2>/dev/null)
    NEW_MAJOR_MINOR=$(echo "$NEW_VERSION" | cut -d. -f1-2)
    if [ -n "$SKEL_CONSTRAINT" ] && [[ "$SKEL_CONSTRAINT" != *"$NEW_MAJOR_MINOR"* ]]; then
        echo
        print_warning "Project skeleton constraint is out of sync."
        print_info "  Skeleton requires: $SKEL_CONSTRAINT"
        print_info "  New cms version:   $NEW_VERSION"
        echo
        print_info "Update + tag the skeleton with:"
        echo "  cd \"$PROJECT_SKEL_PATH\""
        echo "  # change 'totalcms/cms' to \"^$NEW_MAJOR_MINOR\" in composer.json"
        echo "  git add composer.json && git commit -m \"Require totalcms/cms ^$NEW_MAJOR_MINOR\""
        echo "  git tag $NEW_VERSION && git push origin HEAD && git push origin $NEW_VERSION"
        echo
    fi
fi

# Optional: tag + push the cms repo
#
# Gated by a confirmation prompt because pushing a tag is one-way. After a
# 5-minute release run, the operator gets one last "yes, ship it" moment to
# eyeball things before the tag goes public and Packagist mirrors it.
TAG_AND_PUSHED=0
echo
read -p "Tag $NEW_VERSION and push to github now? (y/N) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    BRANCH=$(git rev-parse --abbrev-ref HEAD)
    if git tag "$NEW_VERSION"; then
        print_success "Created tag $NEW_VERSION"
        if git push github "$BRANCH" && git push github "$NEW_VERSION"; then
            print_success "Pushed $BRANCH + tag $NEW_VERSION to github"
            print_info "Packagist webhook will mirror within ~60s. Verify at: https://packagist.org/packages/totalcms/cms"
            TAG_AND_PUSHED=1
        else
            print_warning "Push failed — fix the issue and run: git push github $BRANCH && git push github $NEW_VERSION"
        fi
    else
        print_warning "Tag creation failed — does $NEW_VERSION already exist?"
    fi
else
    print_info "Skipped — tag and push manually when ready:"
    echo "  git tag $NEW_VERSION"
    echo "  git push github \$(git rev-parse --abbrev-ref HEAD)"
    echo "  git push github $NEW_VERSION"
fi

# Summary
echo
print_success "Release preparation complete!"
echo
echo "Release checklist:"
echo "  ✓ Prerequisites checked"
echo "  ✓ Git status verified"
echo "  ✓ Dependencies installed"
echo "  ✓ Quality checks run"
echo "  ✓ Version updated to $NEW_VERSION ($SEVERITY)"
echo "  ✓ Assets built"
echo "  ✓ Autoloader optimized"
echo "  ✓ Caches cleared"
echo "  ✓ Permissions set"
echo "  ✓ Checksums generated"
echo "  ✓ Documentation synced to docs site"
echo "  ✓ Source maps uploaded to Sentry"
echo "  ✓ Sentry release notified"
echo "  ✓ Distribution zip created: $DIST_ZIP"
echo "  ✓ Version registered with license API"
if [ "$TAG_AND_PUSHED" -eq 1 ]; then
    echo "  ✓ Tag $NEW_VERSION pushed to github (Packagist mirror in flight)"
fi
echo
echo "Next steps:"
echo "  1. Review the changes one more time"
echo "  2. Test the production build locally"
if [ "$TAG_AND_PUSHED" -eq 1 ]; then
    echo "  3. Verify https://packagist.org/packages/totalcms/cms shows $NEW_VERSION"
    echo "  4. Create a GitHub release for $NEW_VERSION with changelog notes"
else
    echo "  3. Create git tag: git tag $NEW_VERSION"
    echo "  4. Push to repository: git push github HEAD && git push github $NEW_VERSION"
    echo "  5. Create a GitHub release for $NEW_VERSION with changelog notes"
    echo "  6. Packagist will auto-mirror the new tag (via webhook)"
fi
echo
