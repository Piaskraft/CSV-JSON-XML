<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * Znajduje produkt/kombinację po kluczu ze źródła.
 * key_type: ean | reference | supplier_reference
 */
class PkshProductResolver
{
    public function find(string $key, string $keyType, int $idShop = null): array
    {
        $key = trim($key);
        if ($key === '') return ['id_product'=>null,'id_product_attribute'=>null];

        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $idShop = $idShop ?: (int)Context::getContext()->shop->id;

        switch ($keyType) {
            case 'ean':
                // szukamy w product_attribute też
                $row = $db->getRow('
                    SELECT p.id_product, pa.id_product_attribute
                    FROM '._DB_PREFIX_.'product p
                    LEFT JOIN '._DB_PREFIX_.'product_shop ps ON (ps.id_product=p.id_product AND ps.id_shop='.(int)$idShop.')
                    LEFT JOIN '._DB_PREFIX_.'product_attribute pa ON (pa.id_product=p.id_product)
                    LEFT JOIN '._DB_PREFIX_.'product_attribute_shop pas ON (pas.id_product_attribute=pa.id_product_attribute AND pas.id_shop='.(int)$idShop.')
                    WHERE p.ean13="'.pSQL($key).'" OR pa.ean13="'.pSQL($key).'"
                    ORDER BY pa.id_product_attribute IS NOT NULL DESC
                    LIMIT 1
                ');
                break;

            case 'reference':
                $row = $db->getRow('
                    SELECT p.id_product, pa.id_product_attribute
                    FROM '._DB_PREFIX_.'product p
                    LEFT JOIN '._DB_PREFIX_.'product_attribute pa ON (pa.id_product=p.id_product)
                    WHERE p.reference="'.pSQL($key).'" OR pa.reference="'.pSQL($key).'"
                    ORDER BY pa.id_product_attribute IS NOT NULL DESC
                    LIMIT 1
                ');
                break;

            case 'supplier_reference':
                $row = $db->getRow('
                    SELECT p.id_product, pa.id_product_attribute
                    FROM '._DB_PREFIX_.'product p
                    LEFT JOIN '._DB_PREFIX_.'product_attribute pa ON (pa.id_product=p.id_product)
                    WHERE p.supplier_reference="'.pSQL($key).'" OR pa.supplier_reference="'.pSQL($key).'"
                    ORDER BY pa.id_product_attribute IS NOT NULL DESC
                    LIMIT 1
                ');
                break;

            default:
                $row = null;
        }

        if (!$row) return ['id_product'=>null,'id_product_attribute'=>null];

        return [
            'id_product' => (int)$row['id_product'],
            'id_product_attribute' => isset($row['id_product_attribute']) ? (int)$row['id_product_attribute'] : null,
        ];
    }

    /** Pobierz aktualną cenę brutto oraz stan magazynowy */
    public function getCurrent(array $ids, int $idShop = null): array
    {
        $idShop = $idShop ?: (int)Context::getContext()->shop->id;
        $idProduct = (int)($ids['id_product'] ?? 0);
        $idAttr    = (int)($ids['id_product_attribute'] ?? 0);

        if (!$idProduct) return ['price'=>null,'qty'=>null];

        // cena – weź price TE (tax-excl) + tax rule, a potem przelicz na gross (upraszczamy: użyj Tools::ps_round z Tax::getProductTaxRate)
        $priceTE = Product::getPriceStatic($idProduct, false, $idAttr, 6, null, false, false, 1, true, null, 0, true, null, true, $idShop);
        $qty     = (int)StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr, $idShop);

        // dla diff w zupełności wystarczy TE
        return ['price'=>$priceTE, 'qty'=>$qty];
    }
}
