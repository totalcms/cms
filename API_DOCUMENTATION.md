# Total CMS API Documentation

This document provides comprehensive API documentation for Total CMS, automatically generated from code analysis.

## Table of Contents

1. [Overview](#overview)
2. [Authentication](#authentication)
3. [Core Components](#core-components)
4. [Actions (Controllers)](#actions-controllers)
5. [Domain Services](#domain-services)
6. [Data Models](#data-models)
7. [Middleware](#middleware)
8. [Utilities](#utilities)

## Overview

Total CMS follows a clean architecture pattern with clear separation of concerns:

- **Actions**: HTTP request handlers (equivalent to controllers)
- **Domain**: Business logic and services
- **Middleware**: Request/response processing pipeline
- **Utilities**: Helper classes and tools

## Authentication

### Session Management
- **Location**: `src/Domain/Session/`
- **Primary Class**: Uses `Odan\Session\PhpSession` for session handling
- **CSRF Protection**: `src/Utils/CSRFTokenManager.php`

### API Authentication
- **Bearer Token**: Support for API key authentication
- **Session-based**: Cookie-based authentication for admin interface
- **Middleware**: `src/Middleware/AuthMiddleware.php`

## Core Components

### Configuration System
- **Location**: `config/`
- **Pattern**: Hierarchical PHP configuration files
- **Environment**: Support for environment-specific configs

### Dependency Injection
- **Container**: PHP-DI 7 with PSR-11 compliance
- **Configuration**: `config/container.php`
- **Services**: Auto-wired dependency injection

## Actions (Controllers)

### Admin Actions
Located in `src/Action/Admin/`

#### Collection Management
- `CollectionCreateAction` - Create new collections
- `CollectionDeleteAction` - Delete collections
- `CollectionExportAction` - Export collection data
- `CollectionGetAction` - Retrieve collection information
- `CollectionHomeAction` - Collection dashboard
- `CollectionImportAction` - Import collection data
- `CollectionIndexAction` - List collections
- `CollectionObjectCreateAction` - Create objects in collections
- `CollectionObjectDeleteAction` - Delete collection objects
- `CollectionObjectEditAction` - Edit collection objects
- `CollectionObjectGetAction` - Retrieve collection objects

#### Schema Management
- `SchemaCreateAction` - Create new schemas
- `SchemaDeleteAction` - Delete schemas
- `SchemaExportAction` - Export schema definitions
- `SchemaGetAction` - Retrieve schema information
- `SchemaHomeAction` - Schema dashboard
- `SchemaImportAction` - Import schema definitions
- `SchemaNewAction` - Schema creation form

#### System Actions
- `DashboardAction` - Main admin dashboard
- `DocsAction` - Documentation viewer
- `LoginAction` - Authentication
- `ProfileAction` - User profile management
- `SettingsAction` - System settings

### API Actions
Located in `src/Action/Api/`

#### Collection API
- `CollectionCreateAction` - REST API for collection creation
- `CollectionDeleteAction` - REST API for collection deletion
- `CollectionGetAction` - REST API for collection retrieval
- `CollectionIndexAction` - REST API for collection listing
- `CollectionUpdateAction` - REST API for collection updates

#### Object API
- `ObjectCreateAction` - REST API for object creation
- `ObjectDeleteAction` - REST API for object deletion
- `ObjectGetAction` - REST API for object retrieval
- `ObjectIndexAction` - REST API for object listing
- `ObjectUpdateAction` - REST API for object updates

#### Property API
- `PropertyCreateAction` - REST API for property creation
- `PropertyDeleteAction` - REST API for property deletion
- `PropertyGetAction` - REST API for property retrieval
- `PropertyUpdateAction` - REST API for property updates

### File Management Actions
Located in `src/Action/`

- `DownloadAction` - File download handling
- `ImageWorksAction` - Image processing and manipulation
- `UploadAction` - File upload processing

## Domain Services

### Collection Services
Located in `src/Domain/Collection/`

#### Core Services
- `CollectionService` - Business logic for collections
- `ObjectService` - Business logic for objects
- `PropertyService` - Business logic for properties

#### Repositories
- `CollectionRepository` - Data access for collections
- `ObjectRepository` - Data access for objects
- `PropertyRepository` - Data access for properties

### Schema Services
Located in `src/Domain/Schema/`

- `SchemaService` - Schema validation and management
- `SchemaRepository` - Schema data access

### File Services
Located in `src/Domain/File/`

- `FileService` - File handling and validation
- `ImageService` - Image processing and manipulation
- `UploadService` - File upload processing

### Job Queue Services
Located in `src/Domain/JobQueue/`

- `JobRunner` - Background job execution
- `JobRepository` - Job queue data access
- `JobData` - Job data model

### Search Services
Located in `src/Domain/Search/`

- `SearchService` - Full-text search functionality
- `IndexService` - Search index management

### Export Services
Located in `src/Domain/Export/`

- `ExportService` - Data export functionality
- `CsvExporter` - CSV export implementation
- `JsonExporter` - JSON export implementation

### Import Services
Located in `src/Domain/Import/`

- `ImportService` - Data import functionality
- `CsvImporter` - CSV import implementation
- `JsonImporter` - JSON import implementation

## Data Models

### Core Data Objects
Located in `src/Domain/*/Data/`

#### Collection Data
- `CollectionData` - Collection metadata and configuration
- `ObjectData` - Individual object within collections
- `PropertyData` - Object properties and values

#### Schema Data
- `SchemaData` - Schema definitions and validation rules
- `FieldData` - Individual field definitions

#### Property Data Objects
Located in `src/Domain/Property/Data/`

- `TextData` - Text content with HTML sanitization
- `ImageData` - Image metadata and processing
- `FileData` - File metadata and validation
- `DateData` - Date/time handling
- `NumberData` - Numeric data validation
- `BooleanData` - Boolean values
- `SvgData` - SVG content with sanitization
- `ListData` - Array/list data structures

### Validation
- **Engine**: Uses `cakephp/validation` for data validation
- **Schema Validation**: JSON Schema validation with `opis/json-schema`
- **File Validation**: Comprehensive file type and size validation

## Middleware

### Core Middleware
Located in `src/Middleware/`

#### Security Middleware
- `AuthMiddleware` - Authentication enforcement
- `CSRFProtectionMiddleware` - CSRF token validation
- `CorsMiddleware` - CORS headers for API access

#### Request Processing
- `JsonBodyParserMiddleware` - JSON request body parsing
- `LicenseMiddleware` - License validation and feature access
- `TrailingSlashMiddleware` - URL normalization

#### Response Processing
- `ResponseFactoryMiddleware` - PSR-7 response generation
- `ContentLengthMiddleware` - Content-Length header management

## Utilities

### Core Utilities
Located in `src/Utils/`

#### Security
- `CSRFTokenManager` - CSRF token generation and validation
- `HTMLSanitizer` - XSS prevention and HTML cleaning

#### File Processing
- `FileValidator` - File upload validation
- `ImageProcessor` - Image manipulation and optimization
- `QRCodeGenerator` - QR code generation

#### Data Processing
- `JsonProcessor` - JSON encoding/decoding with error handling
- `StringUtils` - String manipulation utilities
- `ArrayUtils` - Array processing utilities

## Error Handling

### Exception Hierarchy
- `TotalCMSException` - Base exception class
- `ValidationException` - Data validation errors
- `AuthenticationException` - Authentication failures
- `AuthorizationException` - Permission denied errors
- `FileException` - File processing errors

### HTTP Status Codes
- `200` - Success
- `201` - Created
- `400` - Bad Request (validation errors)
- `401` - Unauthorized (authentication required)
- `403` - Forbidden (permission denied)
- `404` - Not Found
- `409` - Conflict (duplicate data)
- `422` - Unprocessable Entity (validation failures)
- `500` - Internal Server Error

## Data Storage

### Flat-File JSON Storage
- **Location**: `tcms-data/`
- **Format**: JSON files organized by collection
- **Indexing**: Automatic search index generation
- **Backup**: File-based backup and restore

### Database Support
- **Job Queue**: SQLite database for background jobs
- **Session Storage**: File-based PHP sessions
- **Cache**: File-based caching system

## Configuration Reference

### Core Settings
```php
// Application configuration
'app' => [
    'name' => 'Total CMS',
    'version' => '3.0.0',
    'timezone' => 'UTC',
    'debug' => false,
]

// Database configuration  
'db' => [
    'driver' => 'sqlite',
    'database' => 'tcms-data/jobs.db',
]

// File upload settings
'upload' => [
    'max_size' => '10M',
    'allowed_types' => ['image/*', 'application/pdf'],
    'path' => 'tcms-data/files/',
]
```

### Security Configuration
```php
// Session settings
'session' => [
    'name' => 'TOTALCMS_SESSION',
    'cache_expire' => 180,
    'cookie_lifetime' => 0,
    'cookie_secure' => true,
    'cookie_httponly' => true,
]

// CSRF protection
'csrf' => [
    'enabled' => true,
    'token_name' => '_token',
    'header_name' => 'X-CSRF-Token',
]
```

## Performance Considerations

### Caching
- **Twig Templates**: Compiled template caching
- **Search Index**: Persistent search index files
- **Configuration**: Cached configuration compilation

### Optimization
- **Asset Building**: ESBuild for JavaScript/CSS optimization
- **Image Processing**: On-demand image resizing and optimization
- **Database**: SQLite for optimal flat-file performance

### Monitoring
- **Logging**: Monolog integration with multiple channels
- **Error Tracking**: Sentry integration for production environments
- **Performance**: Built-in performance monitoring and reporting

---

*This documentation is automatically maintained. For the most up-to-date information, refer to the source code and inline documentation.*