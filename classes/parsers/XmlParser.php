<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * XML: używamy SimpleXML + XPath (item_xpath), np. //root/item
 * Każdy węzeł item zamieniamy na assoc (childName => string).
 */
class PkshXmlParser
{
    public function parse(string $content, array $cfg = []): array
    {
        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_NONET);
        if ($sx === false) {
            $err = $this->collectLibxmlErrors();
            throw new Exception('XML parse error: '.$err);
        }

        $xpath = (string)($cfg['item_xpath'] ?? '');
        if ($xpath === '') {
            throw new Exception('XML: item_xpath is required');
        }

        $nodes = $sx->xpath($xpath);
        if ($nodes === false) {
            throw new Exception('XML: invalid XPath: '.$xpath);
        }

        $total = count($nodes);
        $generator = (function() use ($nodes) {
            foreach ($nodes as $node) {
                /** @var SimpleXMLElement $node */
                yield $this->xmlNodeToAssoc($node);
            }
        })();

        return ['records'=>$generator, 'total'=>$total];
    }

    protected function xmlNodeToAssoc(SimpleXMLElement $node): array
    {
        $arr = [];
        foreach ($node->children() as $child) {
            $name = $child->getName();
            // jeśli powtarzające się tagi – zrób tablicę
            if (isset($arr[$name])) {
                if (!is_array($arr[$name]) || array_keys($arr[$name]) === range(0, count($arr[$name]) - 1)) {
                    $arr[$name] = (array)$arr[$name];
                }
                $arr[$name][] = (string)$child;
            } else {
                $arr[$name] = (string)$child;
            }
        }
        // atrybuty (opcjonalnie)
        foreach ($node->attributes() as $k => $v) {
            $arr['@'.$k] = (string)$v;
        }
        // tekst bezpośredni (rzadko przydaje się w feedach)
        $text = trim((string)$node);
        if ($text !== '' && empty($arr)) {
            $arr['_text'] = $text;
        }
        return $arr;
    }

    protected function collectLibxmlErrors(): string
    {
        $msgs = [];
        foreach (libxml_get_errors() as $e) {
            $msgs[] = trim($e->message);
        }
        libxml_clear_errors();
        return implode('; ', $msgs);
    }
}
