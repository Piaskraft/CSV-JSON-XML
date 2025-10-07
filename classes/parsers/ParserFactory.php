<?php
if (!defined('_PS_VERSION_')) { exit; }

require_once __DIR__ . '/CsvParser.php';
require_once __DIR__ . '/JsonParser.php';
require_once __DIR__ . '/XmlParser.php';

class PkshParserFactory
{
    public static function make(string $fileType)
    {
        $t = strtolower(trim($fileType));
        if ($t === 'csv')  return new PkshCsvParser();
        if ($t === 'json') return new PkshJsonParser();
        if ($t === 'xml')  return new PkshXmlParser();
        throw new Exception('Unsupported file_type: '.$fileType);
    }
}
