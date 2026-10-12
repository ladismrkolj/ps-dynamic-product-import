{literal}
<script>
(function () {
  function init() {
    var source = document.getElementById('pi-preview');
    var pane = document.getElementById('pi-features');
    if (!source || !pane) return;
    var form = source.closest('form');
    var id = Number(source.dataset.source);
    var state = {loaded: false, token: 0};
    function post(action, values) {
      var body = new URLSearchParams({ajax: '1', action: action, id_source: String(id)});
      Object.keys(values || {}).forEach(function (key) { body.set(key, values[key]); });
      return fetch(source.dataset.url, {method: 'POST', credentials: 'same-origin', body: body})
        .then(function (response) { return response.json(); })
        .then(function (data) { if (data.error) throw new Error(data.error); return data; });
    }
    function element(tag, className, text) {
      var node = document.createElement(tag);
      if (className) node.className = className;
      if (text !== undefined) node.textContent = text;
      return node;
    }
    function unsaved(text) { var node = element('span', 'label label-warning', text || 'unsaved'); node.style.marginLeft = '6px'; return node; }
    function live() { var node = element('span'); node.setAttribute('aria-live', 'polite'); return node; }

    // The existing features are a long list: build the options only when the select is first opened.
    function actionSelect(row, options) {
      var select = element('select', 'form-control pi-feature-action');
      function current() {
        if (row.action === 'map' && row.id_feature != null) return 'map:' + row.id_feature;
        return row.action === 'ignore' || row.action === 'create' ? row.action : 'none';
      }
      function label(value) {
        if (value === 'none') return 'Not mapped (source setting)';
        if (value === 'ignore') return 'Ignore';
        if (value === 'create') return 'Create as…';
        var found = options.find(function (option) { return 'map:' + option.id === value; });
        return found ? found.label : 'Unavailable #' + value.slice(4);
      }
      select.add(new Option(label(current()), current()));
      function expand() {
        if (select.dataset.expanded) return;
        select.dataset.expanded = '1';
        var selected = select.value;
        select.replaceChildren(new Option(label('none'), 'none'), new Option(label('ignore'), 'ignore'), new Option(label('create'), 'create'));
        var group = document.createElement('optgroup');
        group.label = 'Existing feature';
        options.forEach(function (option) { group.appendChild(new Option(option.label, 'map:' + option.id)); });
        select.appendChild(group);
        if (!Array.prototype.some.call(select.options, function (option) { return option.value === selected; })) select.add(new Option(label(selected), selected));
        select.value = selected;
      }
      select.addEventListener('focus', expand);
      select.addEventListener('mousedown', expand);
      select.addEventListener('keydown', function (event) { if (event.key === 'Enter') event.preventDefault(); else expand(); });
      return select;
    }

    function load(notice) {
      if (!id) return;
      var token = ++state.token;
      if (!state.loaded) pane.textContent = 'Loading…';
      post('featureMappings').then(function (data) {
        if (token !== state.token) return;
        state.loaded = true;
        pane.replaceChildren();
        render(data, notice);
      }).catch(function (error) { if (token === state.token) pane.textContent = error.message; });
    }

    function render(data, notice) {
      var howto = element('details', 'pi-howto'); howto.appendChild(element('summary', '', 'How this works'));
      howto.appendChild(element('div', '', data.rows.length + ' listed. Each feed feature name can be ignored, mapped to an existing shop feature, or created as a feature of the name you choose (it is then connected to that feature). A name without a mapping follows the source setting below. Rows not in the feed yet can be added by hand. A deleted row (or one marked with the bulk button, which marks rows seen before but not in the newest scan of this list) comes back as not mapped on the next import or discovery if the feed still has the name. Several feed names, or several sources, may map to the same shop feature; for one product the first feed name in feed order wins and the conflict is logged. Values are matched to the existing values of the shop feature ignoring case and repeated or edge spaces before a new value is created. The optional value formula uses value and fields; a null or empty result skips the feature for that product, for example value == \'TBA\' ? null : value. Changes here are staged and saved together with the form when you press Save. Discover only records names and runs at once. Imports add the feed names they find; Discover scans the whole feed (ignoring the filter) using the saved features formula. The rule for values: a product-level value comes first, then each variant\'s distinct values in variant order. PrestaShop combinations carry attributes, not features, so per-variant values become several values of the product\'s feature. To show a per-size table on the product page, use table() in the description.'));

      var settings = element('section', 'pi-settings'); var settingsHead = element('div', 'pi-settings-head'); settingsHead.appendChild(element('h4', '', 'Settings')); settingsHead.appendChild(howto); settings.appendChild(settingsHead);
      var setting = element('div', 'pi-setting-row'); setting.appendChild(element('label', '', 'Feed names without a mapping'));
      var settingSelect = element('select', 'form-control');
      settingSelect.style.maxWidth = '420px';
      settingSelect.add(new Option('Ignore (not imported)', 'ignore'));
      settingSelect.add(new Option('Create a feature with the feed name', 'create'));
      var part = PiStaging.part('feature');
      settingSelect.value = Object.prototype.hasOwnProperty.call(part, 'setting') ? part.setting : data.setting;
      var settingIndicator = live();
      function showSetting() { settingIndicator.replaceChildren(); if (Object.prototype.hasOwnProperty.call(part, 'setting')) settingIndicator.appendChild(unsaved()); }
      showSetting();
      settingSelect.addEventListener('change', function () {
        if (settingSelect.value === data.setting) delete part.setting; else part.setting = settingSelect.value;
        showSetting(); PiStaging.changed();
      });
      setting.appendChild(settingSelect); setting.appendChild(settingIndicator);
      settings.appendChild(setting); pane.appendChild(settings);

      var bar = element('div', 'form-inline'); bar.style.margin = '10px 0';
      var discover = element('button', 'btn btn-outline-secondary btn-sm', 'Discover features from full feed');
      discover.type = 'button';
      var discoverResult = live();
      discover.addEventListener('click', function () {
        discover.disabled = true; discoverResult.textContent = 'Scanning the feed…';
        post('discoverFeatures').then(function (result) {
          var text = result.unique_features + ' feature names found in ' + result.items_scanned + ' items' + (result.failed_items ? ', ' + result.failed_items + ' items failed: ' + result.errors.join(' | ') : '') + '.';
          load(text);
        }).catch(function (error) { discoverResult.textContent = error.message; })
          .then(function () { discover.disabled = false; });
      });
      bar.appendChild(discover); bar.appendChild(document.createTextNode(' ')); bar.appendChild(discoverResult);

      if (notice) discoverResult.textContent = notice;

      var add = element('div', 'pi-addform');
      var addText = element('input', 'form-control'); addText.type = 'text'; addText.maxLength = 128; addText.style.maxWidth = '320px'; addText.style.display = 'inline-block';
      addText.placeholder = 'Feed feature name, e.g. Wind range (kts)';
      addText.setAttribute('aria-label', 'Feed feature name to add');
      var addSelect = actionSelect({action: 'none'}, data.options); addSelect.style.display = 'inline-block'; addSelect.style.width = '240px';
      addSelect.setAttribute('aria-label', 'Action for the new row');
      var addCreate = element('input', 'form-control'); addCreate.type = 'text'; addCreate.maxLength = 128; addCreate.placeholder = 'Name of the feature to create'; addCreate.style.display = 'none'; addCreate.style.width = '240px'; addCreate.style.maxWidth = '240px';
      var addFormula = element('input', 'form-control'); addFormula.type = 'text'; addFormula.placeholder = 'optional value formula (value, fields)'; addFormula.style.display = 'inline-block'; addFormula.style.width = '280px';
      var addButton = element('button', 'btn btn-outline-secondary btn-sm', 'Add row'); addButton.type = 'button';
      var addNote = live();
      addSelect.addEventListener('change', function () { addCreate.style.display = addSelect.value === 'create' ? 'inline-block' : 'none'; if (addSelect.value === 'create' && !addCreate.value) addCreate.value = addText.value.trim(); });
      addButton.addEventListener('click', function () {
        var value = addText.value.trim();
        if (!value) { addNote.textContent = 'Enter the feed feature name.'; return; }
        if (value.length > 128) { addNote.textContent = 'At most 128 characters.'; return; }
        if (data.rows.some(function (row) { return row.key === value; }) || part.add.some(function (row) { return row.name === value; })) { addNote.textContent = 'This feed feature is already in the list.'; return; }
        var action = addSelect.value.indexOf('map:') === 0 ? 'map' : addSelect.value;
        part.add.push({name: value, rule_action: action, feature: action === 'map' ? addSelect.value.slice(4) : '', create_name: action === 'create' ? addCreate.value : '', value_expression: addFormula.value.trim()});
        PiStaging.changed(); load('Added (unsaved).');
      });
      addText.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); addButton.click(); } });
      [addText, addSelect, addCreate, addFormula, addButton, addNote].forEach(function (control) { add.appendChild(control); add.appendChild(document.createTextNode(' ')); });

      var staleBar = element('div'); staleBar.style.margin = '10px 0';
      staleBar.appendChild(PiStaging.staleButton(data, part, function () { load(); }));


      if (!data.rows.length && !part.add.length) {
        pane.appendChild(bar); pane.appendChild(add); pane.appendChild(staleBar);
        pane.appendChild(element('p', '', 'No feature names yet. Map the features field on the Product fields tab, save, then discover them from the feed or run an import.'));
        return;
      }
      var holder = element('div');
      var shown = 150;
      var ctl = PiStaging.listControls(data.rows.map(function (r) { r.label = r.key; return r; }), true, function () { shown = 150; draw(); });
      ctl.actions.appendChild(bar); ctl.actions.appendChild(staleBar); ctl.addRow.appendChild(add);
      pane.appendChild(ctl.el); pane.appendChild(holder);
      function draw() {
      holder.replaceChildren();
      var vis = ctl.visible();
      var table = element('table', 'table pi-map-table pi-features-table');
      var header = table.createTHead().insertRow();
      ['Feed feature', 'Current (saved) status', 'Action, create name and value formula', ''].forEach(function (title) { header.appendChild(element('th', '', title)); });
      var body = table.createTBody();
      function cell(tr, label) { var td = tr.insertCell(); td.setAttribute('data-label', label); return td; }
      part.add.forEach(function (row, index) {
        var tr = body.insertRow();
        var first = cell(tr, 'Feed feature'); first.textContent = row.name; first.appendChild(unsaved('new, unsaved'));
        cell(tr, 'Current (saved) status').textContent = 'not saved yet';
        var controls = cell(tr, 'Action');
        var select = actionSelect({action: row.rule_action, id_feature: row.feature ? Number(row.feature) : null}, data.options);
        select.classList.add('pi-changed');
        var createName = element('input', 'form-control pi-feature-create pi-changed'); createName.type = 'text'; createName.maxLength = 128; createName.value = row.create_name || '';
        var expression = element('input', 'form-control pi-feature-formula pi-changed'); expression.type = 'text'; expression.value = row.value_expression || ''; expression.placeholder = 'optional value formula (value, fields)';
        function toggle() { createName.style.display = select.value === 'create' ? '' : 'none'; }
        function sync() {
          var action = select.value.indexOf('map:') === 0 ? 'map' : select.value;
          row.rule_action = action; row.feature = action === 'map' ? select.value.slice(4) : ''; row.create_name = action === 'create' ? createName.value : ''; row.value_expression = expression.value.trim();
          PiStaging.changed();
        }
        toggle();
        select.addEventListener('change', function () { toggle(); sync(); });
        createName.addEventListener('input', sync); expression.addEventListener('input', sync);
        controls.appendChild(select); controls.appendChild(createName); controls.appendChild(expression);
        var remove = element('button', 'btn btn-outline-danger btn-sm', 'Remove'); remove.type = 'button';
        remove.addEventListener('click', function () { part.add.splice(index, 1); PiStaging.changed(); load(); });
        cell(tr, '').appendChild(remove);
      });
      vis.slice(0, shown).forEach(function (row) {
        var tr = body.insertRow();
        var staged = part.rules[row.key];
        var eff = {action: row.action, id_feature: row.id_feature, create_name: row.create_name, value_expression: row.value_expression};
        if (staged) {
          eff.action = staged.rule_action;
          eff.id_feature = staged.feature ? Number(staged.feature) : null;
          eff.create_name = staged.create_name;
          eff.value_expression = staged.value_expression;
        }
        var name = cell(tr, 'Feed feature');
        var title = element('div', 'pi-row-title', row.key); name.appendChild(title);
        name.appendChild(element('div', 'pi-row-meta ' + (row.seen ? 'text-success' : 'text-warning'), row.seen ? 'seen in feed ' + row.seen : 'not seen yet'));
        [['product formula', row.count, row.samples], ['variants formula', row.variant_count, row.variant_samples]].forEach(function (o) {
          if (!o[1]) return;
          var line = element('div', 'pi-row-meta pi-feature-samples', o[0] + ': ' + o[1] + ' products, e.g. ' + o[2].join(' | ')); line.title = line.textContent; name.appendChild(line);
        });
        var statusCell = cell(tr, 'Current (saved) status'); statusCell.appendChild(PiStaging.badge(row.badge, row.kind)); statusCell.title = row.status;
        if (row.detail) statusCell.appendChild(element('div', 'pi-row-meta', row.detail));
        var controls = cell(tr, 'Action');
        var select = actionSelect(eff, data.options);
        select.setAttribute('aria-label', 'Action for ' + row.key);
        var createName = element('input', 'form-control pi-feature-create');
        createName.type = 'text'; createName.maxLength = 128;
        createName.value = eff.create_name || row.key;
        createName.setAttribute('aria-label', 'Name of the feature to create for ' + row.key);
        createName.title = 'Name of the feature to create';
        var expression = element('input', 'form-control pi-feature-formula');
        expression.type = 'text'; expression.value = eff.value_expression || '';
        expression.placeholder = 'optional value formula (value, fields)';
        expression.setAttribute('aria-label', 'Value formula for ' + row.key);
        function toggle() { createName.style.display = select.value === 'create' ? '' : 'none'; }
        toggle();
        controls.appendChild(select); controls.appendChild(createName); controls.appendChild(expression);
        var flag = element('span');
        var undo = element('button', 'btn btn-link btn-sm', 'Undo'); undo.type = 'button'; undo.title = 'Back to the saved state';
        var line = element('div'); line.appendChild(flag); line.appendChild(undo); controls.appendChild(line);
        var remove = element('button', 'btn btn-outline-danger btn-sm', 'Delete');
        remove.type = 'button';
        function savedToken() { return row.action === 'map' && row.id_feature != null ? 'map:' + row.id_feature : (row.action === 'ignore' || row.action === 'create' ? row.action : 'none'); }
        function current() {
          var action = select.value.indexOf('map:') === 0 ? 'map' : select.value;
          return {rule_action: action, feature: action === 'map' ? select.value.slice(4) : '', create_name: action === 'create' ? createName.value : '', value_expression: expression.value.trim()};
        }
        function untouched(rule) {
          var feature = row.action === 'map' && row.id_feature != null ? String(row.id_feature) : '';
          var action = ['ignore', 'create', 'map'].indexOf(row.action) !== -1 ? row.action : 'none';
          return rule.rule_action === action && rule.feature === feature && rule.create_name === (action === 'create' ? (row.create_name || '') : '') && rule.value_expression === (row.value_expression || '');
        }
        function show() {
          var deleting = part.del.indexOf(row.key) !== -1;
          var changed = !!part.rules[row.key];
          flag.replaceChildren();
          if (deleting) flag.appendChild(unsaved('will be deleted')); else if (changed) flag.appendChild(unsaved());
          undo.style.display = changed && !deleting ? '' : 'none';
          tr.classList.toggle('pi-row-dirty', changed || deleting);
          [select, createName, expression].forEach(function (control) { control.classList.toggle('pi-changed', changed && !deleting); control.disabled = deleting; });
          title.style.textDecoration = deleting ? 'line-through' : '';
          remove.textContent = deleting ? 'Undo delete' : 'Delete';
          remove.setAttribute('aria-label', (deleting ? 'Undo delete of row ' : 'Delete row ') + row.key);
        }
        function stage() {
          var rule = current();
          if (untouched(rule)) delete part.rules[row.key]; else part.rules[row.key] = rule;
          show(); PiStaging.changed();
        }
        select.addEventListener('change', function () { toggle(); stage(); });
        createName.addEventListener('input', stage);
        expression.addEventListener('input', stage);
        [createName, expression].forEach(function (input) {
          input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); input.blur(); } });
        });
        undo.addEventListener('click', function () {
          select.dispatchEvent(new Event('mousedown'));
          select.value = savedToken();
          createName.value = row.create_name || row.key;
          expression.value = row.value_expression || '';
          toggle(); delete part.rules[row.key];
          show(); PiStaging.changed();
        });
        remove.addEventListener('click', function () {
          var at = part.del.indexOf(row.key);
          if (at === -1) part.del.push(row.key); else part.del.splice(at, 1);
          show(); PiStaging.changed();
        });
        cell(tr, '').appendChild(remove);
        show();
      });
      holder.appendChild(table);
      ctl.count(vis.length, data.rows.length);
      if (vis.length > shown) holder.appendChild(PiStaging.more(vis.length, shown, function () { shown += 150; draw(); }));
      }
      draw();
    }

    if (!id) pane.textContent = 'Save the source first.';
    document.addEventListener('pi-source-tab', function (event) {
      if (event.detail === 'pi-features' && !state.loaded) load();
    });
    var active = document.querySelector('#pi-source-tabs > .tab-content > .tab-pane.active');
    if (active && active.id === 'pi-features') load();
    form.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && event.target.closest('#pi-features')) event.preventDefault();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
</script>
{/literal}
