#!/bin/bash

# TotalCMS test runner
#
# Full battery before committing, or a tight filtered loop while working.
# Steps run cheapest-first and stop at the first failure.
#
# Output is quiet by default: one line per step with its result. A step's full
# output is shown only when it fails — which is the only time you want it.
# Use -v to see everything.

set -uo pipefail

# Run from the repo root no matter where the script was invoked from.
cd "$(dirname "$0")/.." || exit 1

# Colors (same convention as bin/prepare-release.sh)
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
print_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
print_error()   { echo -e "${RED}[ERROR]${NC} $1"; }

# Strip ANSI colour codes so a captured summary line prints cleanly.
strip_ansi() { LC_ALL=C sed $'s/\033\\[[0-9;]*m//g'; }

# How to run a command under a pseudo-terminal.
#
# Pest renders a compact live progress display when it detects a terminal, and
# falls back to dumping one dot per test when its output is a pipe. Since the
# PHP suite takes ~40s we want the live display AND a copy of the output to
# pull the summary line from, so it runs under `script`, which gives the child
# a PTY while teeing everything to a file. `script` also propagates the child's
# exit status on both BSD and util-linux.
PTY_STYLE=""
if command -v script >/dev/null 2>&1; then
	if script -q /dev/null true >/dev/null 2>&1; then
		PTY_STYLE="bsd"        # script -q FILE cmd args...
	elif script -q -e -c true /dev/null >/dev/null 2>&1; then
		PTY_STYLE="linux"      # script -q -e -c "cmd" FILE
	fi
fi
usage() {
	cat <<'USAGE'
Usage: bin/runtests.sh [FILTER] [--lint] [-v]

  bin/runtests.sh                          Full battery (~45s)
                                             bundle:check, stan, docs:validate,
                                             PHP tests (parallel), JS tests
  bin/runtests.sh Barcode                  Only PHP tests matching "Barcode"
  bin/runtests.sh tests/Feature/Foo.php    Only that file or directory
  bin/runtests.sh --lint                   Also run phplint (~36s, off by default)
  bin/runtests.sh -v                       Stream full output instead of summaries

A FILTER runs ONLY the matching PHP tests — the static checks and JS tests are
skipped to keep the edit-run loop fast. Run with no arguments before committing.

FILTER is passed to Pest as a path when it names an existing file or directory,
and as --filter otherwise.
USAGE
}

RUN_LINT=false
VERBOSE=false
FILTER=""

while [ $# -gt 0 ]; do
	case "$1" in
		--lint)        RUN_LINT=true ;;
		-v|--verbose)  VERBOSE=true ;;
		-h|--help)     usage; exit 0 ;;
		-*)            print_error "Unknown option: $1"; echo; usage; exit 1 ;;
		*)
			if [ -n "$FILTER" ]; then
				print_error "Only one filter may be given (got '$FILTER' and '$1')"
				exit 1
			fi
			FILTER="$1"
			;;
	esac
	shift
done

START=$(date +%s)
STEP_OUT=""          # captured output of the most recent step
REQUIRE_SUMMARY=""  # when set, a step producing no summary line fails with this message

cleanup() { [ -n "$STEP_OUT" ] && rm -f "$STEP_OUT"; }
trap cleanup EXIT

elapsed() { echo "$(( $(date +%s) - START ))s"; }

# run_step <label> <summary-regex> <command...>
#
# Captures the step's output. On success prints one line: a tick, the elapsed
# time, and the line matching <summary-regex> (pass '' for no summary). On
# failure prints the entire captured output and stops the run.
run_step() {
	local label="$1" pattern="$2"
	shift 2
	local start status secs summary

	start=$(date +%s)

	if [ "$VERBOSE" = true ]; then
		echo
		print_info "$label..."
		"$@"
		status=$?
		secs=$(( $(date +%s) - start ))
	else
		[ -n "$STEP_OUT" ] && rm -f "$STEP_OUT"
		STEP_OUT=$(mktemp)
		"$@" > "$STEP_OUT" 2>&1
		status=$?
		secs=$(( $(date +%s) - start ))
	fi

	if [ "$status" -ne 0 ]; then
		printf "  ${RED}✗${NC} %-18s %5s\n" "$label" "${secs}s"
		if [ "$VERBOSE" != true ]; then
			echo
			# Drop Composer's own framing (the echoed command line and the
			# "Script ... returned with error code" trailer) and keep every line
			# the underlying tool produced. Composer's --quiet would have
			# swallowed the tool output too, which is the part that matters.
			grep -avE '^> |^Script .* returned with error code' "$STEP_OUT"
		fi
		echo
		FAILED_LABEL="$label"
		print_error "$label FAILED"
		exit 1
	fi

	if [ "$VERBOSE" = true ]; then
		print_success "$label passed (${secs}s)"
		return 0
	fi

	summary=""
	if [ -n "$pattern" ]; then
		# Strip colour first: with --colors=always the escape codes sit before
		# the text, so an anchored pattern like '^ *Tests:' would never match.
		summary=$(strip_ansi < "$STEP_OUT" | grep -aE "$pattern" | tail -1 | sed 's/^ *//; s/ *$//; s/  */ /g')
	fi
	# A step that was supposed to report a summary but produced none did not
	# really do any work — a filter matching nothing still exits 0.
	if [ -n "$REQUIRE_SUMMARY" ] && [ -z "$summary" ]; then
		printf "  ${RED}✗${NC} %-18s %5s\n" "$label" "${secs}s"
		echo
		print_error "$REQUIRE_SUMMARY"
		exit 1
	fi

	printf "  ${GREEN}✓${NC} %-18s %5s  %s\n" "$label" "${secs}s" "$summary"
}

# run_step_live <label> <summary-regex> <command...>
#
# Same contract as run_step, but streams the command's output as it runs
# instead of hiding it until the end. Used for the PHP suite, which takes ~40s
# — watching it progress beats staring at a blank terminal. Output is still
# captured (through tee) so the summary line can be extracted, and there is no
# re-dump on failure because you already watched it happen.
run_step_live() {
	local label="$1" pattern="$2"
	shift 2
	local start status secs summary

	[ -n "$STEP_OUT" ] && rm -f "$STEP_OUT"
	STEP_OUT=$(mktemp)
	start=$(date +%s)

	echo
	case "$PTY_STYLE" in
		bsd)
			script -q "$STEP_OUT" "$@"
			status=$?
			;;
		linux)
			script -q -e -c "$(printf '%q ' "$@")" "$STEP_OUT"
			status=$?
			;;
		*)
			# No `script` available: stream through tee and accept Pest's
			# dot-per-test fallback rendering.
			"$@" 2>&1 | tee "$STEP_OUT"
			status=${PIPESTATUS[0]}
			;;
	esac
	secs=$(( $(date +%s) - start ))
	echo

	summary=$(strip_ansi < "$STEP_OUT" | grep -aE "$pattern" | tail -1 | sed 's/^ *//; s/ *$//; s/  */ /g')

	if [ "$status" -ne 0 ]; then
		printf "  ${RED}✗${NC} %-18s %5s\n" "$label" "${secs}s"
		echo
		print_error "$label FAILED"
		exit 1
	fi

	if [ -n "$REQUIRE_SUMMARY" ] && [ -z "$summary" ]; then
		printf "  ${RED}✗${NC} %-18s %5s\n" "$label" "${secs}s"
		echo
		print_error "$REQUIRE_SUMMARY"
		exit 1
	fi

	printf "  ${GREEN}✓${NC} %-18s %5s  %s\n" "$label" "${secs}s" "$summary"
}
# ─── Filtered run: PHP tests only ────────────────────────────────────────────
if [ -n "$FILTER" ]; then
	# Pest prints no "Tests:" summary when a filter matches nothing — and still
	# exits 0. Without this, a typo'd filter reports a green run that tested
	# nothing at all.
	[ "$VERBOSE" != true ] && REQUIRE_SUMMARY="Filter '$FILTER' matched no tests"

	if [ -e "$FILTER" ]; then
		echo "Running PHP tests in $FILTER"
		run_step_live "PHP tests" '^ *Tests:' composer run test:parallel -- "$FILTER" --colors=always
	else
		echo "Running PHP tests matching \"$FILTER\""
		run_step_live "PHP tests" '^ *Tests:' composer run test:parallel -- --filter="$FILTER" --colors=always
	fi

	echo
	print_success "Filtered run finished in $(elapsed)"
	print_warning "Static checks and JS tests skipped — run with no filter before committing."
	exit 0
fi

# ─── Full battery, cheapest first ────────────────────────────────────────────
run_step "Bundle integrity" 'Bundle check'  composer run bundle:check
run_step "Static analysis"  '\[OK\]|error'  composer run stan
run_step "Docs validation"  '^OK:|^ *OK:'   composer run docs:validate

if [ "$RUN_LINT" = true ]; then
	run_step "PHP lint" 'Time:|files|OK'    composer run lint
fi

run_step "JS tests"  '^ *Tests '            composer run test:js
run_step_live "PHP tests" '^ *Tests:'       composer run test:parallel -- --colors=always

echo
print_success "All checks passed in $(elapsed)"
