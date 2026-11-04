<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\WorkbookException;
use App\Import\WorkbookLimits;
use App\Import\WorkbookReader;
use App\Import\RowValidationService;
use App\Import\ValidationSummary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'workbook:inspect', description: 'Development-only XLSX mapping/count; no database, HTTP or formula evaluation.')]
final class InspectWorkbookCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Local XLSX path (read-only).');
        $this->addOption('validate', null, InputOption::VALUE_NONE, 'Validate raw rows and summarize DTO/issues; no writes/downloads.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!in_array(getenv('APP_ENV'), ['development', 'test'], true)) {
            $output->writeln('Use APP_ENV=development or test for workbook diagnostics.');
            return Command::FAILURE;
        }
        try {
            $book = (new WorkbookReader(WorkbookLimits::fromEnvironment()))->open((string) $input->getArgument('path'));
            $validation = null;
            if ($input->getOption('validate')) {
                $validation = new ValidationSummary();
                $validation->consume((new RowValidationService())->validate($book->rows()));
                $count = $validation->rowCount;
                $first = $validation->firstRow;
                $last = $validation->lastRow;
            } else {
                $count = 0;
                $first = $last = null;
                foreach ($book->rows() as $row) {
                    ++$count;
                    $first ??= $row->rowNumber;
                    $last = $row->rowNumber;
                }
            }
            if ($count !== $book->dataRowCount) {
                throw WorkbookException::invalid();
            }
            $report = ['sheetName' => $book->sheetName, 'mapping' => $book->mapping,
                'rowCount' => $count, 'firstRow' => $first, 'lastRow' => $last, 'sha256' => $book->sha256];
            if ($validation !== null) {
                $report['validation'] = $validation;
            }
            $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        } catch (WorkbookException $exception) {
            $output->writeln(json_encode(['error' => ['code' => $exception->issueCode, 'message' => $exception->getMessage()]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
            return Command::FAILURE;
        }
    }
}
