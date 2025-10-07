<?php
if (!defined('_PS_VERSION_')) { exit; }

class PkshSource extends ObjectModel
{
    public $id_source;
    public $id_shop = 1;
    public $active = 0;
    public $name;
    public $url;
    public $file_type = 'csv';
    public $auth_type = 'none';
    public $auth_login;
    public $auth_password;
    public $auth_token;
    public $headers;
    public $query_params;
    public $delimiter = ';';
    public $enclosure = '"';
    public $items_path;
    public $item_xpath;
    public $map_col_key;
    public $map_col_price;
    public $map_col_qty;
    public $map_col_variant;
    public $key_type = 'ean';
    public $price_currency = 'PLN';
    public $rate_mode = 'ecb';
    public $fixed_rate;
    public $margin_mode = 'fixed';
    public $margin_fixed_pct = 0.000;
    public $margin_tiers;
    public $ending_mode = 'none';
    public $ending_value;
    public $min_margin_pct = 0.000;
    public $max_delta_pct = 50.000;
    public $zero_qty_policy = 'disable';
    public $stock_buffer = 0;
    public $price_update_mode = 'impact';
    public $tax_rule_group_id = 0;
    public $last_run_at;
    public $created_at;
    public $updated_at;

    public static $definition = [
        'table'   => 'pksh_source',
        'primary' => 'id_source',
        'fields'  => [
            'id_shop'             => ['type'=>self::TYPE_INT,    'validate'=>'isUnsignedInt', 'required'=>true],
            'active'              => ['type'=>self::TYPE_BOOL,   'validate'=>'isBool'],
            'name'                => ['type'=>self::TYPE_STRING, 'validate'=>'isGenericName', 'required'=>true, 'size'=>128],
            'url'                 => ['type'=>self::TYPE_HTML,   'validate'=>'isUrl'],
            'file_type'           => ['type'=>self::TYPE_STRING, 'validate'=>'isGenericName', 'size'=>10],
            'auth_type'           => ['type'=>self::TYPE_STRING, 'size'=>10],
            'auth_login'          => ['type'=>self::TYPE_STRING, 'size'=>128],
            'auth_password'       => ['type'=>self::TYPE_STRING, 'size'=>255],
            'auth_token'          => ['type'=>self::TYPE_STRING, 'size'=>512],
            'headers'             => ['type'=>self::TYPE_HTML],
            'query_params'        => ['type'=>self::TYPE_HTML],
            'delimiter'           => ['type'=>self::TYPE_STRING, 'size'=>2],
            'enclosure'           => ['type'=>self::TYPE_STRING, 'size'=>2],
            'items_path'          => ['type'=>self::TYPE_STRING, 'size'=>255],
            'item_xpath'          => ['type'=>self::TYPE_STRING, 'size'=>255],
            'map_col_key'         => ['type'=>self::TYPE_STRING, 'required'=>true, 'size'=>64],
            'map_col_price'       => ['type'=>self::TYPE_STRING, 'required'=>true, 'size'=>64],
            'map_col_qty'         => ['type'=>self::TYPE_STRING, 'required'=>true, 'size'=>64],
            'map_col_variant'     => ['type'=>self::TYPE_STRING, 'size'=>64],
            'key_type'            => ['type'=>self::TYPE_STRING, 'size'=>32],
            'price_currency'      => ['type'=>self::TYPE_STRING, 'size'=>8],
            'rate_mode'           => ['type'=>self::TYPE_STRING, 'size'=>8],
            'fixed_rate'          => ['type'=>self::TYPE_FLOAT],
            'margin_mode'         => ['type'=>self::TYPE_STRING, 'size'=>10],
            'margin_fixed_pct'    => ['type'=>self::TYPE_FLOAT],
            'margin_tiers'        => ['type'=>self::TYPE_HTML],
            'ending_mode'         => ['type'=>self::TYPE_STRING, 'size'=>10],
            'ending_value'        => ['type'=>self::TYPE_STRING, 'size'=>8],
            'min_margin_pct'      => ['type'=>self::TYPE_FLOAT],
            'max_delta_pct'       => ['type'=>self::TYPE_FLOAT],
            'zero_qty_policy'     => ['type'=>self::TYPE_STRING, 'size'=>10],
            'stock_buffer'        => ['type'=>self::TYPE_INT],
            'price_update_mode'   => ['type'=>self::TYPE_STRING, 'size'=>16],
            'tax_rule_group_id'   => ['type'=>self::TYPE_INT],
            'last_run_at'         => ['type'=>self::TYPE_DATE],
            'created_at'          => ['type'=>self::TYPE_DATE],
            'updated_at'          => ['type'=>self::TYPE_DATE],
        ],
    ];
}
