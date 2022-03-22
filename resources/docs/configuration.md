# Configuration

Configuration is done via a hierarchy of PHP files.

Config files load order....

1. config/defaults.php
2. env.php
3. config/{{env}}.php -> production.php, development.php, etc.
4. DOCUMENT_ROOT/env.php
