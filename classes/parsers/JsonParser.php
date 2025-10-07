<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * JSON: wyciągamy tablicę elementów wg items_path (np. "data.items").
 * Każdy element powinien być obiektem/assoc – przepuszczamy go „jak leci”.
 */
class PkshJsonParser
{
    public function parse(string $content, array $cfg = []): array
    {
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON decode error: '.json_last_error_msg());
        }

        $itemsPath = (string)($cfg['items_path'] ?? '');
        $items = $this->extractByPath($data, $itemsPath);

        if (!is_array($items)) {
            throw new Exception('JSON items not array at path: '.($itemsPath ?: '[root]'));
        }

        // liczba elementów policzona na szybko
        $total = count($items);

        $generator = (function() use ($items) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    yield $item;
                } else {
                    // jeśli element nie jest assoc, zapakuj w 'value'
                    yield ['value' => $item];
                }
            }
        })();

        return ['records'=>$generator, 'total'=>$total];
    }

    protected function extractByPath($data, string $path)
    {
        if ($path === '' || $path === null) {
            return $data;
        }
        $parts = array_filter(array_map('trim', explode('.', $path)), 'strlen');
        $cur = $data;
        foreach ($parts as $p) {
            if (is_array($cur) && array_key_exists($p, $cur)) {
                $cur = $cur[$p];
            } else {
                return null;
            }
        }
        return $cur;
    }
}
