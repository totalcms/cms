#!/bin/bash

# Start the Stripe test environment for the dev site.
#
# Stripe cannot reach a .test site, so its CLI holds a connection open to
# Stripe and forwards each webhook event here. This script:
#   1. reads the test secret key the Stripe extension has saved, so the CLI
#      listens to the same sandbox the site charges
#   2. asks the CLI for its webhook signing secret and saves it in the
#      extension's settings when it is not the one already there
#   3. forwards events to the site until you stop it with Ctrl-C
#
# It works with test keys only, and never prints a key or a secret.
#
# Usage:
#   bin/stripe-test.sh                # set up, then forward events
#   bin/stripe-test.sh --check        # set up and report; forward nothing
#   bin/stripe-test.sh --skip-verify  # anything else goes to `stripe listen`
#   SITE_URL=https://other.test bin/stripe-test.sh

SITE_URL="${SITE_URL:-https://totalcms.test}"
DATA_DIR="${TCMS_DATA_DIR:-tcms-data}"
SETTINGS="$DATA_DIR/.system/extension-settings/totalcms/stripe.json"
SITE_SETTINGS="$DATA_DIR/.system/settings.json"
WEBHOOK="$SITE_URL/commerce/webhook/stripe"

check_only=0
if [ "$1" = "--check" ]; then
    check_only=1
    shift
fi

fail() {
    echo "✗ $1" >&2
    exit 1
}

command -v stripe >/dev/null || fail "The Stripe CLI is not installed: brew install stripe/stripe-cli/stripe"
[ -f "$SETTINGS" ] || fail "No Stripe settings at $SETTINGS. Enable the Stripe extension and save its settings first."

# One setting of the Stripe extension, or nothing when it is not set.
setting() {
    SETTINGS="$SETTINGS" KEY="$1" php -r '
        $settings = json_decode((string)file_get_contents(getenv("SETTINGS")), true);
        $value    = is_array($settings) ? ($settings[getenv("KEY")] ?? "") : "";
        echo is_string($value) ? $value : "";
    '
}

# With the extension in live mode the site would charge real cards while this
# forwarded sandbox events, so nothing here would match.
mode=$(setting mode)
[ "$mode" = "test" ] || fail "The Stripe extension is in \"$mode\" mode. Set Mode to Test in its settings."

key=$(setting testSecretKey)
[ -n "$key" ] || fail "No Test Secret Key is saved in the Stripe extension's settings."
case "$key" in
    sk_test_*|rk_test_*) ;;
    *) fail "The Test Secret Key is not a test key (it does not start with sk_test_). Nothing was started." ;;
esac
echo "✓ Test secret key found"

# The CLI reads the key from here. Without it, it would listen to whichever
# account `stripe login` chose, which may not be the sandbox the site uses.
export STRIPE_API_KEY="$key"

secret=$(stripe listen --print-secret 2>/dev/null)
case "$secret" in
    whsec_*) ;;
    *) fail "The Stripe CLI gave no signing secret. Run this to see why: STRIPE_API_KEY=… stripe listen --print-secret" ;;
esac

if [ "$secret" = "$(setting testWebhookSecret)" ]; then
    echo "✓ Webhook signing secret already saved"
else
    SETTINGS="$SETTINGS" SECRET="$secret" php -r '
        $path     = getenv("SETTINGS");
        $settings = json_decode((string)file_get_contents($path), true);
        if (!is_array($settings)) {
            exit(1);
        }
        $settings["testWebhookSecret"] = getenv("SECRET");
        exit(file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false ? 1 : 0);
    ' || fail "Could not save the signing secret to $SETTINGS"
    echo "✓ Webhook signing secret saved as the Test Webhook Signing Secret"
fi

# The shop offers Stripe only when it is one of the chosen providers.
chosen=$(SITE_SETTINGS="$SITE_SETTINGS" php -r '
    $settings  = json_decode((string)@file_get_contents(getenv("SITE_SETTINGS")), true);
    $providers = is_array($settings) ? ($settings["commerce"]["providers"] ?? []) : [];
    echo is_array($providers) && in_array("stripe", $providers, true) ? "yes" : "no";
')
if [ "$chosen" = "yes" ]; then
    echo "✓ Stripe is a chosen payment provider"
else
    echo "! Stripe is not ticked in Settings → Commerce → Payment Providers, so the shop cannot take a payment yet."
fi

if [ "$check_only" = 1 ]; then
    echo ""
    echo "Ready. Run bin/stripe-test.sh to forward events to $WEBHOOK"
    exit 0
fi

echo ""
echo "Forwarding Stripe test events to $WEBHOOK"
echo "Test card: 4242 4242 4242 4242, any future date, any CVC. Ctrl-C stops it."
echo ""

exec stripe listen --all-snapshot --forward-to "$WEBHOOK" "$@"
