<?php

declare(strict_types=1);

use App\Infrastructure\DoctrineFactory;

require dirname(__DIR__).'/bootstrap.php';
$db = DoctrineFactory::connection();
if ((int)$db->fetchOne('SELECT COUNT(*) FROM products') !== 0) {
    throw new RuntimeException('List fixtures require the empty isolated database.');
}
$db->executeStatement(<<<'SQL'
INSERT INTO products(external_code,name,description,price,purchase_price,discount,created_at,updated_at)
SELECT 'LIST-'||LPAD(i::text,4,'0'), (CASE WHEN i%2=1 THEN 'Чай ' ELSE 'Кофе ' END)||LPAD(i::text,2,'0'),
 'Synthetic list fixture',i*100,i*75,25,'2026-11-03 00:00:00+00','2026-11-03 00:00:00+00'
FROM generate_series(1,45) i
SQL);
echo json_encode(['products' => (int)$db->fetchOne('SELECT COUNT(*) FROM products')], JSON_THROW_ON_ERROR);
$db->close();
