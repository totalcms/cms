# Total CMS Extension Development Guide

This guide provides comprehensive documentation for developers who want to extend Total CMS with custom collections, field types, and functionality.

## Table of Contents

1. [Overview](#overview)
2. [Extension Architecture](#extension-architecture)
3. [Custom Collection Types](#custom-collection-types)
4. [Custom Field Types](#custom-field-types)
5. [Schema Development](#schema-development)
6. [Property Data Classes](#property-data-classes)
7. [Form Field Components](#form-field-components)
8. [JavaScript Extensions](#javascript-extensions)
9. [Template Extensions](#template-extensions)
10. [Plugin Development](#plugin-development)
11. [Best Practices](#best-practices)
12. [Testing Extensions](#testing-extensions)

## Overview

Total CMS is designed with extensibility at its core, allowing developers to:

- Create custom collection types for specific content needs
- Develop custom field types with specialized behavior
- Extend the admin interface with new components
- Add custom Twig functions and filters
- Integrate with external services and APIs

### Extension Points

Total CMS provides several extension points:

- **Schema System**: JSON Schema-based field definitions
- **Property Data Classes**: Backend data processing and validation
- **Form Field Components**: Admin interface field rendering
- **JavaScript Components**: Frontend behavior and interaction
- **Twig Extensions**: Template functions and filters
- **Service Layer**: Business logic and data processing

## Extension Architecture

### Core Components

```
Extension System
├── Schema Definition (JSON)
├── Property Data Class (PHP)
├── Form Field Component (PHP)
├── JavaScript Component (JS)
├── Template Integration (Twig)
└── Service Integration (PHP)
```

### Directory Structure

```
your-extension/
├── schemas/
│   └── custom-field.json
├── src/
│   ├── Property/
│   │   └── CustomFieldData.php
│   ├── FormField/
│   │   └── CustomFormField.php
│   └── Service/
│       └── CustomFieldService.php
├── javascript/
│   └── custom-field.js
├── templates/
│   └── custom-field.twig
└── tests/
    └── CustomFieldTest.php
```

## Custom Collection Types

### Creating a New Collection Type

#### 1. Define the Schema

Create a JSON schema file that defines your collection structure:

```json
// schemas/product.json
{
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "$id": "https://yoursite.com/schemas/product.json",
    "id": "product",
    "type": "object",
    "description": "E-commerce product schema",
    "properties": {
        "id": {
            "$ref": "https://www.totalcms.co/schemas/properties/slug.json",
            "label": "Product ID",
            "help": "Unique product identifier",
            "field": "id",
            "settings": { "autogen": "${name}" }
        },
        "name": {
            "type": "string",
            "label": "Product Name",
            "help": "The display name of the product",
            "field": "text",
            "factory": "text",
            "maxLength": 100
        },
        "price": {
            "type": "number",
            "label": "Price",
            "help": "Product price in USD",
            "field": "number",
            "factory": "number",
            "minimum": 0,
            "settings": {
                "step": 0.01,
                "min": 0
            }
        },
        "description": {
            "type": "string",
            "label": "Description",
            "help": "Detailed product description",
            "field": "styledtext",
            "factory": "styledtext"
        },
        "images": {
            "$ref": "https://www.totalcms.co/schemas/properties/gallery.json",
            "label": "Product Images",
            "help": "Upload product photos",
            "field": "gallery",
            "factory": "gallery"
        },
        "category": {
            "type": "string",
            "label": "Category",
            "help": "Product category",
            "field": "select",
            "factory": "text",
            "enum": ["electronics", "clothing", "books", "home"],
            "settings": {
                "options": [
                    {"value": "electronics", "label": "Electronics"},
                    {"value": "clothing", "label": "Clothing"},
                    {"value": "books", "label": "Books"},
                    {"value": "home", "label": "Home & Garden"}
                ]
            }
        },
        "inStock": {
            "type": "boolean",
            "label": "In Stock",
            "help": "Is this product currently available?",
            "field": "toggle",
            "factory": "boolean",
            "default": true
        },
        "specifications": {
            "type": "object",
            "label": "Specifications",
            "help": "Technical specifications",
            "field": "properties",
            "factory": "object",
            "additionalProperties": true
        }
    },
    "required": ["id", "name", "price"],
    "additionalProperties": false
}
```

#### 2. Register the Collection Type

```php
// config/collections.php
return [
    'product' => [
        'schema' => 'product',
        'label' => 'Products',
        'description' => 'E-commerce product catalog',
        'icon' => 'shopping-cart',
        'permissions' => ['admin', 'editor'],
        'features' => [
            'search' => true,
            'export' => true,
            'import' => true,
            'feeds' => true
        ]
    ]
];
```

#### 3. Create Collection Factory

```php
// src/Domain/Product/Service/ProductFactory.php
namespace TotalCMS\Domain\Product\Service;

use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Product\Data\ProductData;

final class ProductFactory extends CollectionFactory
{
    public function createProduct(array $data): ProductData
    {
        $collection = $this->generateCollection($data);
        
        return new ProductData(
            id: $collection->getId(),
            name: $data['name'] ?? '',
            price: $data['price'] ?? 0.0,
            description: $data['description'] ?? '',
            images: $data['images'] ?? [],
            category: $data['category'] ?? '',
            inStock: $data['inStock'] ?? true,
            specifications: $data['specifications'] ?? []
        );
    }
}
```

### Collection Configuration

#### Advanced Schema Features

```json
{
    "properties": {
        "tags": {
            "type": "array",
            "label": "Tags",
            "field": "multiselect",
            "items": {
                "type": "string"
            },
            "settings": {
                "addChoices": true,
                "removeItemButton": true,
                "maxItemCount": 10
            }
        },
        "relatedProducts": {
            "type": "array",
            "label": "Related Products",
            "field": "list",
            "settings": {
                "relationalOptions": {
                    "collection": "product",
                    "label": "name",
                    "value": "id"
                }
            }
        }
    }
}
```

## Custom Field Types

### Creating a Custom Field Type

#### 1. Property Data Class

Create a data class that handles backend processing:

```php
// src/Domain/Property/Data/CurrencyData.php
namespace TotalCMS\Domain\Property\Data;

final class CurrencyData implements PropertyDataInterface
{
    private float $amount;
    private string $currency;

    public function __construct(mixed $value)
    {
        if (is_array($value)) {
            $this->amount = (float)($value['amount'] ?? 0);
            $this->currency = $value['currency'] ?? 'USD';
        } else {
            $this->amount = (float)$value;
            $this->currency = 'USD';
        }
    }

    public function getValue(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->getFormattedValue()
        ];
    }

    public function getFormattedValue(): string
    {
        $formatter = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        return $formatter->formatCurrency($this->amount, $this->currency);
    }

    public function isValid(): bool
    {
        return $this->amount >= 0 && 
               in_array($this->currency, ['USD', 'EUR', 'GBP', 'JPY']);
    }

    public function __toString(): string
    {
        return $this->getFormattedValue();
    }
}
```

#### 2. Form Field Component

Create an admin form field component:

```php
// src/Domain/Admin/FormField/CurrencyField.php
namespace TotalCMS\Domain\Admin\FormField;

final class CurrencyField extends FormField
{
    protected string $template = 'form-fields/currency.twig';

    public function render(): string
    {
        $currencies = [
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'JPY' => 'Japanese Yen'
        ];

        $value = $this->getValue();
        $amount = is_array($value) ? $value['amount'] ?? 0 : (float)$value;
        $currency = is_array($value) ? $value['currency'] ?? 'USD' : 'USD';

        return $this->templateEngine->render($this->template, [
            'field' => $this,
            'amount' => $amount,
            'currency' => $currency,
            'currencies' => $currencies,
            'name' => $this->name,
            'id' => $this->getId(),
            'label' => $this->label,
            'help' => $this->help,
            'required' => $this->required,
            'readonly' => $this->readonly,
            'settings' => $this->settings
        ]);
    }

    protected function processValue(mixed $value): array
    {
        if (is_array($value)) {
            return [
                'amount' => (float)($value['amount'] ?? 0),
                'currency' => $value['currency'] ?? 'USD'
            ];
        }
        
        return [
            'amount' => (float)$value,
            'currency' => 'USD'
        ];
    }
}
```

#### 3. Twig Template

Create the form field template:

```twig
{# templates/form-fields/currency.twig #}
<div class="form-field currency-field" data-field-type="currency">
    <label for="{{ id }}_amount">{{ label }}</label>
    
    <div class="currency-input-group">
        <input 
            type="number" 
            id="{{ id }}_amount"
            name="{{ name }}[amount]" 
            value="{{ amount }}"
            step="0.01"
            min="0"
            {% if required %}required{% endif %}
            {% if readonly %}readonly{% endif %}
            class="currency-amount"
        />
        
        <select 
            id="{{ id }}_currency"
            name="{{ name }}[currency]"
            {% if readonly %}disabled{% endif %}
            class="currency-select"
        >
            {% for code, name in currencies %}
                <option value="{{ code }}" {% if currency == code %}selected{% endif %}>
                    {{ code }} - {{ name }}
                </option>
            {% endfor %}
        </select>
    </div>
    
    {% if help %}
        <small class="help-text">{{ help }}</small>
    {% endif %}
</div>
```

#### 4. JavaScript Component

Create the frontend behavior:

```javascript
// javascript/totalform/currency.js
import TotalField from './totalfield';

export default class CurrencyField extends TotalField {
    constructor(container, options) {
        super(container, options);
        
        this.amountInput = container.querySelector('.currency-amount');
        this.currencySelect = container.querySelector('.currency-select');
        
        this.bindEvents();
    }

    bindEvents() {
        super.bindEvents();
        
        this.amountInput.addEventListener('input', () => {
            this.updateFormattedDisplay();
            this.dispatch('field-change', this.getValue());
        });
        
        this.currencySelect.addEventListener('change', () => {
            this.updateFormattedDisplay();
            this.dispatch('field-change', this.getValue());
        });
    }

    getValue() {
        return {
            amount: parseFloat(this.amountInput.value) || 0,
            currency: this.currencySelect.value
        };
    }

    setValue(value) {
        if (typeof value === 'object' && value !== null) {
            this.amountInput.value = value.amount || 0;
            this.currencySelect.value = value.currency || 'USD';
        } else {
            this.amountInput.value = parseFloat(value) || 0;
            this.currencySelect.value = 'USD';
        }
        
        this.updateFormattedDisplay();
    }

    updateFormattedDisplay() {
        const value = this.getValue();
        const formatter = new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: value.currency
        });
        
        const formatted = formatter.format(value.amount);
        
        // Update any formatted display elements
        const display = this.container.querySelector('.currency-display');
        if (display) {
            display.textContent = formatted;
        }
    }

    validate() {
        const value = this.getValue();
        const isValid = value.amount >= 0 && 
                       ['USD', 'EUR', 'GBP', 'JPY'].includes(value.currency);
        
        this.setValidationState(isValid);
        return isValid;
    }
}
```

#### 5. Register the Field Type

```php
// config/field-types.php
return [
    'currency' => [
        'data_class' => CurrencyData::class,
        'form_field' => CurrencyField::class,
        'javascript_component' => 'currency',
        'schema_type' => 'object',
        'label' => 'Currency',
        'description' => 'Amount with currency selection',
        'icon' => 'dollar-sign'
    ]
];
```

## Schema Development

### Advanced Schema Patterns

#### Conditional Fields

```json
{
    "properties": {
        "type": {
            "type": "string",
            "enum": ["physical", "digital"],
            "field": "select"
        },
        "weight": {
            "type": "number",
            "field": "number",
            "if": {
                "properties": { "type": { "const": "physical" } }
            },
            "then": { "required": ["weight"] }
        },
        "downloadUrl": {
            "type": "string",
            "field": "url",
            "if": {
                "properties": { "type": { "const": "digital" } }
            },
            "then": { "required": ["downloadUrl"] }
        }
    }
}
```

#### Dynamic Validation

```json
{
    "properties": {
        "price": {
            "type": "number",
            "field": "currency",
            "settings": {
                "validation": {
                    "custom": "validatePrice",
                    "message": "Price must be competitive"
                }
            }
        }
    }
}
```

#### Nested Objects

```json
{
    "properties": {
        "address": {
            "type": "object",
            "field": "properties",
            "properties": {
                "street": { "type": "string", "field": "text" },
                "city": { "type": "string", "field": "text" },
                "state": { "type": "string", "field": "text" },
                "zipCode": { "type": "string", "field": "text" },
                "country": { 
                    "type": "string", 
                    "field": "select",
                    "enum": ["US", "CA", "MX"]
                }
            },
            "required": ["street", "city", "country"]
        }
    }
}
```

### Schema Validation

```php
// src/Domain/Schema/Service/CustomSchemaValidator.php
namespace TotalCMS\Domain\Schema\Service;

use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;

final class CustomSchemaValidator extends SchemaValidator
{
    public function validateWithCustomRules(array $data, SchemaData $schema): array
    {
        $errors = parent::validate($data, $schema);
        
        // Add custom validation rules
        $errors = array_merge($errors, $this->validateBusinessRules($data, $schema));
        
        return $errors;
    }

    private function validateBusinessRules(array $data, SchemaData $schema): array
    {
        $errors = [];
        
        // Example: Validate price competitiveness
        if (isset($data['price']) && $schema->getId() === 'product') {
            if (!$this->isPriceCompetitive($data['price'], $data['category'] ?? '')) {
                $errors[] = 'Price is not competitive in this category';
            }
        }
        
        return $errors;
    }

    private function isPriceCompetitive(float $price, string $category): bool
    {
        // Implementation for price validation
        return true; // Simplified
    }
}
```

## Property Data Classes

### Advanced Property Processing

```php
// src/Domain/Property/Data/GeoLocationData.php
namespace TotalCMS\Domain\Property\Data;

final class GeoLocationData implements PropertyDataInterface
{
    private float $latitude;
    private float $longitude;
    private ?string $address;
    private ?array $metadata;

    public function __construct(mixed $value)
    {
        if (is_array($value)) {
            $this->latitude = (float)($value['lat'] ?? 0);
            $this->longitude = (float)($value['lng'] ?? 0);
            $this->address = $value['address'] ?? null;
            $this->metadata = $value['metadata'] ?? null;
        } elseif (is_string($value)) {
            // Parse from string format "lat,lng"
            $coords = explode(',', $value);
            $this->latitude = (float)($coords[0] ?? 0);
            $this->longitude = (float)($coords[1] ?? 0);
        }
        
        // Geocode if address provided but no coordinates
        if ($this->address && (!$this->latitude && !$this->longitude)) {
            $this->geocodeAddress();
        }
    }

    public function getValue(): array
    {
        return [
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'address' => $this->address,
            'metadata' => $this->metadata,
            'formatted' => $this->getFormattedCoordinates()
        ];
    }

    public function getDistanceTo(GeoLocationData $other): float
    {
        // Haversine formula for distance calculation
        $earthRadius = 6371; // km
        
        $latFrom = deg2rad($this->latitude);
        $lonFrom = deg2rad($this->longitude);
        $latTo = deg2rad($other->latitude);
        $lonTo = deg2rad($other->longitude);
        
        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;
        
        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos($latFrom) * cos($latTo) *
             sin($lonDelta / 2) * sin($lonDelta / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }

    private function geocodeAddress(): void
    {
        // Implementation for geocoding service
        // This would typically call an external API
    }

    public function isValid(): bool
    {
        return $this->latitude >= -90 && $this->latitude <= 90 &&
               $this->longitude >= -180 && $this->longitude <= 180;
    }
}
```

### Property Services

```php
// src/Domain/Property/Service/GeoLocationService.php
namespace TotalCMS\Domain\Property\Service;

final class GeoLocationService
{
    public function findNearby(GeoLocationData $center, float $radiusKm): array
    {
        // Find objects within radius
        $results = [];
        
        // Query all objects with geo location data
        // Calculate distances and filter by radius
        
        return $results;
    }

    public function geocodeAddress(string $address): ?GeoLocationData
    {
        // Geocoding service integration
        return null;
    }

    public function reverseGeocode(float $lat, float $lng): ?string
    {
        // Reverse geocoding service integration
        return null;
    }
}
```

## Form Field Components

### Advanced Form Fields

```php
// src/Domain/Admin/FormField/MapField.php
namespace TotalCMS\Domain\Admin\FormField;

final class MapField extends FormField
{
    protected string $template = 'form-fields/map.twig';

    public function render(): string
    {
        $value = $this->getValue();
        $coordinates = is_array($value) ? $value : ['lat' => 0, 'lng' => 0];

        return $this->templateEngine->render($this->template, [
            'field' => $this,
            'coordinates' => $coordinates,
            'mapApiKey' => $this->getMapApiKey(),
            'defaultZoom' => $this->settings['zoom'] ?? 10,
            'mapStyle' => $this->settings['style'] ?? 'roadmap'
        ]);
    }

    private function getMapApiKey(): string
    {
        return $_ENV['GOOGLE_MAPS_API_KEY'] ?? '';
    }

    public function getAssets(): array
    {
        return [
            'scripts' => [
                'https://maps.googleapis.com/maps/api/js?key=' . $this->getMapApiKey(),
                '/assets/map-field.js'
            ],
            'styles' => [
                '/assets/map-field.css'
            ]
        ];
    }
}
```

## JavaScript Extensions

### Advanced JavaScript Components

```javascript
// javascript/totalform/map.js
import TotalField from './totalfield';

export default class MapField extends TotalField {
    constructor(container, options) {
        super(container, options);
        
        this.mapContainer = container.querySelector('.map-container');
        this.latInput = container.querySelector('[name$="[lat]"]');
        this.lngInput = container.querySelector('[name$="[lng]"]');
        this.addressInput = container.querySelector('.address-input');
        
        this.initializeMap();
        this.bindEvents();
    }

    async initializeMap() {
        await this.loadGoogleMaps();
        
        const lat = parseFloat(this.latInput.value) || 0;
        const lng = parseFloat(this.lngInput.value) || 0;
        
        this.map = new google.maps.Map(this.mapContainer, {
            center: { lat, lng },
            zoom: this.options.zoom || 10,
            mapTypeId: this.options.style || 'roadmap'
        });
        
        this.marker = new google.maps.Marker({
            position: { lat, lng },
            map: this.map,
            draggable: true
        });
        
        this.marker.addListener('dragend', () => {
            const position = this.marker.getPosition();
            this.updateCoordinates(position.lat(), position.lng());
        });
        
        this.map.addListener('click', (event) => {
            const lat = event.latLng.lat();
            const lng = event.latLng.lng();
            this.marker.setPosition({ lat, lng });
            this.updateCoordinates(lat, lng);
        });
    }

    loadGoogleMaps() {
        return new Promise((resolve) => {
            if (window.google && window.google.maps) {
                resolve();
                return;
            }
            
            window.initGoogleMaps = resolve;
            const script = document.createElement('script');
            script.src = `https://maps.googleapis.com/maps/api/js?key=${this.options.apiKey}&callback=initGoogleMaps`;
            document.head.appendChild(script);
        });
    }

    bindEvents() {
        super.bindEvents();
        
        this.latInput.addEventListener('change', () => this.updateMapFromInputs());
        this.lngInput.addEventListener('change', () => this.updateMapFromInputs());
        
        if (this.addressInput) {
            this.addressInput.addEventListener('blur', () => {
                this.geocodeAddress(this.addressInput.value);
            });
        }
    }

    updateCoordinates(lat, lng) {
        this.latInput.value = lat.toFixed(6);
        this.lngInput.value = lng.toFixed(6);
        
        this.dispatch('field-change', this.getValue());
        this.reverseGeocode(lat, lng);
    }

    updateMapFromInputs() {
        const lat = parseFloat(this.latInput.value) || 0;
        const lng = parseFloat(this.lngInput.value) || 0;
        
        const position = { lat, lng };
        this.map.setCenter(position);
        this.marker.setPosition(position);
    }

    async geocodeAddress(address) {
        if (!address) return;
        
        const geocoder = new google.maps.Geocoder();
        
        geocoder.geocode({ address }, (results, status) => {
            if (status === 'OK' && results[0]) {
                const location = results[0].geometry.location;
                this.updateCoordinates(location.lat(), location.lng());
                this.map.setCenter(location);
            }
        });
    }

    async reverseGeocode(lat, lng) {
        const geocoder = new google.maps.Geocoder();
        
        geocoder.geocode({ location: { lat, lng } }, (results, status) => {
            if (status === 'OK' && results[0] && this.addressInput) {
                this.addressInput.value = results[0].formatted_address;
            }
        });
    }

    getValue() {
        return {
            lat: parseFloat(this.latInput.value) || 0,
            lng: parseFloat(this.lngInput.value) || 0,
            address: this.addressInput ? this.addressInput.value : null
        };
    }

    setValue(value) {
        if (typeof value === 'object' && value !== null) {
            this.latInput.value = value.lat || 0;
            this.lngInput.value = value.lng || 0;
            
            if (this.addressInput && value.address) {
                this.addressInput.value = value.address;
            }
            
            if (this.map) {
                this.updateMapFromInputs();
            }
        }
    }
}
```

## Template Extensions

### Custom Twig Functions

```php
// src/Domain/Twig/CustomTwigExtension.php
namespace TotalCMS\Domain\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

final class CustomTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nearby_locations', [$this, 'getNearbyLocations']),
            new TwigFunction('format_currency', [$this, 'formatCurrency']),
            new TwigFunction('weather_data', [$this, 'getWeatherData'])
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('distance_to', [$this, 'calculateDistance']),
            new TwigFilter('format_coordinates', [$this, 'formatCoordinates'])
        ];
    }

    public function getNearbyLocations(array $center, float $radius = 10): array
    {
        // Implementation to find nearby locations
        return [];
    }

    public function formatCurrency(float $amount, string $currency = 'USD'): string
    {
        $formatter = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        return $formatter->formatCurrency($amount, $currency);
    }

    public function getWeatherData(float $lat, float $lng): array
    {
        // Integration with weather API
        return [];
    }

    public function calculateDistance(array $from, array $to): float
    {
        // Distance calculation between two points
        return 0.0;
    }

    public function formatCoordinates(array $coords): string
    {
        return sprintf('%.6f, %.6f', $coords['lat'], $coords['lng']);
    }
}
```

### Template Usage

```twig
{# Display nearby products #}
{% set current_location = object.location %}
{% set nearby = nearby_locations(current_location, 25) %}

<h3>Nearby Locations</h3>
<ul>
{% for location in nearby %}
    <li>
        {{ location.name }} - 
        {{ current_location|distance_to(location.coordinates) }} km away
    </li>
{% endfor %}
</ul>

{# Format currency #}
<p>Price: {{ format_currency(product.price, product.currency) }}</p>

{# Display coordinates #}
<p>Location: {{ object.location|format_coordinates }}</p>
```

## Plugin Development

### Plugin Structure

```php
// src/Plugin/EcommercePlugin.php
namespace TotalCMS\Plugin;

use TotalCMS\Plugin\PluginInterface;

final class EcommercePlugin implements PluginInterface
{
    public function register(): void
    {
        // Register services
        $this->registerServices();
        
        // Register event listeners
        $this->registerEventListeners();
        
        // Register custom field types
        $this->registerFieldTypes();
        
        // Register Twig extensions
        $this->registerTwigExtensions();
    }

    private function registerServices(): void
    {
        // Payment processing services
        // Inventory management
        // Order processing
    }

    private function registerEventListeners(): void
    {
        // Listen for object creation/updates
        // Handle inventory updates
        // Send notifications
    }

    private function registerFieldTypes(): void
    {
        // Currency field
        // Product variations
        // Shipping options
    }

    public function getRoutes(): array
    {
        return [
            '/api/payment/process' => PaymentProcessAction::class,
            '/api/inventory/check' => InventoryCheckAction::class,
            '/webhook/payment/callback' => PaymentCallbackAction::class
        ];
    }

    public function getMigrations(): array
    {
        return [
            '001_create_orders_table.sql',
            '002_create_payment_logs_table.sql'
        ];
    }
}
```

## Best Practices

### Performance Considerations

1. **Lazy Loading**: Load heavy components only when needed
2. **Caching**: Cache computed values and external API calls
3. **Validation**: Validate data early and efficiently
4. **Memory Management**: Clean up resources properly

### Security Guidelines

1. **Input Validation**: Always validate and sanitize user input
2. **Output Encoding**: Encode output to prevent XSS
3. **Access Control**: Implement proper permission checks
4. **Data Encryption**: Encrypt sensitive data

### Code Organization

1. **Single Responsibility**: Each class should have one clear purpose
2. **Interface Segregation**: Use specific interfaces for different concerns
3. **Dependency Injection**: Use DI for testable, maintainable code
4. **Documentation**: Document public APIs and complex logic

## Testing Extensions

### Unit Testing

```php
// tests/Unit/Property/CurrencyDataTest.php
namespace Tests\Unit\Property;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\CurrencyData;

final class CurrencyDataTest extends TestCase
{
    public function testCreatesFromArray(): void
    {
        $data = new CurrencyData([
            'amount' => 99.99,
            'currency' => 'EUR'
        ]);
        
        $value = $data->getValue();
        
        $this->assertEquals(99.99, $value['amount']);
        $this->assertEquals('EUR', $value['currency']);
        $this->assertStringContains('€', $value['formatted']);
    }

    public function testValidatesCorrectly(): void
    {
        $validData = new CurrencyData(['amount' => 50, 'currency' => 'USD']);
        $invalidData = new CurrencyData(['amount' => -10, 'currency' => 'INVALID']);
        
        $this->assertTrue($validData->isValid());
        $this->assertFalse($invalidData->isValid());
    }
}
```

### Integration Testing

```php
// tests/Feature/CustomFieldTest.php
namespace Tests\Feature;

use function Nekofar\Slim\Pest\postJson;

beforeEach(function (): void {
    $this->setUpApp(bootstrap());
});

it('saves object with custom currency field', function (): void {
    $objectData = [
        'id' => 'test-product',
        'name' => 'Test Product',
        'price' => [
            'amount' => 29.99,
            'currency' => 'EUR'
        ]
    ];
    
    postJson('/collections/product', $objectData)
        ->assertCreated()
        ->assertJsonFragment([
            'price' => [
                'amount' => 29.99,
                'currency' => 'EUR'
            ]
        ]);
});
```

---

This extension development guide provides the foundation for creating powerful, maintainable extensions for Total CMS. Follow these patterns and best practices to build robust custom functionality that integrates seamlessly with the core system.