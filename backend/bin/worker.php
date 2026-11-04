<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$application = new Symfony\Component\Console\Application('Product import worker');
$application->addCommand(new App\Command\WorkImportsCommand());
$application->setDefaultCommand('imports:work', true);
$application->run();
