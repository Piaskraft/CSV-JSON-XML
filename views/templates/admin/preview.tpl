{extends file="helpers/view/view.tpl"}
{block name="view"}
<div class="panel">
    <h3><i class="icon-search"></i> {$title|escape}</h3>

    <div class="well">
        <strong>Source:</strong> #{$source->id_source} — {$source->name|escape}
        <br>
        <strong>File type:</strong> {$source->file_type|escape}
        &nbsp;|&nbsp; <strong>Key:</strong> {$source->key_type|escape}
        &nbsp;|&nbsp; <strong>Currency:</strong> {$source->price_currency|escape}
        &nbsp;|&nbsp; <strong>Rate mode:</strong> {$metrics.rate_mode|escape}
        {if isset($metrics.rate_used) && $metrics.rate_used}
            &nbsp;|&nbsp; <strong>Rate used (PLN/EUR):</strong> {$metrics.rate_used}
        {/if}
        <br>
        <strong>Total in feed:</strong> {$metrics.total_in_feed}
        &nbsp;|&nbsp; <strong>Scanned:</strong> {$metrics.scanned}
        &nbsp;|&nbsp; <strong>Valid:</strong> {$metrics.valid}
        &nbsp;|&nbsp; <strong>Warnings:</strong> {$metrics.with_warnings}
        &nbsp;|&nbsp; <strong>Errors:</strong> {$metrics.errors}
        &nbsp;|&nbsp; <strong>Duration:</strong> {$metrics.duration_ms} ms
    </div>

    {* DODANY PRZYCISK 'Dry Diff' UMIESZCZONY PO SEKCJI INFORMACYJNEJ *}
    <div class="clearfix">
        <a class="btn btn-primary pull-right" href="{$link->getAdminLink('AdminPkSupplierHubSources')|escape}&id_source={$source->id_source}&viewpksh_source=1&drydiff=1">
            <i class="icon-exchange"></i> Dry Diff (compare with catalog)
        </a>
    </div>
    <hr>
    {* KONIEC DODANEGO PRZYCISKU *}


    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Key</th>
                    <th>Price (raw)</th>
                    <th>Qty</th>
                    <th>Price EUR</th>
                    <th>Target price</th>
                    <th>Warnings</th>
                    <th>Errors</th>
                </tr>
            </thead>
            <tbody>
                {if $rows|@count == 0}
                    <tr><td colspan="7"><em>No rows to show (check URL/parser mapping).</em></td></tr>
                {else}
                    {foreach from=$rows item=r}
                        <tr{if $r.errors|@count} class="danger"{elseif $r.warnings|@count} class="warning"{/if}>
                            <td>{$r.key|escape}</td>
                            <td>{$r.price_raw}</td>
                            <td>{$r.qty_raw}</td>
                            <td>{if isset($r.price_eur)}{$r.price_eur}{/if}</td>
                            <td>{if isset($r.price_target)}{$r.price_target}{/if}</td>
                            <td>
                                {if $r.warnings|@count}
                                    <ul style="margin:0;padding-left:18px;">
                                        {foreach from=$r.warnings item=w}<li>{$w|escape}</li>{/foreach}
                                    </ul>
                                {/if}
                            </td>
                            <td>
                                {if $r.errors|@count}
                                    <ul style="margin:0;padding-left:18px;">
                                        {foreach from=$r.errors item:e}<li>{$e|escape}</li>{/foreach}
                                    </ul>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                {/if}
            </tbody>
        </table>
    </div>

    <a class="btn btn-default" href="{$link->getAdminLink('AdminPkSupplierHubSources')|escape}">
        <i class="icon-angle-left"></i> Back to Sources
    </a>
</div>
{/block}