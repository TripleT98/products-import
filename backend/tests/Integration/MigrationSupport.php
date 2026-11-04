<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationFactory;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\MigratorConfiguration;

final class MigrationSupport
{
    public static function migrate(Connection $connection, string $target = 'latest'): void
    {
        $factory = MigrationFactory::create($connection);
        $factory->getMetadataStorage()->ensureInitialized();
        $version = $factory->getVersionAliasResolver()->resolveVersionAlias($target);
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($version);
        $configuration = new MigratorConfiguration();
        $configuration->setAllOrNothing(true);
        $factory->getMigrator()->migrate($plan, $configuration);
    }
}
