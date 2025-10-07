{extends file="helpers/view/view.tpl"}
{block name="view"}
<div class="panel">
  <h3><i class="icon-download"></i> {$title|escape:'html'}</h3>
  <p class="help-block">{$subtitle|escape:'html'}</p>
  <div class="alert alert-info">
    Hello 👋 — tu pojawi się lista źródeł (ETAP 3). Na razie to tylko kontrolka sanity-check.
  </div>
</div>
{/block}
