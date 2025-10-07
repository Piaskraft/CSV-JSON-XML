<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * Czytamy CSV z nagłówkiem. Zwracamy iterowalną listę asocjacyjnych rekordów.
 * $cfg: ['delimiter'=>';','enclosure'=>'"'] (opcjonalne).
 */
class PkshCsvParser
{
    /**
     * @return array [records=>Generator|array, total=>int]
     */
    public function parse(string $content, array $cfg = []): array
    {
        $delim = (string)($cfg['delimiter'] ?? ';');
        $encl  = (string)($cfg['enclosure'] ?? '"');

        // używamy SplTempFileObject, aby dostać fgetcsv
        $fp = new SplTempFileObject();
        $fp->fwrite($content);
        $fp->rewind();
        $fp->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $fp->setCsvControl($delim, $encl);

        // nagłówek
        $headers = $fp->current();
        if (!is_array($headers)) {
            throw new Exception('CSV: missing header row');
        }
        // usuń BOM z pierwszej komórki
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
        }
        $headers = array_map(function($h){ return trim((string)$h); }, $headers);
        $fp->next();

        $total = 0;
        $generator = (function() use ($fp, $headers, &$total) {
            for (; !$fp->eof(); $fp->next()) {
                $row = $fp->current();
                if (!is_array($row)) continue;
                // utnij do liczby nagłówków
                $row = array_slice($row, 0, count($headers));
                // dopaduj brakujące
                while (count($row) < count($headers)) { $row[] = null; }
                $assoc = array_combine($headers, array_map(function($v){
                    return is_string($v) ? trim($v) : $v;
                }, $row));
                $total++;
                yield $assoc;
            }
        })();

        return ['records'=>$generator, 'total'=>$total];
    }
}
