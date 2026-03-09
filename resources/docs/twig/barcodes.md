---
title: "Twig Barcode Integration"
description: "Generate barcodes in Total CMS Twig templates with 16+ formats including Code 128, EAN-13, UPC-A, QR, and DataMatrix with SVG output and styling."
---

# Twig Barcode Integration

Total CMS provides comprehensive barcode generation capabilities through Twig templates using the tecnickcom/tc-lib-barcode library, supporting 16+ different barcode formats for various use cases.

## Overview

The barcode integration allows you to:
- Generate multiple barcode formats (Code 128, EAN-13, UPC-A, etc.)
- Create product barcodes with automatic type detection
- Generate inventory and SKU codes
- Support for ISBN, medical, and library codes
- Customize barcode appearance (size, color, format)
- Output as HTML or SVG for maximum flexibility

## Supported Barcode Types

Total CMS supports 16 different barcode formats:

| Type | Code | Use Case | Data Format |
|------|------|----------|-------------|
| Code 128 | `C128` | General purpose, alphanumeric | Any text/numbers |
| Code 39 | `C39` | Industrial, automotive | Alphanumeric + symbols |
| Code 93 | `C93` | High density alphanumeric | Alphanumeric |
| EAN-13 | `EAN13` | International products | 12-13 digits |
| EAN-8 | `EAN8` | Small products | 7-8 digits |
| UPC-A | `UPCA` | US/Canada products | 11-12 digits |
| UPC-E | `UPCE` | Compressed UPC | 7-8 digits |
| Interleaved 2 of 5 | `I25` | Numeric data | Numbers only |
| Codabar | `CODABAR` | Libraries, medical | A/B/C/D + numbers |
| Code 11 | `CODE11` | Telecommunications | Numbers + dash |
| Standard 2 of 5 | `S25` | Industrial | Numbers only |
| POSTNET | `POSTNET` | US Postal Service | ZIP codes |
| PLANET | `PLANET` | US Postal tracking | Tracking numbers |
| Royal Mail 4CC | `RMS4CC` | UK postal | Postal codes |
| KIX | `KIX` | Dutch postal | Postal codes |
| Intelligent Mail | `IMB` | USPS tracking | Mail tracking |

## Basic Usage

### Simple Barcode Generation

```twig
{# Basic Code 128 barcode #}
{{ barcode.code128('HELLO123') }}

{# Product barcode with auto-detection #}
{{ barcode.product('036000291452') }}

{# Text content using best format #}
{{ barcode.text('SKU-12345') }}

{# Numeric-only content #}
{{ barcode.numeric('123456789') }}
```

### Auto-Detection Methods

Total CMS provides smart methods that automatically choose the best barcode format:

```twig
{# Auto-detects UPC-A, EAN-13, or EAN-8 based on length #}
{{ barcode.product('7622210995537') }}  {# 13 digits = EAN-13 #}
{{ barcode.product('036000291452') }}   {# 12 digits = UPC-A #}
{{ barcode.product('12345670') }}       {# 8 digits = EAN-8 #}

{# Uses Code 128 for alphanumeric data #}
{{ barcode.text('HELLO-WORLD-123') }}

{# Uses Interleaved 2 of 5 for numeric data #}
{{ barcode.numeric('987654321') }}
```

## Product Codes

### UPC and EAN Codes

```twig
{# Real product examples #}
{{ barcode.product('036000291452') }}   {# Coca-Cola 12 pack (UPC-A) #}
{{ barcode.product('7622210995537') }}  {# Toblerone bar (EAN-13) #}
{{ barcode.product('12345670') }}       {# Generic product (EAN-8) #}

{# Specific format methods #}
{{ barcode.upca('036000291452') }}      {# Force UPC-A format #}
{{ barcode.ean13('7622210995537') }}    {# Force EAN-13 format #}
{{ barcode.ean8('12345670') }}          {# Force EAN-8 format #}
```

### ISBN Book Codes

```twig
{# Famous books with real ISBN numbers #}
{{ barcode.ean13('9780140449136') }}    {# Pride and Prejudice #}
{{ barcode.ean13('9780141439518') }}    {# Jane Eyre #}
{{ barcode.ean13('9780061120084') }}    {# To Kill a Mockingbird #}

{# Using product method (auto-detects EAN-13) #}
{{ barcode.product('9780316769488') }}  {# The Catcher in the Rye #}
```

## Inventory and SKU Codes

### Alphanumeric Inventory Codes

```twig
{# Common SKU formats #}
{{ barcode.text('SKU-12345') }}
{{ barcode.text('PROD-ABC-789') }}
{{ barcode.text('ITEM-2024-001') }}
{{ barcode.text('WH-A1-B2-C3') }}

{# Complex product identifiers #}
{{ barcode.text('BATCH-A-2024-Q1') }}
{{ barcode.text('LOT-XYZ-789-2024') }}
```

### Serial Numbers

```twig
{# Pure numeric serial numbers #}
{{ barcode.numeric('123456789') }}
{{ barcode.numeric('987654321') }}
{{ barcode.numeric('555666777') }}

{# Using specific numeric format #}
{{ barcode.i25('123456789') }}          {# Interleaved 2 of 5 #}
```

## Specialized Codes

### Medical and Library Codes (Codabar)

```twig
{# Library book codes #}
{{ barcode.codabar('A123456B') }}       {# Library book #}
{{ barcode.codabar('C789012D') }}       {# Medical sample #}
{{ barcode.codabar('B555666A') }}       {# Blood bank #}

{# Start/stop characters: A, B, C, D #}
{{ barcode.codabar('D' ~ book.id ~ 'A') }}
```

### Code 39 and Code 93

```twig
{# Code 39 - supports more symbols #}
{{ barcode.code39('CODE39-TEST') }}
{{ barcode.code39('*HELLO*') }}         {# Start/stop asterisks #}

{# Code 93 - higher density #}
{{ barcode.code93('CODE93TEST') }}
{{ barcode.code93('COMPACT123') }}
```

## Custom Styling and Options

### Output Formats

```twig
{# HTML output with embedded SVG (default) #}
{{ barcode.code128('TEST123') }}

{# Pure SVG output #}
{{ barcode.code128('TEST123', {'format': 'svg'}) }}
```

### Size Customization

```twig
{# Custom width and height #}
{{ barcode.code128('TEST123', {
    'width': 3,      {# Bar width multiplier #}
    'height': 50     {# Height in pixels #}
}) }}

{# Large barcode for printing #}
{{ barcode.product('036000291452', {
    'width': 4,
    'height': 80,
    'format': 'svg'
}) }}
```

### Color Customization

```twig
{# Different colors #}
{{ barcode.text('BLUE-CODE', {'color': 'blue'}) }}
{{ barcode.text('RED-CODE', {'color': 'red'}) }}
{{ barcode.text('GREEN-CODE', {'color': '#00AA00'}) }}

{# Professional black (default) #}
{{ barcode.text('BLACK-CODE', {'color': 'black'}) }}
```

### Combined Options

```twig
{# All options together #}
{{ barcode.code128('CUSTOM-STYLE', {
    'width': 4,
    'height': 60,
    'color': 'navy',
    'format': 'svg'
}) }}
```

## Real-World Template Examples

### E-commerce Product Page

```twig
{# product-detail.twig #}
<div class="product-details">
    <h1>{{ product.title }}</h1>
    
    {% if product.upc %}
        <div class="product-barcode">
            <h4>Product Barcode</h4>
            {{ barcode.product(product.upc) }}
            <p class="upc-code">UPC: {{ product.upc }}</p>
        </div>
    {% endif %}
    
    {% if product.sku %}
        <div class="sku-barcode">
            <h4>SKU Code</h4>
            {{ barcode.text(product.sku, {
                'width': 2,
                'height': 40
            }) }}
            <p class="sku-code">SKU: {{ product.sku }}</p>
        </div>
    {% endif %}
</div>
```

### Inventory Management

```twig
{# inventory-list.twig #}
<div class="inventory-grid">
    {% for item in cms.collection.objects('inventory') %}
        <div class="inventory-card">
            <h3>{{ item.name }}</h3>
            
            <div class="barcode-section">
                {{ barcode.text(item.sku) }}
                <p>SKU: {{ item.sku }}</p>
            </div>
            
            {% if item.serial %}
                <div class="serial-section">
                    {{ barcode.numeric(item.serial) }}
                    <p>Serial: {{ item.serial }}</p>
                </div>
            {% endif %}
            
            <div class="details">
                <p>Quantity: {{ item.quantity }}</p>
                <p>Location: {{ item.location }}</p>
            </div>
        </div>
    {% endfor %}
</div>
```

### Library Book System

```twig
{# book-catalog.twig #}
{% for book in cms.collection.objects('books') %}
    <div class="book-entry">
        <div class="book-info">
            <h2>{{ book.title }}</h2>
            <p>by {{ book.author }}</p>
        </div>
        
        <div class="book-codes">
            {# ISBN barcode #}
            <div class="isbn-code">
                <h4>ISBN</h4>
                {{ barcode.ean13(book.isbn) }}
                <p>{{ book.isbn }}</p>
            </div>
            
            {# Library ID using Codabar #}
            <div class="library-code">
                <h4>Library ID</h4>
                {{ barcode.codabar('A' ~ book.library_id ~ 'B') }}
                <p>{{ book.library_id }}</p>
            </div>
        </div>
    </div>
{% endfor %}
```

### Shipping and Orders

```twig
{# shipping-label.twig #}
<div class="shipping-label">
    <div class="order-info">
        <h1>Order #{{ order.number }}</h1>
        <p>Date: {{ order.date|date('Y-m-d') }}</p>
    </div>
    
    <div class="order-barcode">
        {{ barcode.text('ORD-' ~ order.number, {
            'width': 3,
            'height': 50
        }) }}
    </div>
    
    {% if order.tracking %}
        <div class="tracking-info">
            <h3>Tracking Number</h3>
            {{ barcode.numeric(order.tracking) }}
            <p>{{ order.tracking }}</p>
        </div>
    {% endif %}
    
    <div class="shipping-address">
        <h3>Ship To:</h3>
        <p>{{ order.ship_name }}</p>
        <p>{{ order.ship_address }}</p>
        <p>{{ order.ship_city }}, {{ order.ship_state }} {{ order.ship_zip }}</p>
    </div>
</div>
```

### Medical/Laboratory Samples

```twig
{# sample-labels.twig #}
{% for sample in cms.collection.objects('lab_samples') %}
    <div class="sample-label">
        <h3>Sample ID: {{ sample.id }}</h3>
        
        <div class="sample-barcode">
            {{ barcode.codabar('C' ~ sample.id ~ 'D', {
                'width': 2,
                'height': 40
            }) }}
        </div>
        
        <div class="sample-info">
            <p>Patient: {{ sample.patient_id }}</p>
            <p>Type: {{ sample.type }}</p>
            <p>Date: {{ sample.collection_date|date('Y-m-d') }}</p>
        </div>
        
        {% if sample.batch %}
            <div class="batch-code">
                <p>Batch:</p>
                {{ barcode.text(sample.batch, {
                    'width': 1,
                    'height': 30
                }) }}
            </div>
        {% endif %}
    </div>
{% endfor %}
```

## Error Handling

### Validation Examples

```twig
{# These will throw validation errors #}
{% try %}
    {{ barcode.ean13('123') }}  {# Too short #}
{% catch %}
    <p>Invalid EAN-13 code</p>
{% endtry %}

{% try %}
    {{ barcode.numeric('ABC123') }}  {# Non-numeric #}
{% catch %}
    <p>Numeric codes require digits only</p>
{% endtry %}
```

### Safe Barcode Generation

```twig
{# Safe product code generation #}
{% if product.upc and product.upc|length >= 8 %}
    {{ barcode.product(product.upc) }}
{% else %}
    <p>Invalid or missing product code</p>
{% endif %}

{# Safe SKU generation #}
{% if item.sku %}
    {{ barcode.text(item.sku) }}
{% endif %}
```

## Advanced Usage

### Custom Barcode Types

```twig
{# Use specific barcode types directly #}
{{ barcode.custom('DATA123', 'C128', {
    'width': 3,
    'height': 45,
    'color': 'darkblue'
}) }}
```

### Getting Supported Types

```twig
{# List all supported barcode types #}
<div class="supported-types">
    <h3>Supported Barcode Types:</h3>
    <ul>
        {% for type in barcode.supportedTypes() %}
            <li>{{ type }}</li>
        {% endfor %}
    </ul>
</div>
```

### Dynamic Barcode Selection

```twig
{# Choose barcode type based on data #}
{% set code = item.identifier %}
{% if code matches '/^\\d+$/' %}
    {# Numeric only - use Interleaved 2 of 5 #}
    {{ barcode.numeric(code) }}
{% elseif code|length == 13 and code matches '/^\\d+$/' %}
    {# 13 digits - likely EAN-13 #}
    {{ barcode.ean13(code) }}
{% else %}
    {# Alphanumeric - use Code 128 #}
    {{ barcode.text(code) }}
{% endif %}
```

## CSS Styling

### Barcode Container Styling

```css
/* Style the barcode containers */
.barcode-container {
    display: inline-block;
    padding: 10px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    text-align: center;
    margin: 10px 0;
}

.barcode-container svg {
    max-width: 100%;
    height: auto;
    display: block;
    margin: 0 auto;
}

/* Print-specific styles */
@media print {
    .barcode-container {
        background: white !important;
        border: none;
        padding: 5px;
        page-break-inside: avoid;
    }
}
```

## Best Practices

1. **Choose the Right Format**: Use product codes for retail, Code 128 for general text, numeric formats for serial numbers
2. **Validate Input**: Always validate barcode data before generation
3. **Size for Purpose**: Use larger barcodes for printing, smaller for web display
4. **High Contrast**: Use dark colors on light backgrounds for best scanning
5. **Test Scanning**: Always test generated barcodes with actual scanners
6. **Error Handling**: Implement proper error handling for invalid data
7. **Performance**: Cache generated barcodes for frequently accessed items

## Common Use Cases

- **Retail**: Product codes, inventory management, price tags
- **Libraries**: Book tracking, patron cards, equipment checkout
- **Healthcare**: Patient identification, sample tracking, medication labels
- **Manufacturing**: Part numbers, quality control, batch tracking
- **Shipping**: Package tracking, routing codes, delivery confirmation
- **Events**: Ticket validation, access control, attendee tracking

The barcode integration in Total CMS provides a comprehensive solution for generating professional, scannable barcodes for any application.