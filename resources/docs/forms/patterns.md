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

The same patterns work in a schema. In a property's **Extra Schema
Definitions**, set `pattern` to the pattern's name:

```json
{ "pattern": "patterns.version" }
```

On save this expands to the real regex, anchored:

```json
{ "pattern": "^\\d+\\.\\d+\\.\\d+$" }
```

Nested patterns use their dotted path, exactly as in Twig:

```json
{ "pattern": "patterns.phone.usa" }
{ "pattern": "patterns.postCode.uk" }
```

Prefer this over pasting a regex. It is shorter, it keeps the form field and
the schema on one definition, and there are no backslashes to escape.

### Why the expansion adds anchors

The patterns above are stored **unanchored**, because an HTML `pattern`
attribute is anchored by the browser automatically.

JSON Schema's `pattern` is a substring match instead, so a bare
`\\d+\\.\\d+\\.\\d+` would accept `junk-3.5.0-junk`. The expansion wraps
`^` and `$` so a schema and a form field sharing one pattern agree on what
they accept.

### Notes

- **A literal regex is left alone.** Anything not starting with `patterns.` is
  stored as typed — that is the escape hatch if you want unanchored matching.
- **An unknown name is an error.** `patterns.verison` fails on save rather than
  being stored as a literal regex, which would compile fine and reject every
  value.
- **`passwordMinLength(8)` is not available** in a schema. Dynamic patterns are
  methods, not stored values, so they work only in Twig.
- **`notBlank` means "no whitespace anywhere"**, not "not empty" — anchored,
  `\S+` rejects `hello world`. Use `minLength` for a not-empty check.

## Dynamic Patterns

```
patterns.passwordMinLength(8)  # Minimum password length
```
