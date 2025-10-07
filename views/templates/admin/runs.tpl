{extends file="helpers/view/view.tpl"}
{block name="view"}
<div class="panel">
  <h3><i class="icon-list"></i> {$title|escape:'html'}</h3>
  <p class="help-block">{$subtitle|escape:'html'}</p>
  <div class="alert alert-info">
    Hello 👋 — tu pokażemy runy, logi i rollback (ETAP 11–13). Na razie „Hello”.
  </div>
</div>
{/block}
