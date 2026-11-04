<?php

declare(strict_types=1);

namespace App\Image;

/** A separate CLI process bounds libc DNS delays without global signals or shell interpolation. */
final class SystemDnsResolver
{
    private const string QUERY = <<<'PHP'
$records = @dns_get_record($argv[1], DNS_A | DNS_AAAA);
$addresses = [];
foreach ($records ?: [] as $record) {
    if (isset($record['ip'])) { $addresses[] = $record['ip']; }
    if (isset($record['ipv6'])) { $addresses[] = $record['ipv6']; }
}
echo json_encode(array_values(array_unique($addresses)), JSON_THROW_ON_ERROR);
PHP;

    /** @return list<string> */
    public function resolve(string $host, int $timeoutMs): array
    {
        $process = @proc_open(
            [PHP_BINDIR . '/php', '-r', self::QUERY, $host],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new ImageDownloadException(ImageWarningCode::DnsFailed);
        }
        try {
            stream_set_timeout($pipes[1], intdiv($timeoutMs, 1000), ($timeoutMs % 1000) * 1000);
            $output = stream_get_contents($pipes[1], 8192);
            if (stream_get_meta_data($pipes[1])['timed_out']) {
                throw new ImageDownloadException(ImageWarningCode::Timeout);
            }
            $addresses = json_decode($output ?: '', true);
            if (!is_array($addresses) || $addresses === []) {
                throw new ImageDownloadException(ImageWarningCode::DnsFailed);
            }
            return $addresses;
        } finally {
            fclose($pipes[1]);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }
}
