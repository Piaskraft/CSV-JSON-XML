<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * Sprawdza poprawność pojedynczego rekordu feedu.
 * Zwraca: ['ok'=>bool,'errors'=>string[],'warnings'=>string[]]
 */
class PkshFeedValidator
{
    public function validate(array $row, array $cfg): array
    {
        $errors = [];
        $warns  = [];

        $key   = isset($row['key']) ? trim((string)$row['key']) : '';
        $price = $this->toFloat($row['price_raw'] ?? null);
        $qty   = $this->toInt($row['qty_raw'] ?? null);

        if ($key === '') {
            $errors[] = 'missing key';
        }

        if ($price === null) {
            $errors[] = 'price not a number';
        } elseif ($price < 0) {
            $errors[] = 'price < 0';
        }

        if ($qty === null) {
            // traktujemy brak jako 0 i ostrzegamy
            $qty = 0;
            $warns[] = 'qty empty -> 0';
        } elseif ($qty < 0) {
            $warns[] = 'qty < 0 -> 0';
            $qty = 0;
        }

        // zwróć ewentualnie skorygowaną ilość (żeby dalej nie liczyć na -1)
        $row['qty_raw'] = $qty;

        return [
            'ok'       => empty($errors),
            'errors'   => $errors,
            'warnings' => $warns,
            'row'      => $row,
        ];
    }

    protected function toFloat($v): ?float
    {
        if ($v === null || $v === '') return null;
        // zamień przecinek na kropkę
        if (is_string($v)) {
            $v = str_replace([' ', "\xc2\xa0"], '', $v); // usuń spacje/nbsp
            $v = str_replace(',', '.', $v);
        }
        return is_numeric($v) ? (float)$v : null;
    }

    protected function toInt($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_string($v)) {
            $v = preg_replace('/[^\d\-]/', '', $v);
        }
        if ($v === '' || !is_numeric($v)) return null;
        return (int)$v;
    }
}
