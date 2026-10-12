<div id="pi-run-list" class="panel" data-url="{$pi_run_url|escape:'html':'UTF-8'}" data-baseline="{$pi_run_baseline|intval}">
  <h3>Run import</h3>
  <label for="pi-run-source">Sources</label>
  <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px">
  <select id="pi-run-source" class="form-control" style="width:auto;min-width:260px;max-width:420px">
    <option value="all">All active sources</option>
    {foreach from=$pi_run_sources item=source}
    <option value="{$source.id_source|intval}" data-name="{$source.name|escape:'html':'UTF-8'}" data-deactivate="{$source.deactivate_missing|intval}">{$source.name|escape:'html':'UTF-8'}{if !$source.active} (inactive){/if}</option>
    {/foreach}
  </select>
  <button type="button" id="pi-run-list-button" class="btn btn-default">Run import now</button>
  <button type="button" id="pi-run-list-overwrite" class="btn btn-default" data-overwrite="1">Run and overwrite manual edits</button>
  </div>
  <div id="pi-run-list-results" aria-live="polite"></div>
</div>
{include file='./run_import_script.tpl'}
