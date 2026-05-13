---
title: "Validation Patterns"
description: "Built-in validation patterns for form fields including postal codes, phone numbers, and dynamic patterns."
---

# Validation Patterns

Total CMS provides built-in validation patterns that can be used in form fields:

```twig
{{ cms.form.text('my-field', {}, {
    pattern: patterns.email,
    help: 'Please enter a valid email address'
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
patterns.ipv4                  # IPv4 address
patterns.ipv6                  # IPv6 address
patterns.domain                # Domain name
patterns.slug                  # URL-friendly slug
patterns.uuid                  # UUID format
patterns.macAddress            # MAC address
patterns.creditCard            # Credit card number
patterns.isbn                  # ISBN number
patterns.currency              # Currency format
patterns.latitudeLongitude     # Coordinates
patterns.html                  # HTML content
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

## Dynamic Patterns

```
patterns.passwordMinLength(8)  # Minimum password length
```
