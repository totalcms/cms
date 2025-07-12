# Total CMS Architecture

This document provides a comprehensive overview of Total CMS's architecture, design patterns, and technical decisions.

## Table of Contents

- [Overview](#overview)
- [Core Principles](#core-principles)
- [System Architecture](#system-architecture)
- [Directory Structure](#directory-structure)
- [Domain-Driven Design](#domain-driven-design)
- [Data Layer](#data-layer)
- [HTTP Layer](#http-layer)
- [Security Architecture](#security-architecture)
- [Frontend Architecture](#frontend-architecture)
- [Build System](#build-system)
- [Testing Architecture](#testing-architecture)

## Overview

Total CMS is built as a modern, PHP-based content management system that follows industry best practices and design patterns. The architecture emphasizes:

- **Separation of Concerns**: Clear boundaries between layers
- **Testability**: Dependency injection and interface-based design
- **Flexibility**: Extensible through middleware and plugins
- **Performance**: Efficient flat-file storage with caching
- **Security**: Built-in protection against common vulnerabilities

## Core Principles

### 1. Domain-Driven Design (DDD)

The application is organized around business domains rather than technical layers:

```
src/
├── Domain/
│   ├── Auth/          # Authentication & authorization
│   ├── Collection/    # Collection management
│   ├── Object/        # Content objects
│   ├── Property/      # Property types & data
│   ├── Schema/        # Schema definitions
│   └── JobQueue/      # Background job processing
```

### 2. Dependency Injection

All dependencies are managed through PHP-DI container:

```php
// config/container.php
return [
    ServiceInterface::class => function (ContainerInterface $container) {
        return new Service(
            $container->get(RepositoryInterface::class),
            $container->get(LoggerInterface::class)
        );
    }
];
```

### 3. Interface Segregation

Small, focused interfaces for better flexibility:

```php
interface ObjectSaverInterface {
    public function saveObject(string $collection, array $data): ObjectData;
}

interface ObjectFetcherInterface {
    public function fetchObject(string $collection, string $id): ObjectData;
}
```

## System Architecture

### High-Level Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Web Client    │     │   REST API      │     │   Admin UI      │
└────────┬────────┘     └────────┬────────┘     └────────┬────────┘
         │                       │                         │
         └───────────────────────┴─────────────────────────┘
                                 │
                    ┌────────────┴────────────┐
                    │    HTTP Router         │
                    │    (Slim Framework)    │
                    └────────────┬────────────┘
                                 │
                    ┌────────────┴────────────┐
                    │    Middleware Stack    │
                    │  • Authentication      │
                    │  • CORS                │
                    │  • Validation          │
                    │  • License Check       │
                    └────────────┬────────────┘
                                 │
                    ┌────────────┴────────────┐
                    │    Action Handlers     │
                    │  (Controllers)         │
                    └────────────┬────────────┘
                                 │
                    ┌────────────┴────────────┐
                    │   Domain Services      │
                    │  • Business Logic      │
                    │  • Data Processing     │
                    └────────────┬────────────┘
                                 │
                    ┌────────────┴────────────┐
                    │    Repositories        │
                    │  • Data Access         │
                    │  • JSON Storage        │
                    └─────────────────────────┘
```

### Request Flow

1. **HTTP Request** → Router matches route
2. **Middleware Stack** → Processes request (auth, validation, etc.)
3. **Action Handler** → Thin controller, delegates to services
4. **Domain Service** → Contains business logic
5. **Repository** → Data persistence layer
6. **Response** → JSON/HTML response with proper headers

## Directory Structure

### Source Code Organization

```
src/
├── Action/              # HTTP action handlers (controllers)
│   ├── Admin/          # Admin panel actions
│   ├── Api/            # REST API endpoints
│   ├── Auth/           # Authentication actions
│   └── Collection/     # Collection-specific actions
│
├── Domain/             # Business logic layer
│   ├── {Domain}/
│   │   ├── Data/       # Data transfer objects
│   │   ├── Repository/ # Data access layer
│   │   └── Service/    # Business logic services
│   │
│   └── Property/       # Property type system
│       ├── Data/       # Property data classes
│       ├── Field/      # Form field definitions
│       └── Schema/     # Property schemas
│
├── Middleware/         # HTTP middleware
│   ├── AuthMiddleware.php
│   ├── CorsMiddleware.php
│   ├── CSRFProtectionMiddleware.php
│   └── ValidationMiddleware.php
│
├── Renderer/           # Response rendering
│   ├── JsonRenderer.php
│   ├── TwigRenderer.php
│   └── XmlRenderer.php
│
└── Utils/              # Utility classes
    ├── FileHandler.php
    ├── ImageProcessor.php
    └── Sanitizer.php
```

### Data Storage Structure

```
tcms-data/
├── {collection}/       # Collection data
│   ├── {id}.json      # Individual object files
│   └── _index.json    # Collection index
│
├── schemas/           # Schema definitions
│   └── {collection}.json
│
└── media/             # Uploaded files
    ├── images/
    ├── files/
    └── galleries/
```

## Domain-Driven Design

### Domain Boundaries

Each domain is self-contained with its own:

- **Data Objects**: Immutable value objects
- **Services**: Business logic implementation
- **Repositories**: Data persistence
- **Interfaces**: Contract definitions

Example domain structure:

```php
namespace TotalCMS\Domain\Blog;

// Data object
final class BlogPostData {
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $content,
        public readonly DateTime $publishedAt
    ) {}
}

// Service interface
interface BlogServiceInterface {
    public function createPost(array $data): BlogPostData;
    public function publishPost(string $id): void;
}

// Service implementation
final class BlogService implements BlogServiceInterface {
    public function __construct(
        private BlogRepositoryInterface $repository,
        private EventDispatcherInterface $events
    ) {}
    
    public function createPost(array $data): BlogPostData {
        // Business logic here
    }
}

// Repository interface
interface BlogRepositoryInterface {
    public function save(BlogPostData $post): void;
    public function findById(string $id): ?BlogPostData;
}
```

### Service Layer Patterns

Services follow these patterns:

1. **Single Responsibility**: Each service has one clear purpose
2. **Dependency Injection**: All dependencies injected via constructor
3. **Interface-based**: Program to interfaces, not implementations
4. **Stateless**: Services don't maintain state between calls

## Data Layer

### Flat-File Storage

Total CMS uses JSON files for data storage:

**Advantages:**
- No database server required
- Easy backup and version control
- Human-readable data format
- Portable between environments

**Implementation:**
```php
// Object storage format
{
    "id": "unique-slug",
    "type": "blog",
    "created": "2024-01-01T00:00:00Z",
    "modified": "2024-01-02T00:00:00Z",
    "properties": {
        "title": "Post Title",
        "content": "Post content...",
        "author": "John Doe"
    }
}
```

### Repository Pattern

Repositories abstract data access:

```php
final class JsonObjectRepository implements ObjectRepositoryInterface
{
    public function save(string $collection, ObjectData $object): void
    {
        $path = $this->getObjectPath($collection, $object->id);
        $data = json_encode($object->toArray(), JSON_PRETTY_PRINT);
        
        if (!file_put_contents($path, $data)) {
            throw new StorageException("Failed to save object");
        }
    }
    
    public function find(string $collection, string $id): ?ObjectData
    {
        $path = $this->getObjectPath($collection, $id);
        
        if (!file_exists($path)) {
            return null;
        }
        
        $data = json_decode(file_get_contents($path), true);
        return ObjectData::fromArray($data);
    }
}
```

### Indexing System

Collections maintain an index for efficient queries:

```json
{
    "collection": "blog",
    "count": 42,
    "objects": [
        {
            "id": "post-1",
            "title": "First Post",
            "date": "2024-01-01",
            "tags": ["news", "announcement"]
        }
    ],
    "updated": "2024-01-15T10:30:00Z"
}
```

## HTTP Layer

### Routing

Routes are defined declaratively:

```php
// config/routes/api.php
return function (App $app) {
    $app->group('/api/v3', function (RouteCollectorProxy $group) {
        // Collection routes
        $group->get('/{collection}', ListObjectsAction::class);
        $group->post('/{collection}', CreateObjectAction::class);
        $group->get('/{collection}/{id}', GetObjectAction::class);
        $group->put('/{collection}/{id}', UpdateObjectAction::class);
        $group->delete('/{collection}/{id}', DeleteObjectAction::class);
    })->add(ApiAuthMiddleware::class);
};
```

### Action Handlers

Actions are thin controllers that delegate to services:

```php
final class CreateObjectAction
{
    public function __construct(
        private ObjectService $objectService,
        private ResponseFactoryInterface $responseFactory
    ) {}
    
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $collection = $args['collection'];
        $data = $request->getParsedBody();
        
        try {
            $object = $this->objectService->create($collection, $data);
            return $this->responseFactory->json($response, $object->toArray(), 201);
        } catch (ValidationException $e) {
            return $this->responseFactory->error($response, $e->getMessage(), 400);
        }
    }
}
```

### Middleware Stack

Middleware provides cross-cutting concerns:

1. **ErrorHandlingMiddleware**: Catches exceptions, returns proper error responses
2. **CorsMiddleware**: Handles CORS headers for API access
3. **AuthenticationMiddleware**: Validates API tokens or session
4. **ValidationMiddleware**: Validates request data against schemas
5. **LicenseMiddleware**: Checks license validity
6. **CSRFProtectionMiddleware**: Prevents CSRF attacks

## Security Architecture

### Authentication & Authorization

Multi-layer security approach:

```php
// Session-based auth for admin
class SessionAuthMiddleware
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (!$this->session->has('user_id')) {
            return $this->redirectToLogin();
        }
        
        $user = $this->userRepository->find($this->session->get('user_id'));
        $request = $request->withAttribute('user', $user);
        
        return $handler->handle($request);
    }
}

// Token-based auth for API
class ApiAuthMiddleware
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $token = $this->extractToken($request);
        
        if (!$this->tokenValidator->isValid($token)) {
            return $this->unauthorizedResponse();
        }
        
        return $handler->handle($request);
    }
}
```

### Input Sanitization

All user input is sanitized:

```php
class HTMLSanitizer
{
    public function sanitize(string $html): string
    {
        // Remove dangerous tags and attributes
        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        
        return $purifier->purify($html);
    }
}

class SvgSanitizer
{
    public function sanitize(string $svg): string
    {
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        
        return $sanitizer->sanitize($svg);
    }
}
```

### CSRF Protection

Token-based CSRF protection:

```php
class CSRFTokenManager
{
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set('csrf_token', $token);
        
        return $token;
    }
    
    public function validateToken(string $token): bool
    {
        $storedToken = $this->session->get('csrf_token');
        
        return hash_equals($storedToken, $token);
    }
}
```

## Frontend Architecture

### JavaScript Organization

```
javascript/
├── totalform/          # Form system components
│   ├── fields/         # Field type implementations
│   ├── validators/     # Form validation
│   └── core.js         # Core form functionality
│
├── admin/              # Admin panel scripts
│   ├── datatable.js    # Data table component
│   ├── media.js        # Media manager
│   └── editor.js       # Content editor
│
└── utils/              # Utility functions
    ├── api.js          # API client
    └── helpers.js      # Helper functions
```

### Build Pipeline

ESBuild configuration for modern JavaScript:

```javascript
// esbuild.config.js
module.exports = {
    entryPoints: {
        'admin': './javascript/admin/index.js',
        'totalform': './javascript/totalform/index.js',
    },
    bundle: true,
    minify: process.env.NODE_ENV === 'production',
    sourcemap: true,
    target: ['es2020'],
    outdir: './public/dist',
    loader: {
        '.scss': 'css',
    },
    plugins: [sassPlugin()]
};
```

## Build System

### Asset Pipeline

1. **JavaScript**: ESBuild bundles and minifies
2. **CSS**: Sass compilation with PostCSS
3. **Images**: Optimization with imagemin
4. **Fonts**: Web font generation

### Development Workflow

```bash
# Development build with watch
bin/watch.sh

# Production build
composer run build

# Create distribution bundle
composer run bundle
```

## Testing Architecture

### Test Organization

```
tests/
├── Unit/               # Unit tests
│   ├── Domain/         # Domain logic tests
│   └── Utils/          # Utility tests
│
├── Feature/            # Feature tests
│   ├── Api/            # API endpoint tests
│   └── Admin/          # Admin panel tests
│
├── Integration/        # Integration tests
│   └── Storage/        # Storage layer tests
│
└── TestCase.php        # Base test class
```

### Testing Patterns

```php
// Unit test example
it('calculates discount correctly', function () {
    $calculator = new DiscountCalculator();
    
    $price = 100.00;
    $discount = 0.15;
    
    $result = $calculator->apply($price, $discount);
    
    expect($result)->toBe(85.00);
});

// Integration test example
it('saves and retrieves objects', function () {
    $repository = new JsonObjectRepository($this->storagePath);
    $object = new ObjectData('test-id', ['title' => 'Test']);
    
    $repository->save('blog', $object);
    $retrieved = $repository->find('blog', 'test-id');
    
    expect($retrieved)->toEqual($object);
});
```

## Performance Considerations

### Caching Strategy

1. **Template Caching**: Twig templates compiled and cached
2. **Image Caching**: Processed images cached by parameters
3. **Object Caching**: Frequently accessed objects cached in memory
4. **Index Caching**: Collection indexes cached and invalidated on changes

### Optimization Techniques

- Lazy loading of related objects
- Efficient JSON parsing with streaming
- Image optimization on upload
- Minified assets in production
- Gzip compression for responses

## Extension Points

### Custom Property Types

```php
// Register custom property type
$container->get(PropertyTypeRegistry::class)->register(
    'custom-type',
    CustomPropertyData::class,
    CustomPropertyField::class
);
```

### Middleware Extensions

```php
// Add custom middleware
$app->add(new CustomMiddleware($container));
```

### Event System

```php
// Listen to events
$events->listen('object.created', function ($event) {
    // Handle object creation
});
```

## Deployment Architecture

### Server Requirements

- PHP-FPM for better performance
- OPcache enabled
- File system permissions properly configured
- Regular backups of tcms-data directory

### Scalability

- Horizontal scaling with shared file system
- CDN for static assets
- Redis for session storage (optional)
- Queue workers for background jobs

---

This architecture provides a solid foundation for a modern, maintainable, and scalable CMS while keeping deployment simple with flat-file storage.