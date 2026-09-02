---
title: "Validation Patterns"
description: "Built-in validation patterns for form fields including postal codes, phone numbers, and dynamic patterns."
---

# Validation Patterns

Total CMS provides built-in validation patterns that can be used in form fields:

```twig
{{ cms.form.text('my-field', {}, {
    pattern: patterns.domain,
    help: 'Please enter a valid domain name'
}) }}
```

## Available Patterns

```
patterns.alphaNumeric          # Letters and numbers only
patterns.notBlank              # Cannot be empty
patterns.passwordUpperLowerNumber  # Must contain uppercase, lowercase, and number
patterns.date                  # Date format
patterns.time                  # Time format
patterns.dateTime              # Date and time format
patterns.integer               # Whole numbers only
patterns.decimal               # Decimal numbers
patterns.hex                   # Hexadecimal values
patterns.domain                # Domain name
patterns.slug                  # URL-friendly slug
patterns.uuid                  # UUID format
patterns.macAddress            # MAC address
patterns.isbn                  # ISBN number
patterns.currency              # Currency format
patterns.latitudeLongitude     # Coordinates
patterns.html                  # HTML content
patterns.version               # Three-part version (3.5.0)
patterns.versionExtended       # Full semver (v3.5.1-rc.1, 3.5.0+build.7)
```

## Post Code Patterns

```
patterns.postCode.australia
patterns.postCode.austria
patterns.postCode.belgium
patterns.postCode.brazil
patterns.postCode.canada
patterns.postCode.germany
patterns.postCode.hungary
patterns.postCode.italy
patterns.postCode.japan
patterns.postCode.luxembourg
patterns.postCode.netherlands
patterns.postCode.poland
patterns.postCode.spain
patterns.postCode.sweden
patterns.postCode.uk
patterns.postCode.usa
```

## Phone Patterns

```
patterns.phone.usa
patterns.phone.uk
patterns.phone.france
patterns.phone.international
```

## Using These in Schema Validation

The patterns above are stored **unanchored**, because an HTML `pattern`
attribute is anchored by the browser automatically.

JSON Schema's `pattern` keyword is not — it is a substring match. Pasting a
bare pattern into a property's **Extra Schema Definitions** gives you
validation that accepts almost anything:

```json
{ "pattern": "\\d+\\.\\d+\\.\\d+" }
```

That accepts `junk-3.5.0-junk`. Wrap it in `^` and `$` instead:

```json
{ "pattern": "^\\d+\\.\\d+\\.\\d+$" }
```

Note the doubled backslashes: `\\d` is how you write `\d` in JSON. The schema
editor's JSON field repairs a single `\d` for you, but the doubled form is
what actually gets stored.

## Dynamic Patterns

```
patterns.passwordMinLength(8)  # Minimum password length
```
