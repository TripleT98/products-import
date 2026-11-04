<?php

declare(strict_types=1);

// Metadata inspection only: creating an EntityManager does not connect to PostgreSQL.
return \App\Infrastructure\DoctrineFactory::entityManager();
