---
title: "Twig QR Code Integration"
description: "Generate QR codes in Total CMS Twig templates for URLs, vCard contacts, WiFi credentials, calendar events, GPS locations, and SMS or email links."
---

# Twig QR Code Integration

Total CMS provides comprehensive QR code generation capabilities through Twig templates using the bacon/bacon-qr-code library, supporting various data types including URLs, contact information, WiFi credentials, and more.

## Overview

The QR code integration allows you to:
- Generate QR codes for URLs, text, and structured data
- Create contact cards (vCard format) with QR codes
- Generate WiFi connection QR codes
- Create calendar events and SMS/email QR codes
- Support for location (GPS) coordinates
- Output as SVG for scalable, high-quality codes

## Basic Usage

### Simple Text and URLs

```twig
{# Basic text QR code #}
{{ qr.text('Hello World!') }}

{# Website URL #}
{{ qr.url('https://totalcms.io') }}

{# Any URL type #}
{{ qr.url('https://github.com/user/repo') }}
```

### Communication QR Codes

```twig
{# Phone number (creates tel: link) #}
{{ qr.tel('555-123-4567') }}
{{ qr.tel('+1-555-123-4567') }}

{# Email with optional subject and body #}
{{ qr.mailto('support@totalcms.io') }}
{{ qr.mailto('contact@company.com', 'Support Request', 'Please help with...') }}

{# SMS with pre-filled message #}
{{ qr.sms('555-123-4567', 'Hello from Total CMS!') }}
```

## Advanced QR Code Types

### WiFi Network Credentials

```twig
{# WiFi connection QR code #}
{{ qr.wifi('WPA', 'MyNetwork', 'password123', 'false') }}

{# Different authentication types #}
{{ qr.wifi('WEP', 'OldNetwork', 'wepkey', 'false') }}
{{ qr.wifi('nopass', 'OpenNetwork', '', 'false') }}

{# Hidden network #}
{{ qr.wifi('WPA', 'HiddenNetwork', 'secretpass', 'true') }}
```

### GPS Location Coordinates

```twig
{# Geographic coordinates #}
{{ qr.gps('40.7128', '-74.0060') }}  {# New York City #}
{{ qr.gps('51.5074', '-0.1278') }}   {# London #}

{# Using variables from collections #}
{{ qr.gps(location.latitude, location.longitude) }}
```

### Contact Information (vCard)

```twig
{# Complete contact card #}
{{ qr.vcf({
    'first': 'John',
    'last': 'Doe',
    'company': 'Total CMS Solutions',
    'street': '123 Business Ave',
    'city': 'Tech City',
    'state': 'CA',
    'zip': '90210',
    'phone': '555-123-4567',
    'mobile': '555-987-6543',
    'email': 'john.doe@totalcms.io',
    'website': 'https://totalcms.io'
}) }}

{# Minimal contact card #}
{{ qr.vcf({
    'first': 'Jane',
    'last': 'Smith',
    'email': 'jane@example.com',
    'mobile': '555-555-5555'
}) }}
```

### Calendar Events

```twig
{# Meeting or event QR code #}
{{ qr.event({
    'title': 'Team Meeting',
    'desc': 'Weekly team sync and planning session',
    'location': 'Conference Room A, Office Building',
    'start': '2024-12-01T10:00:00+00:00',
    'end': '2024-12-01T11:30:00+00:00'
}) }}

{# Conference or workshop #}
{{ qr.event({
    'title': 'Total CMS Workshop',
    'desc': 'Learn advanced Total CMS techniques and best practices',
    'location': 'Tech Conference Center, Hall B',
    'start': '2024-12-15T14:00:00+00:00',
    'end': '2024-12-15T17:00:00+00:00'
}) }}
```

## Real-World Template Examples

### Business Card Template

```twig
{# business-card.twig #}
<div class="business-card">
    <div class="card-front">
        <h1>{{ person.first }} {{ person.last }}</h1>
        <h2>{{ person.title }}</h2>
        <p>{{ person.company }}</p>

        <div class="contact-info">
            <p>📧 {{ person.email }}</p>
            <p>📱 {{ person.mobile }}</p>
            <p>🌐 {{ person.website }}</p>
        </div>
    </div>

    <div class="card-back">
        <div class="qr-code">
            {{ qr.vcf({
                'first': person.first,
                'last': person.last,
                'company': person.company,
                'email': person.email,
                'mobile': person.mobile,
                'website': person.website
            }) }}
        </div>
        <p class="qr-label">Scan to save contact</p>
    </div>
</div>
```

### Event Information Page

```twig
{# event-details.twig #}
<div class="event-page">
    <header class="event-header">
        <h1>{{ event.title }}</h1>
        <p class="event-date">{{ event.start|date('F j, Y g:i A') }}</p>
        <p class="event-location">{{ event.location }}</p>
    </header>

    <div class="event-content">
        <div class="event-description">
            {{ event.description|markdown }}
        </div>

        <div class="event-qr-codes">
            <div class="qr-section">
                <h3>📅 Add to Calendar</h3>
                {{ qr.event({
                    'title': event.title,
                    'desc': event.description,
                    'location': event.location,
                    'start': event.start,
                    'end': event.end
                }) }}
                <p>Scan to add event to your calendar</p>
            </div>

            {% if event.website %}
                <div class="qr-section">
                    <h3>🌐 Event Website</h3>
                    {{ qr.url(event.website) }}
                    <p>Scan for more information</p>
                </div>
            {% endif %}

            {% if event.location_lat and event.location_lng %}
                <div class="qr-section">
                    <h3>📍 Event Location</h3>
                    {{ qr.gps(event.location_lat, event.location_lng) }}
                    <p>Scan for directions</p>
                </div>
            {% endif %}
        </div>
    </div>
</div>
```

### Restaurant Menu with QR Codes

```twig
{# restaurant-table.twig #}
<div class="table-tent">
    <div class="front-panel">
        <h1>{{ restaurant.name }}</h1>
        <p>Table {{ table.number }}</p>

        <div class="qr-codes">
            <div class="qr-code-section">
                <h3>📱 View Menu</h3>
                {{ qr.url(restaurant.menu_url) }}
                <p>Scan to see our digital menu</p>
            </div>

            <div class="qr-code-section">
                <h3>📶 Free WiFi</h3>
                {{ qr.wifi('WPA', restaurant.wifi_name, restaurant.wifi_password, 'false') }}
                <p>Scan to connect</p>
            </div>
        </div>
    </div>

    <div class="back-panel">
        <h2>Contact Us</h2>

        <div class="contact-qr">
            {{ qr.vcf({
                'company': restaurant.name,
                'street': restaurant.address,
                'city': restaurant.city,
                'state': restaurant.state,
                'zip': restaurant.zip,
                'phone': restaurant.phone,
                'email': restaurant.email,
                'website': restaurant.website
            }) }}
            <p>Save our contact information</p>
        </div>
    </div>
</div>
```

### Product Information

```twig
{# product-qr.twig #}
{% for product in cms.collection.objects('products') %}
    <div class="product-info">
        <h2>{{ product.title }}</h2>

        <div class="product-qr-codes">
            {% if product.url %}
                <div class="qr-section">
                    <h4>🛒 Product Page</h4>
                    {{ qr.url(product.url) }}
                    <p>View online</p>
                </div>
            {% endif %}

            {% if product.manual_url %}
                <div class="qr-section">
                    <h4>📖 User Manual</h4>
                    {{ qr.url(product.manual_url) }}
                    <p>Download manual</p>
                </div>
            {% endif %}

            {% if product.support_email %}
                <div class="qr-section">
                    <h4>🆘 Support</h4>
                    {{ qr.mailto(product.support_email, 'Support Request: ' ~ product.title) }}
                    <p>Contact support</p>
                </div>
            {% endif %}
        </div>
    </div>
{% endfor %}
```

### WiFi Guest Access

```twig
{# wifi-access.twig #}
<div class="wifi-card">
    <h1>🏨 {{ hotel.name }}</h1>
    <h2>Guest WiFi Access</h2>

    <div class="wifi-info">
        <div class="wifi-details">
            <p><strong>Network:</strong> {{ hotel.wifi_name }}</p>
            <p><strong>Password:</strong> {{ hotel.wifi_password }}</p>
        </div>

        <div class="wifi-qr">
            {{ qr.wifi('WPA', hotel.wifi_name, hotel.wifi_password, 'false') }}
            <p>Scan to connect automatically</p>
        </div>
    </div>

    <div class="additional-services">
        <h3>Hotel Services</h3>

        <div class="service-qr-codes">
            <div class="qr-item">
                <h4>🏨 Hotel Website</h4>
                {{ qr.url(hotel.website) }}
            </div>

            <div class="qr-item">
                <h4>🍽️ Restaurant Menu</h4>
                {{ qr.url(hotel.menu_url) }}
            </div>

            <div class="qr-item">
                <h4>🛎️ Concierge</h4>
                {{ qr.tel(hotel.concierge_phone) }}
            </div>
        </div>
    </div>
</div>
```

### Contact Directory

```twig
{# staff-directory.twig #}
<div class="staff-directory">
    <h1>{{ company.name }} - Staff Directory</h1>

    {% for staff in cms.collection.objects('staff') %}
        <div class="staff-card">
            <div class="staff-info">
                <h3>{{ staff.first }} {{ staff.last }}</h3>
                <p class="title">{{ staff.title }}</p>
                <p class="department">{{ staff.department }}</p>

                <div class="contact-details">
                    <p>📧 {{ staff.email }}</p>
                    <p>📱 {{ staff.mobile }}</p>
                    {% if staff.office_phone %}
                        <p>☎️ {{ staff.office_phone }}</p>
                    {% endif %}
                </div>
            </div>

            <div class="staff-qr">
                {{ qr.vcf({
                    'first': staff.first,
                    'last': staff.last,
                    'company': company.name,
                    'email': staff.email,
                    'mobile': staff.mobile,
                    'phone': staff.office_phone
                }) }}
                <p>Save contact</p>
            </div>
        </div>
    {% endfor %}
</div>
```

## Dynamic QR Code Generation

### Collection-Based QR Codes

```twig
{# Dynamic contact cards from collection #}
{% for contact in cms.collection.objects('contacts') %}
    <div class="contact-card">
        <h3>{{ contact.name }}</h3>
        {{ qr.vcf({
            'first': contact.first_name,
            'last': contact.last_name,
            'company': contact.company,
            'email': contact.email,
            'mobile': contact.phone
        }) }}
    </div>
{% endfor %}

{# Event QR codes from events collection #}
{% for event in cms.collection.objects('events') %}
    <div class="event-qr">
        <h3>{{ event.title }}</h3>
        {{ qr.event({
            'title': event.title,
            'desc': event.description,
            'location': event.venue,
            'start': event.start_date,
            'end': event.end_date
        }) }}
    </div>
{% endfor %}
```

### Conditional QR Codes

```twig
{# Show appropriate QR code based on data availability #}
{% if contact.website %}
    {{ qr.url(contact.website) }}
{% elseif contact.email %}
    {{ qr.mailto(contact.email) }}
{% elseif contact.phone %}
    {{ qr.tel(contact.phone) }}
{% else %}
    {{ qr.text(contact.name) }}
{% endif %}
```

## CSS Styling for QR Codes

```css
/* QR Code container styling */
.qr-code {
    display: inline-block;
    padding: 15px;
    background: white;
    border: 2px solid #000;
    border-radius: 8px;
    text-align: center;
    margin: 10px;
}

.qr-code svg {
    display: block;
    margin: 0 auto;
    max-width: 200px;
    max-height: 200px;
}

.qr-label {
    margin-top: 10px;
    font-size: 12px;
    color: #666;
    text-align: center;
}

/* Business card QR codes */
.business-card .qr-code {
    padding: 10px;
    border: 1px solid #ccc;
}

.business-card .qr-code svg {
    max-width: 100px;
    max-height: 100px;
}

/* Print-friendly QR codes */
@media print {
    .qr-code {
        background: white !important;
        border: 1px solid black;
        page-break-inside: avoid;
    }
}

/* Responsive QR codes */
@media (max-width: 768px) {
    .qr-code svg {
        max-width: 150px;
        max-height: 150px;
    }
}
```

## Best Practices

1. **Data Validation**: Always validate URLs and data before generating QR codes
2. **Size Appropriately**: Ensure QR codes are large enough to scan reliably
3. **High Contrast**: Use dark QR codes on light backgrounds
4. **Test Scanning**: Test QR codes with various devices and apps
5. **Include Instructions**: Add clear instructions for users
6. **Error Correction**: QR codes have built-in error correction
7. **Keep It Simple**: Don't overcrowd areas around QR codes

## Security Considerations

```twig
{# Sanitize URLs before QR generation #}
{% set safe_url = url|trim|replace(' ', '') %}
{% if safe_url matches '/^https?:\\/\\/.+/' %}
    {{ qr.url(safe_url) }}
{% endif %}

{# Validate email addresses #}
{% if email matches '/^[^@]+@[^@]+\\.[^@]+$/' %}
    {{ qr.mailto(email) }}
{% endif %}
```

## Common Use Cases

- **Business Cards**: Contact information sharing
- **Events**: Calendar integration and location sharing
- **Restaurants**: Menu access and WiFi sharing
- **Hotels**: Guest services and WiFi access
- **Products**: Manuals, support, and product information
- **Marketing**: Website links and promotional content
- **Real Estate**: Property information and virtual tours
- **Education**: Course materials and contact information

The QR code integration in Total CMS provides a comprehensive solution for creating interactive, scannable codes that enhance user experience and provide seamless digital connections.