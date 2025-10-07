<div class="panel">
  <h3><i class="icon icon-list"></i> Run #{$run.id_run}</h3>
  <div class="well">
    <ul>
      <li><b>Source:</b> {$run.id_source}</li>
      <li><b>Dry:</b> {$run.dry_run}</li>
      <li><b>Status:</b> {$run.status}</li>
      <li><b>Checksum:</b> {$run.checksum}</li>
      <li><b>Started:</b> {$run.started_at}</li>
      <li><b>Finished:</b> {$run.finished_at}</li>
      <li><b>Total:</b> {$run.total} | <b>Updated:</b> {$run.updated} | <b>Skipped:</b> {$run.skipped} | <b>Errors:</b> {$run.errors}</li>
      {if $run.message}<li><b>Message:</b> {$run.message|escape:'html'}</li>{/if}
    </ul>
    <a class="btn btn-default" href="{$export_link|escape:'html'}"><i class="icon-download"></i> Export CSV</a>
    <a class="btn btn-warning" href="{$rollback_link|escape:'html'}" onclick="return confirm('Na pewno przywrócić snapshoty?')"><i class="icon-undo"></i> Rollback</a>
  </div>

  <h4>Logs ({$count})</h4>
  <table class="table">
    <thead>
      <tr>
        <th>ID</th><th>Product</th><th>Action</th><th>Old Price</th><th>New Price</th><th>Old Qty</th><th>New Qty</th><th>Reason</th><th>Details</th><th>At</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$logs item=row}
        <tr>
          <td>{$row.id_log}</td>
          <td>{$row.id_product}</td>
          <td>{$row.action}</td>
          <td>{$row.old_price}</td>
          <td>{$row.new_price}</td>
          <td>{$row.old_qty}</td>
          <td>{$row.new_qty}</td>
          <td>{$row.reason|escape:'html'}</td>
          <td><pre style="white-space:pre-wrap;max-width:420px;">{$row.details|escape:'html'}</pre></td>
          <td>{$row.created_at}</td>
        </tr>
      {/foreach}
      {if empty($logs)}
        <tr><td colspan="10" class="text-center text-muted">Brak logów</td></tr>
      {/if}
    </tbody>
  </table>

  {if $pages > 1}
    <div>
      {section name=p start=1 loop=$pages+1}
        {assign var=i value=$smarty.section.p.index}
        {if $i == $page}
          <span class="btn btn-primary disabled">{$i}</span>
        {else}
          <a class="btn btn-default" href="{$smarty.server.REQUEST_URI|escape:'html'}&page={$i}">{$i}</a>
        {/if}
      {/section}
    </div>
  {/if}
</div>
