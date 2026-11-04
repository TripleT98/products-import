<?php

declare(strict_types=1);

use App\Import\Job\ImportJobService;
use App\Infrastructure\DoctrineFactory;
use App\Queue\ImportMessage;
use App\Queue\ImportQueue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Integration\MigrationSupport;

require dirname(__DIR__) . '/bootstrap.php';
$action = $argv[1] ?? '';
$db = DoctrineFactory::connection();
if ($action === 'serve') {
    MigrationSupport::migrate($db);
    $db->executeStatement("INSERT INTO users(username,password_hash) VALUES ('worker-fixture',?)", [password_hash('worker-test-password', PASSWORD_BCRYPT)]);
    foreach (['/var/app/uploads', '/var/app/images'] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        chown($dir, 33);
        chgrp($dir, 33);
        chmod($dir, 0770);
    }
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->fromArray(['Наименование', 'Внешний код', 'Цена: Цена продажи', 'Закупочная цена', 'Доп. поле: Размер', 'Доп. поле: Ссылки на фото'], null, 'A1');
    foreach ([[2,'First','000001','100','75','http://fixture.test:18080/ok'],
        [3,'Bad row','invalid','0','10',''], [4,'Last','000002','200','150','http://fixture.test:18080/jpeg,http://fixture.test:18080/404']] as [$row,$name,$code,$sale,$purchase,$urls]) {
        foreach (['A' => $name,'B' => $code,'C' => $sale,'D' => $purchase,'E' => '0','F' => $urls] as $col => $value) {
            $sheet->setCellValueExplicit($col.$row, $value, DataType::TYPE_STRING);
        }
    }
    (new Xlsx($book))->save('/var/app/uploads/source.xlsx');
    $book->disconnectWorksheets();
    chmod('/var/app/uploads/source.xlsx', 0644);
    file_put_contents('/var/app/uploads/broken.xlsx', 'not a ZIP workbook');
    chmod('/var/app/uploads/broken.xlsx', 0644);
    $db->close();
    pcntl_exec(PHP_BINARY, [__DIR__.'/image-server.php','/tmp/t15-http-requests','http','127.0.0.1:18080']);
    throw new RuntimeException('Fixture exec failed.');
}
if ($action === 'snapshot') {
    $id = $argv[2];
    new ImportMessage($id);
    $job = (new ImportJobService())->findForOwner($id, 1);
    $images = $db->fetchAllAssociative('SELECT * FROM product_images ORDER BY id');
    foreach ($images as &$image) {
        $file = '/var/app/images/'.substr($image['path'], strlen('/images/'));
        $image['exists'] = is_file($file);
        $image['mime'] = is_file($file) ? mime_content_type($file) : null;
    }
    unset($image);
    echo json_encode(['job' => $job,'issues' => (new ImportJobService())->issuesForOwner($id, 1),
        'products' => $db->fetchAllAssociative('SELECT external_code,price,purchase_price,discount FROM products ORDER BY id'),
        'images' => $images,'results' => (int)$db->fetchOne('SELECT COUNT(*) FROM import_row_results WHERE job_id=?', [$id]),
        'fileCount' => count(glob('/var/app/images/*.png') ?: [])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} elseif ($action === 'publish') {
    (new ImportQueue())->bus->dispatch(new ImportMessage($argv[2]));
    echo '{"published":true}';
} elseif ($action === 'fault') {
    $id = $argv[2];
    new ImportMessage($id);
    $mode = $argv[3];
    if (!in_array($mode, ['once','always'], true)) {
        throw new RuntimeException('Invalid fixture fault.');
    }
    $db->executeStatement('DROP TRIGGER IF EXISTS t15_fault ON import_jobs');
    $db->executeStatement('DROP FUNCTION IF EXISTS t15_fault()');
    $db->executeStatement('DROP SEQUENCE IF EXISTS t15_attempt');
    $db->executeStatement('CREATE SEQUENCE t15_attempt');
    $condition = $mode === 'once' ? "nextval('t15_attempt')=1" : 'true';
    $db->executeStatement("CREATE FUNCTION t15_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.id='".$id."' AND OLD.total_rows IS NULL AND NEW.total_rows IS NOT NULL AND ".$condition." THEN RAISE EXCEPTION 'controlled temporary database failure'; END IF; RETURN NEW; END $$");
    $db->executeStatement('CREATE TRIGGER t15_fault BEFORE UPDATE ON import_jobs FOR EACH ROW EXECUTE FUNCTION t15_fault()');
    echo '{"faultReady":true}';
} else {
    throw new RuntimeException('Unknown fixture action.');
}
$db->close();
