<?php

declare(strict_types=1);

use App\Infrastructure\DoctrineFactory;

require dirname(__DIR__).'/bootstrap.php';
$db = DoctrineFactory::connection();
$id = (int) $db->fetchOne("SELECT id FROM products WHERE external_code='LIST-0011'");
if ($id < 1) {
    throw new RuntimeException('Dedicated list fixtures must exist first.');
}
$db->executeStatement('UPDATE products SET name=?, description=? WHERE id=?', [
    'Чай 11 — длинный кириллический товар '.str_repeat('А', 100),
    "Описание с переносом\n<img src=x onerror=\"window.__detailXss=true\">\n".str_repeat('Длинное описание ', 80), $id,
]);
foreach (['Размер' => '0', 'Длинное значение' => str_repeat('КириллицаБезПробелов', 40), '<script>unsafe key</script>' => '<img src=x onerror=alert(1)>'] as $key => $value) {
    $db->insert('product_attributes', ['product_id' => $id, 'key' => $key, 'value' => $value]);
}
chmod('/var/app/images', 0755);
foreach (['blue' => [43, 92, 169], 'green' => [33, 127, 104]] as $name => $color) {
    $image = imagecreatetruecolor(480, 300);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$color));
    imagestring($image, 5, 150, 140, 'Local fixture: '.$name, imagecolorallocate($image, 255, 255, 255));
    imagepng($image, '/var/app/images/detail-'.$name.'.png');
    chmod('/var/app/images/detail-'.$name.'.png', 0644);
}
foreach (['blue', 'green', 'missing'] as $position => $name) {
    $db->insert('product_images', ['product_id' => $id, 'url' => 'https://external.invalid/source-'.$name.'.png', 'path' => '/images/detail-'.$name.'.png', 'position' => $position]);
}
echo json_encode(['id' => $id, 'attributes' => 3, 'images' => 3], JSON_THROW_ON_ERROR);
$db->close();
