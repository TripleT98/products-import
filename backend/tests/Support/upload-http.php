<?php

declare(strict_types=1);

use App\Infrastructure\DoctrineFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__).'/bootstrap.php';
$input = json_decode(stream_get_contents(STDIN), true, flags:JSON_THROW_ON_ERROR);
$action = $input['action'];
$db = DoctrineFactory::connection();
if ($action === 'prepare') {
    copy('/var/app/uploads/source.xlsx', '/tmp/t16-source.xlsx');
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([['Внешний код','Наименование','Цена: Цена продажи','Закупочная цена'],['1','One','100','75'],['2','Two','100','75'],['3','Three','100','75'],['4','Four','100','75']]);
    (new Xlsx($book))->save('/tmp/t16-rows.xlsx');
    $book->disconnectWorksheets();
    file_put_contents('/tmp/t16-text.xlsx', '<html>not a spreadsheet</html>');
    file_put_contents('/tmp/t16-corrupt.xlsx', "PK\x03\x04broken ZIP bytes");
    file_put_contents('/tmp/t16-large.xlsx', str_repeat('X', 65537));
    file_put_contents('/tmp/t16-php-limit.xlsx', str_repeat('X', 10485761));
    file_put_contents('/tmp/t16-nginx-limit.xlsx', str_repeat('X', 13631488));
    copy('/tmp/t16-source.xlsx', '/tmp/t16-bomb.xlsx');
    $zip = new ZipArchive();
    $zip->open('/tmp/t16-bomb.xlsx');
    $zip->addFromString('bomb.txt', str_repeat('X', 1000000));
    $zip->close();
    copy('/tmp/t16-source.xlsx', '/tmp/t16-entries.xlsx');
    $zip->open('/tmp/t16-entries.xlsx');
    for ($i = 0;$i < 40;++$i) {
        $zip->addFromString('extra-'.$i.'.txt', 'fixture');
    }$zip->close();
    copy('/tmp/t16-source.xlsx', '/tmp/t16-encrypted.xlsx');
    $zip->open('/tmp/t16-encrypted.xlsx');
    $zip->setPassword('fixture');
    $zip->setEncryptionName('xl/workbook.xml', ZipArchive::EM_AES_256);
    $zip->close();
    echo '{"prepared":true}';
    exit;
}
if ($action === 'inspect') {
    $id = $input['id'] ?? $db->fetchOne('SELECT id FROM import_jobs ORDER BY created_at DESC,id DESC LIMIT 1');
    $job = $id === false ? null : $db->fetchAssociative('SELECT id,owner_id,status,file_name,fatal_code FROM import_jobs WHERE id=?', [$id]);
    $copies = glob('/var/app/uploads/*.xlsx') ?: [];
    $generated = [];
    foreach ($copies as $file) {
        if (preg_match('/\/[a-f0-9]{32}\.xlsx$/D', $file)) {
            $generated[] = ['name' => basename($file),'mode' => decoct(fileperms($file) & 0777),'owner' => fileowner($file),'sha256' => hash_file('sha256', $file)];
        }
    }
    echo json_encode(['job' => $job,'jobs' => (int)$db->fetchOne('SELECT COUNT(*) FROM import_jobs'),'files' => $generated,
        'products' => (int)$db->fetchOne('SELECT COUNT(*) FROM products'),'sourceSha256' => hash_file('sha256', '/tmp/t16-source.xlsx')], JSON_THROW_ON_ERROR);
    exit;
}
$handle = curl_init('http://127.0.0.1'.($input['path'] ?? '/api/imports'));
$headers = $input['headers'] ?? [];
$responseHeaders = [];
curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true,CURLOPT_TIMEOUT => 30,CURLOPT_PROXY => '',
    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
        if (str_contains($line, ':')) {
            [$key,$value] = explode(':', $line, 2);
            $responseHeaders[strtolower($key)] = trim($value);
        }return strlen($line);
    }]);
if ($action === 'login') {
    curl_setopt_array($handle, [CURLOPT_URL => 'http://127.0.0.1/api/auth/login',CURLOPT_POST => true,CURLOPT_POSTFIELDS => json_encode(['username' => $input['username'] ?? 'worker-fixture', 'password' => 'worker-test-password'], JSON_THROW_ON_ERROR)]);
    $headers[] = 'Content-Type: application/json';
} elseif ($action === 'upload') {
    $parts = [];
    $kind = $input['kind'] ?? 'source';
    if ($kind !== 'missing') {
        $name = $input['filename'] ?? '../../untrusted-name.xlsx';
        $mime = $input['mime'] ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $parts[$input['field'] ?? 'file'] = new CURLFile('/tmp/t16-'.$kind.'.xlsx', $mime, $name);
    }
    if (isset($input['extra'])) {
        $parts = array_merge($parts, $input['extra']);
    }
    if ($kind === 'missing') {
        $headers[] = 'Content-Type: multipart/form-data; boundary=missing';
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => "--missing--\r\n"]);
    } else {
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $parts]);
    }
} elseif ($action !== 'get') {
    throw new RuntimeException('Unknown HTTP fixture action.');
}
if ($action !== 'login' && ($input['auth'] ?? true)) {
    $headers[] = 'Authorization: Bearer '.trim(file_get_contents('/tmp/t16-token'.(($input['username'] ?? '') === 'other-owner' ? '-other' : '')));
}
curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
$body = curl_exec($handle);
if ($body === false) {
    throw new RuntimeException('HTTP fixture request failed: '.curl_error($handle));
}
$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
curl_close($handle);
if ($action === 'login' && $status === 200) {
    $data = json_decode($body, true, flags:JSON_THROW_ON_ERROR);
    $tokenPath = '/tmp/t16-token'.(($input['username'] ?? '') === 'other-owner' ? '-other' : '');
    file_put_contents($tokenPath, $data['accessToken']);
    chmod($tokenPath, 0600);
    $body = json_encode(['ownerId' => $data['user']['id']]);
}
$decoded = json_decode($body, true);
echo json_encode(['status' => $status,'headers' => $responseHeaders,'body' => $decoded ?? null,'bytes' => strlen($body),'sha256' => hash('sha256', $body)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
