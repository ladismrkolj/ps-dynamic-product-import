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
      pane.appendChild(element('p', '', data.rows.length + ' listed. Each feed feature name can be ignored, mapped to an existing shop feature, or created as a feature of the name you choose (it is then connected to that feature). A name without a mapping follows the source setting below. Several feed names, or several sources, may map to the same shop feature; for one product the first feed name in feed order wins and the conflict is logged. Values are matched to the existing values of the shop feature ignoring case and repeated or edge spaces before a new value is created. The optional value formula uses value and fields; a null or empty result skips the feature for that product, for example value == \'TBA\' ? null : value. Changes here are staged and saved together with the form when you press Save. Discover only records names and runs at once. Imports add the feed names they find; Discover scans the whole feed (ignoring the filter) using the saved features formula.'));

      var setting = element('label', '', 'Feed names without a mapping ');
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
      pane.appendChild(setting);

      var bar = element('div', 'form-inline'); bar.style.margin = '10px 0';
      var discover = element('button', 'btn btn-default', 'Discover features from full feed');
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
      pane.appendChild(bar);
      if (notice) discoverResult.textContent = notice;

      if (!data.rows.length) {
        pane.appendChild(element('p', '', 'No feature names yet. Map the features field on the Product fields tab, save, then discover them from the feed or run an import.'));
        return;
      }
      var table = element('table', 'table');
      var header = table.createTHead().insertRow();
      ['Feed feature', 'Products', 'Sample values', 'Action', 'Value formula', 'Status', ''].forEach(function (title) { header.appendChild(element('th', '', title)); });
      var body = table.createTBody();
      data.rows.forEach(function (row) {
        var tr = body.insertRow();
        var staged = part.rules[row.key];
        var eff = {action: row.action, id_feature: row.id_feature, create_name: row.create_name, value_expression: row.value_expression};
        if (staged) {
          eff.action = staged.rule_action;
          eff.id_feature = staged.feature ? Number(staged.feature) : null;
          eff.create_name = staged.create_name;
          eff.value_expression = staged.value_expression;
        }
        var name = tr.insertCell(); name.textContent = row.key;
        if (!row.seen) name.appendChild(element('span', 'label label-warning', 'not seen yet')).style.marginLeft = '6px';
        tr.insertCell().textContent = row.count ? String(row.count) : '';
        var samples = tr.insertCell(); samples.className = 'pi-feature-samples'; samples.textContent = row.samples.join(' | '); samples.title = row.samples.join(' | ');
        var actionCell = tr.insertCell();
        var select = actionSelect(eff, data.options);
        select.setAttribute('aria-label', 'Action for ' + row.key);
        var createName = element('input', 'form-control pi-feature-create');
        createName.type = 'text'; createName.maxLength = 128;
        createName.value = eff.create_name || row.key;
        createName.setAttribute('aria-label', 'Name of the feature to create for ' + row.key);
        createName.title = 'Name of the feature to create';
        createName.style.marginTop = '4px';
        function toggle() { createName.style.display = select.value === 'create' ? '' : 'none'; }
        toggle();
        actionCell.appendChild(select); actionCell.appendChild(createName);
        var expressionCell = tr.insertCell();
        var expression = element('input', 'form-control pi-feature-formula');
        expression.type = 'text'; expression.value = eff.value_expression || '';
        expression.placeholder = 'optional: formula of value and fields';
        expression.setAttribute('aria-label', 'Value formula for ' + row.key);
        expressionCell.appendChild(expression);
        var status = tr.insertCell(); status.className = 'pi-feature-status'; status.textContent = row.status; status.title = row.status;
        var flag = element('span'); expressionCell.appendChild(flag);
        var remove = element('button', 'btn btn-default btn-sm', 'Delete');
        remove.type = 'button';
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
          flag.replaceChildren();
          if (deleting) flag.appendChild(unsaved('will be deleted')); else if (part.rules[row.key]) flag.appendChild(unsaved());
          if (part.rules[row.key] || deleting) { status.textContent = deleting ? 'will be deleted when you press Save' : 'changed, saved when you press Save'; status.title = status.textContent; }
          name.style.textDecoration = deleting ? 'line-through' : '';
          [select, createName, expression].forEach(function (control) { control.disabled = deleting; });
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
        remove.addEventListener('click', function () {
          var at = part.del.indexOf(row.key);
          if (at === -1) part.del.push(row.key); else part.del.splice(at, 1);
          show(); PiStaging.changed();
        });
        tr.insertCell().appendChild(remove);
        show();
      });
      pane.appendChild(table);
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
