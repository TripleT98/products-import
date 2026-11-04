<?php

declare(strict_types=1);

namespace App\Service;

use AMQPConnection;
use PDO;
use Throwable;

/** Bounded infrastructure probes; never expose connection errors or credentials. */
final class RuntimeDiagnostics
{
    public function readiness(): array
    {
        $database = $this->databaseReady();
        $broker = $this->brokerReady();
        return ['status' => $database && $broker ? 'ok' : 'unavailable', 'dependencies' => [
            'database' => $database ? 'ready' : 'unavailable', 'broker' => $broker ? 'ready' : 'unavailable',
        ]];
    }

    public function inspect(): array
    {
        $database = $this->databaseReady();
        $broker = $this->brokerReady();
        $uploads = is_writable(getenv('UPLOAD_DIR') ?: '/var/app/uploads');
        $images = is_writable(getenv('IMAGE_DIR') ?: '/var/app/images');

        return [
            'status' => $database && $broker && $uploads && $images ? 'ok' : 'unavailable',
            'service' => 'product-import-api',
            'dependencies' => [
                'database' => $database ? 'ready' : 'unavailable',
                'broker' => $broker ? 'ready' : 'unavailable',
            ],
            'storage' => [
                'uploads' => $uploads ? 'writable' : 'unavailable',
                'images' => $images ? 'writable' : 'unavailable',
            ],
        ];
    }

    private function databaseReady(): bool
    {
        try {
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;connect_timeout=2;options='-c statement_timeout=1000'",
                getenv('DB_HOST') ?: 'db',
                getenv('DB_PORT') ?: '5432',
                getenv('POSTGRES_DB') ?: 'product_import'
            );
            $connection = new PDO($dsn, getenv('POSTGRES_USER') ?: '', getenv('POSTGRES_PASSWORD') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            return $connection->query('SELECT 1')->fetchColumn() == 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function brokerReady(): bool
    {
        try {
            $connection = new AMQPConnection([
                'host' => getenv('RABBITMQ_HOST') ?: 'rabbitmq',
                'port' => (int) (getenv('RABBITMQ_PORT') ?: '5672'),
                'login' => getenv('RABBITMQ_DEFAULT_USER') ?: '',
                'password' => getenv('RABBITMQ_DEFAULT_PASS') ?: '',
                'vhost' => getenv('RABBITMQ_DEFAULT_VHOST') ?: '/',
                'connect_timeout' => 2.0,
                'read_timeout' => 2.0,
                'write_timeout' => 2.0,
                'rpc_timeout' => 2.0,
            ]);
            $connection->connect();
            $ready = $connection->isConnected();
            $connection->disconnect();

            return $ready;
        } catch (Throwable) {
            return false;
        }
    }
}
