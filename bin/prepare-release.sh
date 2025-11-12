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

print_success "All prerequisites are installed"

# Get current version from version.txt file
if [ -f "version.txt" ]; then
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

# Update version if changed (after build to prevent overwriting)
print_info "Comparing versions: '$NEW_VERSION' vs '$CURRENT_VERSION'"
if [ "$NEW_VERSION" != "$CURRENT_VERSION" ]; then
    print_info "Updating version to $NEW_VERSION..."
    # Get current git commit hash
    GIT_HASH=$(git rev-parse --short HEAD)
    # Update version in version.txt file
    echo "$NEW_VERSION ($GIT_HASH)" > version.txt
	cp version.txt dist/version.txt
    print_success "Version updated to $NEW_VERSION ($GIT_HASH)"
else
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
find . -type f -name "*.php" -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
chmod +x bin/*.sh bin/*.php
print_success "File permissions set"

# Generate checksums
print_info "Generating file checksums..."
find . -type f \( -name "*.php" -o -name "*.js" -o -name "*.css" \) -not -path "./vendor/*" -not -path "./node_modules/*" -not -path "./cache/*" -not -path "./tmp/*" -exec sha256sum {} \; > checksums.txt
print_success "Checksums generated"

# Summary
echo
print_success "Release preparation complete!"
echo
echo "Release checklist:"
echo "  ✓ Prerequisites checked"
echo "  ✓ Git status verified"
echo "  ✓ Dependencies installed"
echo "  ✓ Quality checks run"
echo "  ✓ Version updated to $NEW_VERSION"
echo "  ✓ Assets built"
echo "  ✓ Autoloader optimized"
echo "  ✓ Caches cleared"
echo "  ✓ Permissions set"
echo "  ✓ Checksums generated"
echo
echo "Next steps:"
echo "  1. Review the changes one more time"
echo "  2. Test the production build locally"
echo "  3. Create git tag: git tag -a v$NEW_VERSION -m 'Release version $NEW_VERSION'"
echo "  4. Push to repository: git push && git push --tags"
echo "  5. Create release on GitHub with changelog"
echo "  6. Deploy to production"
echo
