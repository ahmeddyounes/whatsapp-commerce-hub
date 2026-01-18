# Database Migrations

This directory contains database migrations for the WhatsApp Commerce Hub plugin.

## Overview

The migration system provides a versioned, testable way to manage database schema changes. Each migration is:
- **Versioned**: Migrations run in order based on semantic versioning
- **Idempotent**: Migrations use `dbDelta` and conditional checks to safely run multiple times
- **Testable**: Each migration can be tested independently

## Creating a Migration

### 1. Create a new migration file

Migration files should be named `Migration_X_Y_Z.php` where `X.Y.Z` is the version number.

Example: `Migration_2_7_0.php` for version 2.7.0

### 2. Extend AbstractMigration

```php
<?php
declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Database\Migrations;

use WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Migration_2_7_0 extends AbstractMigration {
    public function __construct() {
        parent::__construct( '2.7.0' );
    }

    public function up( DatabaseManager $db ): void {
        // Your migration logic here
    }
}
```

### 3. Implement the up() method

The `up()` method contains your migration logic. Use the helper methods from `AbstractMigration`:

#### Add a column
```php
$this->addColumn(
    $db,
    'customer_profiles',
    'metadata',
    'JSON NULL COMMENT \'Additional metadata\''
);
```

#### Drop a column
```php
$this->dropColumn( $db, 'customer_profiles', 'old_column' );
```

#### Add an index
```php
$this->addIndex(
    $db,
    'customer_profiles',
    'idx_email',
    'email',
    'INDEX' // or 'UNIQUE', 'FULLTEXT'
);
```

#### Drop an index
```php
$this->dropIndex( $db, 'customer_profiles', 'idx_email' );
```

#### Use dbDelta for table modifications
```php
$charsetCollate = $this->wpdb->get_charset_collate();
$tableName = $db->getTableName( 'my_table' );

$sql = "CREATE TABLE {$tableName} (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id)
) {$charsetCollate};";

$this->dbDelta( $db, $sql );
```

#### Run raw SQL (use sparingly)
```php
$this->query( $db, "UPDATE {$tableName} SET column = 'value'" );
```

## Migration Execution

Migrations run automatically when:
1. The plugin is activated
2. `DatabaseManager::runMigrations()` is called
3. The database version is less than the target version

The system:
1. Checks if migrations are needed
2. Runs base table creation via `dbDelta`
3. Loads all migration files from this directory
4. Sorts migrations by version
5. Executes each migration where `shouldRun()` returns true
6. Updates the database version after each successful migration

## Best Practices

### DO:
- ✅ Use semantic versioning (MAJOR.MINOR.PATCH)
- ✅ Make migrations idempotent (safe to run multiple times)
- ✅ Use `dbDelta` for table structure changes when possible
- ✅ Use helper methods (`addColumn`, `addIndex`, etc.) for safety
- ✅ Test migrations thoroughly before deployment
- ✅ Add comments explaining complex logic

### DON'T:
- ❌ Delete or modify existing migration files
- ❌ Run destructive operations without safety checks
- ❌ Assume tables or columns exist without checking
- ❌ Use version numbers that conflict with existing migrations
- ❌ Skip error handling for critical operations

## Example Migration

See `Migration_2_7_0.php` for a complete example that demonstrates:
- Adding a new column with idempotent checks
- Adding an index with idempotent checks
- Proper documentation

## Testing Migrations

Unit tests for the migration system are in:
`tests/Unit/Infrastructure/Database/DatabaseManagerMigrationsTest.php`

Run tests with:
```bash
./vendor/bin/phpunit tests/Unit/Infrastructure/Database/
```

## Troubleshooting

### Migration not running
- Check that the file is named correctly: `Migration_X_Y_Z.php`
- Verify the version in `__construct()` matches the filename
- Ensure the current DB version is less than the migration version

### Migration runs multiple times
- Use the helper methods (`addColumn`, `addIndex`) which have built-in checks
- For custom logic, implement your own idempotency checks
- Check `shouldRun()` logic in your migration

### Errors during migration
- Check WordPress error logs
- Verify table and column names are correct
- Ensure database user has required permissions
- Test SQL statements manually before putting in migrations
