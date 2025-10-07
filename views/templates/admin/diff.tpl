{extends file="helpers/view/view.tpl"}
{block name="view"}
<div class="panel">
  <h3><i class="icon-exchange"></i> Dry Run — Differences</h3>

  <div class="well">
    <strong>Matched products:</strong> {$stats.matched} / {$stats.checked}
    &nbsp;|&nbsp; <strong>Changes:</strong> {$stats.changes}
    &nbsp;|&nbsp; <strong>Guard (max delta %):</strong> {$source->max_delta_pct}
    &nbsp;|&nbsp; <strong>Duration preview:</strong> {$metrics.duration_ms} ms
  </div>

  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Key</th>
          <th>ID</th>
          <th>Attr</th>
          <th>Price cur</th>
          <th>Price new</th>
          <th>Δ Price %</th>
          <th>Qty cur</th>
          <th>Qty new</th>
          <th>Δ Qty</th>
          <th>Guard</th>
          <th>Warnings</th>
        </tr>
      </thead>
      <tbody>
      {if $rows|@count == 0}
        <tr><td colspan="11"><em>No changes (or no matches).</em></td></tr>
      {else}
        {foreach from=$rows item=r}
          <tr{if $r.guard_hit} class="danger"{/if}>
            <td>{$r.key|escape}</td>
            <td>{$r.id_product}</td>
            <td>{if $r.id_attr}{$r.id_attr}{/if}</td>
            <td>{$r.cur_price}</td>
            <td>{$r.new_price}</td>
            <td>{if $r.delta_price_pct !== null}{$r.delta_price_pct}%{/if}</td>
            <td>{$r.cur_qty}</td>
            <td>{$r.new_qty}</td>
            <td>{if $r.delta_qty !== null}{$r.delta_qty}{/if}</td>
            <td>{if $r.guard_hit}<span class="label label-danger">HIT</span>{/if}</td>
            <td>
              {if $r.warnings|@count}
                <ul style="margin:0;padding-left:18px;">
                  {foreach from=$r.warnings item=w}<li>{$w|escape}</li>{/foreach}
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
