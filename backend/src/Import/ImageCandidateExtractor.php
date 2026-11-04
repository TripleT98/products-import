<?php

declare(strict_types=1);

namespace App\Import;

final class ImageCandidateExtractor
{
    /** @param list<array{key: string, value: string}> $attributes @return array{urls: list<string>, issues: list<RowIssue>} */
    public function extract(array $attributes, int $rowNumber, string $externalCode): array
    {
        $values = array_column($attributes, 'value', 'key');
        $urls = $issues = $seen = [];
        foreach (['Ссылка на упаковку', 'Ссылки на фото'] as $key) {
            foreach (explode(',', $values[$key] ?? '') as $candidate) {
                $url = CellNormalizer::trim($candidate);
                if ($url === '' || isset($seen['url:' . $url])) {
                    continue;
                }
                $seen['url:' . $url] = true;
                $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
                if (strlen($url) > 2048 || preg_match('/\s/u', $url) || !in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    $issues[] = new RowIssue('warning', 'IMAGE_URL_REJECTED', 'Некорректный или слишком длинный URL изображения.', $rowNumber, $externalCode, 'images');
                    continue;
                }
                $urls[] = $url;
            }
        }
        // No DNS, socket, download or redirect checks here; those belong to T12.
        return ['urls' => $urls, 'issues' => $issues];
    }
}
