<?php

declare(strict_types=1);

// Controlled HTTP fixture process; never part of production images.
$tls = ($argv[2] ?? '') === 'tls';
$context = stream_context_create();
if ($tls) {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'fixture.test'], $key);
    $certificate = openssl_csr_sign($csr, null, $key, 1);
    openssl_x509_export($certificate, $pem);
    openssl_pkey_export($key, $private);
    $file = dirname($argv[1]) . '/fixture.pem';
    file_put_contents($file, $pem . $private);
    stream_context_set_option($context, 'ssl', 'local_cert', $file);
}
$bind = $argv[3] ?? '127.0.0.1:0';
if (!preg_match('/^127\.0\.0\.1:[0-9]{1,5}$/D', $bind)) {
    throw new RuntimeException('Only loopback fixture binds are allowed.');
}
$server = stream_socket_server(($tls ? 'tls' : 'tcp') . '://' . $bind, $error, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    throw new RuntimeException('Cannot start image fixture server.');
}
$address = stream_socket_get_name($server, false);
echo substr($address, strrpos($address, ':') + 1) . "\n";
flush();
$image = imagecreatetruecolor(2, 2);
imagefill($image, 0, 0, imagecolorallocate($image, 20, 100, 200));
ob_start();
imagepng($image);
$png = ob_get_clean();
ob_start();
imagejpeg($image);
$jpeg = ob_get_clean();
ob_start();
imagegif($image);
$gif = ob_get_clean();
$deadline = microtime(true) + 3600;
while (microtime(true) < $deadline) {
    $connection = @stream_socket_accept($server, 2);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 2);
    $request = fgets($connection);
    if ($request === false) {
        fclose($connection);
        continue;
    }
    $target = explode(' ', $request)[1];
    $path = parse_url($target, PHP_URL_PATH);
    file_put_contents($argv[1], $path . "\n", FILE_APPEND);
    $bytes = 0;
    while (($line = fgets($connection)) !== false && trim($line) !== '' && $bytes < 16384) {
        $bytes += strlen($line);
    }
    $status = 200;
    $headers = ['Content-Type: image/png'];
    $body = $png;
    $slow = false;
    switch ($path) {
        case '/switchable':
            clearstatcache();
            if (file_exists($argv[1] . '.fail')) {
                $status = 404;
                $body = 'temporarily unavailable';
            }
            break;
        case '/jpeg': $body = $jpeg;
            $headers = ['Content-Type: image/jpeg'];
            break;
        case '/gif': $body = $gif;
            $headers = ['Content-Type: image/gif'];
            break;
        case '/polyglot': $body .= '<?php echo "untrusted-script"; ?>';
            break;
        case '/mime': $headers = ['Content-Type: text/html'];
            break;
        case '/fake': $body = '<html>not an image</html>';
            break;
        case '/corrupt': $body = substr($png, 0, 40);
            break;
        case '/404': $status = 404;
            $body = 'missing';
            break;
        case '/huge': $headers[] = 'Content-Length: 99999999';
            break;
        case '/stream': $body = str_repeat('x', 131072);
            break;
        case '/chunked': $headers[] = 'Transfer-Encoding: chunked';
            $body = '1000' . "\r\n" . str_repeat('x', 4096) . "\r\n0\r\n\r\n";
            break;
        case '/header': $headers[] = 'X-Large: ' . str_repeat('x', 20000);
            break;
        case '/encoding': $headers[] = 'Content-Encoding: gzip';
            break;
        case '/slow': $slow = true;
            break;
        case '/redirect': $status = 302;
            $headers[] = 'Location: /ok';
            $body = '';
            break;
        case '/loop': $status = 302;
            $headers[] = 'Location: /loop';
            $body = '';
            break;
        case '/chain': $status = 302;
            parse_str(parse_url($target, PHP_URL_QUERY) ?: '', $query);
            $headers[] = 'Location: /chain?n=' . ((int) ($query['n'] ?? 0) + 1);
            $body = '';
            break;
        case '/badredirect': $status = 302;
            $headers[] = 'Location: http://127.0.0.1/secret';
            $body = '';
            break;
        case '/scheme': $status = 302;
            $headers[] = 'Location: file:///etc/passwd';
            $body = '';
            break;
        case '/userinfo': $status = 302;
            $headers[] = 'Location: http://user:pass@fixture.test:' . substr($address, strrpos($address, ':') + 1) . '/secret';
            $body = '';
            break;
        case '/otherhost': $status = 302;
            $headers[] = 'Location: http://other.test:' . substr($address, strrpos($address, ':') + 1) . '/secret';
            $body = '';
            break;
    }
    @fwrite($connection, 'HTTP/1.1 ' . $status . " Fixture\r\n" . implode("\r\n", $headers) . "\r\nConnection: close\r\n\r\n");
    if ($slow) {
        usleep(600000);
    }
    @fwrite($connection, $body);
    fclose($connection);
}
fclose($server);
