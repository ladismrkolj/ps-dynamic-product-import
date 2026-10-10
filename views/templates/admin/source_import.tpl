<div id="pi-import-source" class="panel" data-url="{$pi_import_url|escape:'html':'UTF-8'}">
  <h3>Import source configuration</h3>
  <p class="help-block">Upload a <code>.productimport.yml</code> file exported with <strong>Export</strong>. Edit the YAML in any text editor, for example to change category or brand mappings, and upload it again. Categories, brands, suppliers and the language are matched by name; unmatched names are listed below.</p>
  <div class="form-group">
    <label>What to do with the file</label>
    {if $pi_import_can_create}
    <div class="radio"><label><input type="radio" name="pi-import-target" value="new" checked> Create new source</label></div>
    {/if}
    {if $pi_import_can_overwrite && $pi_import_sources}
    <div class="radio"><label><input type="radio" name="pi-import-target" value="overwrite"{if !$pi_import_can_create} checked{/if}> Overwrite existing source</label></div>
    <div id="pi-import-overwrite" style="margin-left:20px">
      <select id="pi-import-id" class="form-control" style="max-width:420px">
        {foreach from=$pi_import_sources item=pi_source}
        <option value="{$pi_source.id_source|intval}">{$pi_source.name|escape:'html':'UTF-8'} ({$pi_source.technical_key|escape:'html':'UTF-8'})</option>
        {/foreach}
      </select>
      <p class="help-block">Entries in the file overwrite the source; settings and category or brand mappings missing from the file are kept unchanged. The technical key is never changed. An entry with <code>category: null</code> or <code>brand: null</code> resets that mapping to auto-create.</p>
    </div>
    {/if}
  </div>
  <div class="form-group">
    <label for="pi-import-file">Export file</label>
    <input type="file" id="pi-import-file" accept=".yml,.yaml,text/yaml">
  </div>
  <div class="form-group">
    <label for="pi-import-text">&hellip; or paste the exported YAML</label>
    <textarea id="pi-import-text" rows="3" class="form-control"></textarea>
  </div>
  <button type="button" id="pi-import-button" class="btn btn-default">Import</button>
  <div id="pi-import-result" aria-live="polite" style="margin-top:10px"></div>
</div>
{literal}
<script>
(function () {
  var panel = document.getElementById('pi-import-source');
  if (!panel) { return; }
  var button = document.getElementById('pi-import-button');
  var result = document.getElementById('pi-import-result');
  var radios = panel.querySelectorAll('input[name="pi-import-target"]');
  var overwriteBox = document.getElementById('pi-import-overwrite');

  function target() {
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].checked) { return radios[i].value; }
    }
    return 'new';
  }

  function syncTarget() {
    if (overwriteBox) { overwriteBox.style.display = target() === 'overwrite' ? '' : 'none'; }
  }
  for (var r = 0; r < radios.length; r++) { radios[r].addEventListener('change', syncTarget); }
  syncTarget();

  function alertBox(kind, text) {
    var box = document.createElement('div');
    box.className = 'alert alert-' + kind;
    box.textContent = text;
    return box;
  }

  button.addEventListener('click', function () {
    var file = document.getElementById('pi-import-file').files[0];
    var pasted = document.getElementById('pi-import-text').value;
    var mode = target();
    result.textContent = '';
    if (!file && pasted.trim() === '') {
      result.appendChild(alertBox('danger', 'Choose a .productimport.yml file or paste its content.'));
      return;
    }
    if (mode === 'overwrite') {
      var select = document.getElementById('pi-import-id');
      var label = select.options[select.selectedIndex].text;
      if (!window.confirm('Overwrite the source "' + label + '" with the entries of this file? Settings and mappings missing from the file stay as they are.')) { return; }
    }
    var body = new FormData();
    body.append('target', mode);
    if (mode === 'overwrite') { body.append('id_source', document.getElementById('pi-import-id').value); }
    if (file) { body.append('source_file', file); } else { body.append('source_text', pasted); }
    button.disabled = true;
    fetch(panel.getAttribute('data-url'), {method: 'POST', body: body, credentials: 'same-origin'})
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data.error) { result.appendChild(alertBox('danger', data.error)); return; }
        var ok = document.createElement('div');
        ok.className = 'alert alert-success';
        ok.appendChild(document.createTextNode(data.mode === 'overwrite' ? 'Source updated: ' : 'Source created: '));
        var link = document.createElement('a');
        link.href = data.edit_url;
        link.textContent = data.name + ' (' + data.technical_key + ')';
        ok.appendChild(link);
        if (data.mode === 'overwrite' && data.counts) {
          var c = data.counts;
          var summary = document.createElement('div');
          summary.textContent = 'Category mappings: ' + c.categories_created + ' added, ' + c.categories_updated + ' updated. Brand mappings: ' + c.brands_created + ' added, ' + c.brands_updated + ' updated.';
          ok.appendChild(summary);
        }
        result.appendChild(ok);
        if (data.warnings && data.warnings.length) {
          var warn = document.createElement('div');
          warn.className = 'alert alert-warning';
          warn.appendChild(document.createTextNode(data.mode === 'overwrite'
            ? 'These names did not match this store and were left as they were:'
            : 'Review these before running the import. Unmatched names were left unset (categories and brands are auto-created, or the source default applies):'));
          var list = document.createElement('ul');
          data.warnings.forEach(function (text) {
            var item = document.createElement('li');
            item.textContent = text;
            list.appendChild(item);
          });
          warn.appendChild(list);
          result.appendChild(warn);
        }
      })
      .catch(function (error) { result.appendChild(alertBox('danger', 'Import failed: ' + error.message)); })
      .then(function () { button.disabled = false; });
  });
}());
</script>
{/literal}
