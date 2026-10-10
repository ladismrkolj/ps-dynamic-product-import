<div id="pi-preview" data-url="{$pi_preview_url|escape:'html':'UTF-8'}" data-source="{$pi_preview_id|intval}"></div>
<div id="pi-configuration-panel">
  <h3>Preview</h3>
  <p>Maps the first 20 products of the feed with the mapping currently on this form, without saving and without writing catalog data. Errors are highlighted per cell.</p>
  <button type="button" class="btn btn-default pi-preview-table-button">Update preview</button>
  <div class="pi-preview-table-results" aria-live="polite"></div>
  {if $pi_can_run}
  <div id="pi-run-editor" data-url="{$pi_preview_url|escape:'html':'UTF-8'}" data-baseline="{$pi_run_baseline|intval}" data-source="{$pi_preview_id|intval}" data-name="{$pi_run_name|escape:'html':'UTF-8'}" data-deactivate="{$pi_run_deactivate|intval}">
    <p>Runs the saved configuration. Unsaved edits are not used.</p>
    <button type="button" id="pi-run-editor-button" class="btn btn-default" {if !$pi_preview_id}disabled{/if}>Run import now</button>
    <button type="button" id="pi-run-editor-overwrite" class="btn btn-default" data-overwrite="1" {if !$pi_preview_id}disabled{/if}>Run and overwrite manual edits</button>
    {if !$pi_preview_id}<p>Save the source to enable importing.</p>{/if}
    <div id="pi-run-editor-results" aria-live="polite"></div>
  </div>
  {/if}
</div>
{include file='./run_import_script.tpl'}
