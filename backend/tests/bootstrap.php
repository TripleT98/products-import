<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Refuse accidental runs against application credentials, even on the host.
if (getenv('APP_ENV') !== 'test'
    || getenv('DB_HOST') !== 'db-test'
    || getenv('POSTGRES_DB') !== 'product_import_test'
    || getenv('POSTGRES_USER') !== 'product_import_test') {
    throw new RuntimeException('Use the isolated compose.test.yaml environment; application databases are forbidden.');
}
