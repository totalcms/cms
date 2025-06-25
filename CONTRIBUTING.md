# Contributing to Total CMS

Thank you for your interest in contributing to Total CMS! This guide will help you get started with contributing to the project.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Development Workflow](#development-workflow)
- [Coding Standards](#coding-standards)
- [Testing](#testing)
- [Pull Request Process](#pull-request-process)
- [Reporting Issues](#reporting-issues)
- [Documentation](#documentation)

## Code of Conduct

By participating in this project, you agree to abide by our code of conduct:

- Be respectful and inclusive
- Welcome newcomers and help them get started
- Focus on constructive criticism
- Accept feedback gracefully
- Prioritize the project's best interests

## Getting Started

1. Fork the repository on GitHub
2. Clone your fork locally
3. Create a new branch for your feature or bugfix
4. Make your changes following our guidelines
5. Submit a pull request

## Development Setup

### Prerequisites

- PHP 8.2 or higher
- Composer 2.0+
- Node.js 18+ and Yarn
- Git
- A local web server (Apache/Nginx/PHP built-in server)

### Initial Setup

1. **Clone your fork:**
   ```bash
   git clone https://github.com/yourusername/totalcms.git
   cd totalcms
   ```

2. **Add upstream remote:**
   ```bash
   git remote add upstream https://github.com/original/totalcms.git
   ```

3. **Install dependencies:**
   ```bash
   composer install
   yarn install
   ```

4. **Build assets:**
   ```bash
   composer run build
   ```

5. **Set up local environment:**
   ```bash
   # Copy environment configuration if needed
   cp .env.example .env
   
   # Create necessary directories
   mkdir -p tcms-data cache logs
   chmod -R 755 tcms-data cache logs
   ```

6. **Start development server:**
   ```bash
   # Option 1: PHP built-in server
   php -S localhost:8000 -t public/
   
   # Option 2: Use the watch script for auto-reload
   bin/watch.sh
   ```

## Development Workflow

### 1. Creating a Feature Branch

```bash
# Update your local main branch
git checkout main
git pull upstream main

# Create a new feature branch
git checkout -b feature/your-feature-name
```

### 2. Making Changes

- Write clean, readable code following our coding standards
- Add tests for new functionality
- Update documentation as needed
- Commit regularly with clear, descriptive messages

### 3. Commit Message Format

Follow the conventional commits specification:

```
type(scope): subject

body (optional)

footer (optional)
```

Types:
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, etc.)
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

Examples:
```bash
git commit -m "feat(auth): add two-factor authentication"
git commit -m "fix(api): correct JSON response formatting"
git commit -m "docs: update installation instructions"
```

### 4. Keeping Your Branch Updated

```bash
git fetch upstream
git rebase upstream/main
```

## Coding Standards

### PHP Code Style

We follow PSR-12 coding standards with some additional rules:

- **Indentation**: Use tabs (not spaces)
- **Naming Conventions**:
  - Classes: PascalCase
  - Methods/Functions: camelCase
  - Constants: UPPER_SNAKE_CASE
  - Properties: camelCase
- **Type Declarations**: Always use strict types
- **Return Types**: Always specify return types
- **PHPDoc**: Document complex logic and public APIs

Example:
```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Service;

use TotalCMS\Domain\Repository\RepositoryInterface;

final class ExampleService
{
    public function __construct(
        private RepositoryInterface $repository
    ) {
    }

    /**
     * Process data according to business rules.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public function processData(array $data): array
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Data cannot be empty');
        }

        return $this->repository->save($data);
    }
}
```

### JavaScript/TypeScript Style

- Use ESLint configuration provided in the project
- Prefer const over let
- Use arrow functions for callbacks
- Use async/await over promises where appropriate

### Running Code Quality Tools

```bash
# PHP Code Style Fixer
composer run cs:fix

# PHPStan (static analysis)
composer run stan

# PHP Mess Detector
composer run md

# All quality checks
composer run test:all
```

## Testing

### Writing Tests

We use Pest PHP for testing. Tests should be:
- Isolated and independent
- Fast and reliable
- Well-named and documented
- Cover both success and failure cases

Example test:
```php
<?php

use TotalCMS\Domain\Service\ExampleService;

it('processes valid data successfully', function () {
    $service = new ExampleService($this->repository);
    $result = $service->processData(['key' => 'value']);
    
    expect($result)->toBeArray()
        ->toHaveKey('key', 'value');
});

it('throws exception for empty data', function () {
    $service = new ExampleService($this->repository);
    
    expect(fn() => $service->processData([]))
        ->toThrow(InvalidArgumentException::class, 'Data cannot be empty');
});
```

### Running Tests

```bash
# Run all tests
composer run test

# Run with coverage
composer run test:coverage

# Run specific test file
./vendor/bin/pest tests/Feature/ExampleTest.php

# Update snapshots
composer run test:updatesnaps
```

### Test Coverage

- Aim for at least 80% code coverage
- Focus on testing business logic
- Don't test framework code or simple getters/setters
- Write integration tests for complex workflows

## Pull Request Process

### Before Submitting

1. **Ensure all tests pass:**
   ```bash
   composer run test:all
   ```

2. **Update documentation:**
   - Add/update PHPDoc comments
   - Update README if needed
   - Add entries to CHANGELOG.md

3. **Check for conflicts:**
   ```bash
   git fetch upstream
   git rebase upstream/main
   ```

### Submitting a Pull Request

1. Push your branch to your fork:
   ```bash
   git push origin feature/your-feature-name
   ```

2. Go to GitHub and create a pull request from your branch to `main`

3. Fill out the PR template with:
   - Clear description of changes
   - Related issue numbers
   - Testing instructions
   - Screenshots (if UI changes)

4. Wait for review and address feedback

### PR Review Process

- At least one maintainer must approve the PR
- All CI checks must pass
- No merge conflicts
- Documentation must be updated
- Tests must be included for new features

## Reporting Issues

### Bug Reports

When reporting bugs, include:

1. **Environment details:**
   - PHP version
   - Total CMS version
   - Operating system
   - Web server

2. **Steps to reproduce:**
   - Detailed steps to trigger the bug
   - Expected behavior
   - Actual behavior

3. **Additional context:**
   - Error messages
   - Log files
   - Screenshots
   - Code samples

### Feature Requests

For feature requests, provide:

1. **Use case:** Why is this feature needed?
2. **Proposed solution:** How should it work?
3. **Alternatives considered:** Other approaches you've thought about
4. **Additional context:** Mockups, examples, etc.

## Documentation

### Contributing to Documentation

Documentation is crucial! Help us improve by:

1. **Fixing typos and grammar**
2. **Adding examples and clarifications**
3. **Creating tutorials and guides**
4. **Updating outdated information**

### Documentation Standards

- Use clear, concise language
- Include code examples where appropriate
- Test all code examples
- Use proper markdown formatting
- Add table of contents for long documents

### Building Documentation

```bash
# Generate API documentation
composer run docs:api

# Check for broken links
composer run docs:check
```

## Getting Help

If you need help:

1. Check existing documentation
2. Search through issues on GitHub
3. Ask in discussions
4. Contact maintainers

## Recognition

Contributors are recognized in:
- CONTRIBUTORS.md file
- Release notes
- Project documentation

Thank you for contributing to Total CMS! 🎉