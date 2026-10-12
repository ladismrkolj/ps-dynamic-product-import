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
      pane.appendChild(element('p', '', data.rows.length + ' listed. Each feed feature name can be ignored, mapped to an existing shop feature, or created as a feature of the name you choose (it is then connected to that feature). A name without a mapping follows the source setting below. Several feed names, or several sources, may map to the same shop feature; for one product the first feed name in feed order wins and the conflict is logged. Values are matched to the existing values of the shop feature ignoring case and repeated or edge spaces before a new value is created. The optional value formula uses value and fields; a null or empty result skips the feature for that product, for example value == \'TBA\' ? null : value. Changes here are saved immediately, independent of the main Save button. Imports add the feed names they find; Discover scans the whole feed (ignoring the filter) using the saved features formula.'));

      var setting = element('label', '', 'Feed names without a mapping ');
      var settingSelect = element('select', 'form-control');
      settingSelect.style.maxWidth = '420px';
      settingSelect.add(new Option('Ignore (not imported)', 'ignore'));
      settingSelect.add(new Option('Create a feature with the feed name', 'create'));
      settingSelect.value = data.setting;
      var settingIndicator = live();
      settingSelect.addEventListener('change', function () {
        settingSelect.disabled = true; settingIndicator.textContent = 'Saving…';
        post('saveFeatureSetting', {value: settingSelect.value})
          .then(function () { settingIndicator.textContent = 'Saved'; load(); })
          .catch(function (error) { settingIndicator.textContent = error.message; })
          .then(function () { settingSelect.disabled = false; });
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
        var name = tr.insertCell(); name.textContent = row.key;
        if (!row.seen) name.appendChild(element('span', 'label label-warning', 'not seen yet')).style.marginLeft = '6px';
        tr.insertCell().textContent = row.count ? String(row.count) : '';
        var samples = tr.insertCell(); samples.className = 'pi-feature-samples'; samples.textContent = row.samples.join(' | '); samples.title = row.samples.join(' | ');
        var actionCell = tr.insertCell();
        var select = actionSelect(row, data.options);
        select.setAttribute('aria-label', 'Action for ' + row.key);
        var createName = element('input', 'form-control pi-feature-create');
        createName.type = 'text'; createName.maxLength = 128;
        createName.value = row.create_name || row.key;
        createName.setAttribute('aria-label', 'Name of the feature to create for ' + row.key);
        createName.title = 'Name of the feature to create';
        createName.style.marginTop = '4px';
        function toggle() { createName.style.display = select.value === 'create' ? '' : 'none'; }
        toggle();
        actionCell.appendChild(select); actionCell.appendChild(createName);
        var expressionCell = tr.insertCell();
        var expression = element('input', 'form-control pi-feature-formula');
        expression.type = 'text'; expression.value = row.value_expression || '';
        expression.placeholder = 'optional: formula of value and fields';
        expression.setAttribute('aria-label', 'Value formula for ' + row.key);
        expressionCell.appendChild(expression);
        var status = tr.insertCell(); status.className = 'pi-feature-status'; status.textContent = row.status; status.title = row.status;
        var indicator = live(); expressionCell.appendChild(indicator);
        // Saves of one row run strictly in order, each sending the controls' state at that moment: the last change wins.
        var chain = Promise.resolve();
        function save() {
          indicator.textContent = 'Saving…';
          chain = chain.then(function () {
            var values = {key: row.key, rule_action: select.value.indexOf('map:') === 0 ? 'map' : select.value, value_expression: expression.value};
            if (values.rule_action === 'map') values.feature = select.value.slice(4);
            if (values.rule_action === 'create') values.create_name = createName.value;
            return post('saveFeatureRule', values).then(function (result) {
              indicator.textContent = 'Saved'; status.textContent = result.status; status.title = result.status;
            }).catch(function (error) { indicator.textContent = error.message; });
          });
        }
        select.addEventListener('change', function () { toggle(); save(); });
        createName.addEventListener('change', save);
        expression.addEventListener('change', save);
        [createName, expression].forEach(function (input) {
          input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); input.blur(); } });
        });
        var remove = element('button', 'btn btn-default btn-sm', 'Delete');
        remove.type = 'button';
        remove.setAttribute('aria-label', 'Delete row ' + row.key);
        remove.addEventListener('click', function () {
          if (!window.confirm('Delete the row for "' + row.key + '"? If the feed still contains it, it reappears as not mapped the next time the feed is read.')) return;
          remove.disabled = true;
          post('deleteFeatureRule', {key: row.key})
            .then(function () { load(); })
            .catch(function (error) { indicator.textContent = error.message; remove.disabled = false; });
        });
        tr.insertCell().appendChild(remove);
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
