<?php

declare(strict_types=1);

use App\Infrastructure\DoctrineFactory;
use App\Queue\ImportMessage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__).'/bootstrap.php';
$db = DoctrineFactory::connection();
$action = $argv[1] ?? '';
if ($action === 'prepare') {
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->fromArray(['Наименование','Внешний код','Цена: Цена продажи','Закупочная цена','Доп. поле: Размер','Доп. поле: Ссылки на фото']);
    for ($i = 0; $i < 16; ++$i) {
        // Includes invalid price, duplicates of a committed code and a physical blank gap.
        $row = 2 + $i + (int)($i >= 5);
        $code = in_array($i, [3,9], true) ? 'R000' : 'R'.str_pad((string)$i, 3, '0', STR_PAD_LEFT);
        $urls = 'http://fixture.test:18080/slow';
        if ($i === 2) {
            $urls .= ',http://fixture.test:18080/404';
        }
        foreach (['A' => 'Recovery '.$i,'B' => $code,'C' => $i === 1 ? '0' : '100','D' => '75','E' => '0','F' => $urls] as $column => $value) {
            $sheet->setCellValueExplicit($column.$row, $value, DataType::TYPE_STRING);
        }
    }
    (new Xlsx($book))->save('/var/app/uploads/recovery.xlsx');
    $book->disconnectWorksheets();
    chmod('/var/app/uploads/recovery.xlsx', 0644);
    echo '{"rows":16,"valid":13,"invalid":3,"warnings":1}';
} elseif ($action === 'audit') {
    $id = $argv[2];
    new ImportMessage($id);
    $job = $db->fetchAssociative('SELECT lease_generation,last_row_number FROM import_jobs WHERE id=?', [$id]);
    $rows = $db->fetchAllAssociative('SELECT row_number,outcome,product_id,created_at FROM import_row_results WHERE job_id=? ORDER BY row_number', [$id]);
    $products = $db->fetchAllAssociative('SELECT p.id,p.external_code,p.name,p.price,p.purchase_price,p.discount FROM products p ORDER BY p.id');
    $duplicates = (int)$db->fetchOne('SELECT COUNT(*) FROM (SELECT job_id,row_number FROM import_row_results GROUP BY job_id,row_number HAVING COUNT(*)>1) d');
    $issues = $db->fetchAllAssociative('SELECT id,severity,code,row_number FROM import_issues WHERE job_id=? ORDER BY id', [$id]);
    $counts = $db->fetchAllKeyValue('SELECT outcome,COUNT(*) FROM import_row_results WHERE job_id=? GROUP BY outcome', [$id]);
    $warnings = (int)$db->fetchOne("SELECT COUNT(*) FROM import_issues WHERE job_id=? AND severity='warning'", [$id]);
    $attributes = $db->fetchAllAssociative('SELECT product_id,key,value FROM product_attributes ORDER BY product_id,key');
    $refs = $db->fetchFirstColumn('SELECT DISTINCT path FROM product_images');
    $files = array_map(static fn (string $path): string => '/images/'.basename($path), glob('/var/app/images/*.png') ?: []);
    echo json_encode(['generation' => (int)$job['lease_generation'],'lastRow' => (int)$job['last_row_number'],'rows' => $rows,'products' => $products,
        'issues' => $issues,'counts' => $counts,'warnings' => $warnings,'duplicateResults' => $duplicates,'attributes' => $attributes,
        'unreferencedPng' => array_values(array_diff($files, $refs))], JSON_THROW_ON_ERROR);
} elseif ($action === 'fault') {
    $id = $argv[2];
    new ImportMessage($id);
    $mode = $argv[3];
    if (!in_array($mode, ['once','always','clear'], true)) {
        throw new RuntimeException('Invalid controlled fault.');
    }
    $db->executeStatement('DROP TRIGGER IF EXISTS t18_fault ON import_row_results');
    $db->executeStatement('DROP FUNCTION IF EXISTS t18_fault()');
    $db->executeStatement('DROP SEQUENCE IF EXISTS t18_fault_attempt');
    if ($mode !== 'clear') {
        $db->executeStatement('CREATE SEQUENCE t18_fault_attempt');
        $condition = $mode === 'once' ? "nextval('t18_fault_attempt')=1" : 'true';
        // Runs after product/attributes/image flush, inside the SAME row transaction.
        $db->executeStatement("CREATE FUNCTION t18_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.job_id='".$id."' AND NEW.row_number=8 AND NEW.outcome<>'failed' AND ".$condition." THEN RAISE EXCEPTION 'controlled connection failure' USING ERRCODE='08006'; END IF; RETURN NEW; END $$");
        $db->executeStatement('CREATE TRIGGER t18_fault BEFORE INSERT ON import_row_results FOR EACH ROW EXECUTE FUNCTION t18_fault()');
    }
    echo '{"ok":true}';
} else {
    throw new RuntimeException('Unknown recovery fixture action.');
}
$db->close();
