{literal}
<script>
(function () {
  function init() {
    var source = document.getElementById('pi-preview');
    var id = Number(source.dataset.source);
    var states = {};
    var NOT_MAPPED = 'Not mapped (default, else find or create, then connect)';
    function post(action, type, values) {
      var body = new URLSearchParams({ajax: '1', action: action, id_source: String(id), type: type});
      Object.keys(values || {}).forEach(function (key) { body.set(key, values[key]); });
      return fetch(source.dataset.url, {method: 'POST', credentials: 'same-origin', body: body})
        .then(function (response) { return response.json(); })
        .then(function (data) { if (data.error) throw new Error(data.error); return data; });
    }
    function select(options, value, empty) {
      var element = document.createElement('select');
      element.className = 'form-control';
      element.add(new Option(value == null || value === '' ? empty : (options.find(function (option) { return Number(option.id) === Number(value); }) || {}).label || 'Unavailable #' + value, value == null ? '' : String(value)));
      function expand() {
        if (element.dataset.expanded) return;
        element.dataset.expanded = '1';
        var selected = element.value;
        element.replaceChildren(new Option(empty, ''));
        options.forEach(function (option) { element.add(new Option(option.label, String(option.id))); });
        if (selected && !options.some(function (option) { return String(option.id) === selected; })) element.add(new Option('Unavailable #' + selected, selected));
        element.value = selected;
      }
      element.addEventListener('focus', expand);
      element.addEventListener('mousedown', expand);
      element.addEventListener('keydown', function (event) { if (event.key === 'Enter') event.preventDefault(); else expand(); });
      return element;
    }
    function badge(text) { var node = document.createElement('span'); node.className = 'label label-warning'; node.textContent = text; node.style.marginLeft = '6px'; return node; }
    function load(type, notice) {
      var state = states[type];
      if (!id) return;
      var token = ++state.token;
      if (!state.loaded) state.pane.textContent = 'Loading…';
      post('mappings', type).then(function (data) {
        if (token !== state.token) return;
        state.loaded = true;
        state.pane.replaceChildren();
        render(type, state, data, notice);
      }).catch(function (error) { if (token === state.token) state.pane.textContent = error.message; });
    }
    function render(type, state, data, notice) {
      var part = PiStaging.part(type);
      var help = document.createElement('p');
      help.textContent = data.rows.length + ' listed. A value without a mapping is resolved by the next import (the source default if one is set, otherwise an existing ' + (type === 'category' ? 'category with the same name path, or a new one' : 'brand with the same name, or a new one') + ') and the result is stored here as its mapping. Choose the empty option to clear a mapping so the next import resolves it again. Changes here are staged and saved together with the form when you press Save. The feed is read automatically when the source settings change; rows appear here once the feed contains them.';
      state.pane.appendChild(help);
      var label = document.createElement('label'); label.textContent = 'Default for anything not mapped below';
      state.pane.appendChild(label);
      var original = data.default == null ? '' : String(data.default);
      var current = Object.prototype.hasOwnProperty.call(part, 'default') ? part.default : original;
      var defaultSelect = select(data.options, current, 'No default (find or create, then connect)');
      label.appendChild(defaultSelect);
      var defaultFlag = document.createElement('span'); defaultFlag.setAttribute('aria-live', 'polite'); label.appendChild(defaultFlag);
      function showDefault() { defaultFlag.replaceChildren(); if (Object.prototype.hasOwnProperty.call(part, 'default')) defaultFlag.appendChild(badge('unsaved')); }
      showDefault();
      defaultSelect.addEventListener('change', function () {
        if (defaultSelect.value === original) delete part.default; else part.default = defaultSelect.value;
        showDefault(); PiStaging.changed();
      });

      var add = document.createElement('div'); add.className = 'form-inline'; add.style.margin = '10px 0';
      var addText = document.createElement('input'); addText.type = 'text'; addText.className = 'form-control'; addText.style.minWidth = '260px';
      addText.placeholder = type === 'category' ? 'Feed category, e.g. Sports > Sails' : 'Feed brand name';
      addText.setAttribute('aria-label', type === 'category' ? 'Feed category path to add' : 'Feed brand name to add');
      var addSelect = select(data.options, null, NOT_MAPPED);
      addSelect.setAttribute('aria-label', 'Destination for the new row');
      var addButton = document.createElement('button'); addButton.type = 'button'; addButton.className = 'btn btn-default'; addButton.textContent = 'Add row';
      var addIndicator = document.createElement('span'); addIndicator.setAttribute('aria-live', 'polite');
      addText.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); addButton.click(); } });
      addButton.addEventListener('click', function () {
        if (!addText.value.trim()) { addIndicator.textContent = type === 'category' ? 'Enter the feed category path.' : 'Enter the feed brand name.'; return; }
        part.add.push({label: addText.value.trim(), value: addSelect.value});
        PiStaging.changed();
        load(type, 'Added (unsaved).');
      });
      [addText, addSelect, addButton, addIndicator].forEach(function (element) { add.appendChild(element); add.appendChild(document.createTextNode(' ')); });
      state.pane.appendChild(add);
      if (notice) { addIndicator.textContent = notice; }

      if (!data.rows.length && !part.add.length) {
        var empty = document.createElement('p');
        empty.textContent = 'No rows yet. Add one above, or load the feed (set the ' + (type === 'category' ? 'category_paths' : 'manufacturer') + ' mapping on the Product fields tab and save).';
        state.pane.appendChild(empty); return;
      }
      var table = document.createElement('table'); table.className = 'table';
      var header = table.createTHead().insertRow();
      ['Feed value', 'In feed', 'Current status', 'Destination', ''].forEach(function (title) { var cell = document.createElement('th'); cell.textContent = title; header.appendChild(cell); });
      var body = table.createTBody();
      part.add.forEach(function (row, index) {
        var tr = body.insertRow(); tr.insertCell().textContent = row.label;
        tr.insertCell().appendChild(badge('new, unsaved'));
        tr.insertCell().textContent = 'will be added when you press Save';
        var control = select(data.options, row.value, NOT_MAPPED);
        control.setAttribute('aria-label', 'Destination for ' + row.label);
        control.addEventListener('change', function () { row.value = control.value; PiStaging.changed(); });
        tr.insertCell().appendChild(control);
        var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-default btn-sm'; remove.textContent = 'Remove';
        remove.addEventListener('click', function () { part.add.splice(index, 1); PiStaging.changed(); load(type); });
        tr.insertCell().appendChild(remove);
      });
      data.rows.forEach(function (row) {
        var tr = body.insertRow(); var name = tr.insertCell(); name.textContent = row.label;
        var badgeCell = tr.insertCell();
        var seen = document.createElement('span');
        seen.className = 'label ' + (row.seen ? 'label-success' : 'label-warning');
        seen.textContent = row.seen ? 'seen in feed ' + row.seen : 'not seen yet';
        badgeCell.appendChild(seen);
        var status = tr.insertCell(); status.textContent = row.status;
        var control = select(data.options, Object.prototype.hasOwnProperty.call(part.rows, row.key) ? part.rows[row.key] : row.id, NOT_MAPPED);
        control.setAttribute('aria-label', 'Destination for ' + row.label);
        var originalId = row.id == null ? '' : String(row.id);
        var flag = document.createElement('span');
        var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-default btn-sm';
        function show() {
          var deleting = part.del.indexOf(row.key) !== -1;
          var changed = Object.prototype.hasOwnProperty.call(part.rows, row.key);
          flag.replaceChildren();
          if (deleting) flag.appendChild(badge('will be deleted')); else if (changed) flag.appendChild(badge('unsaved'));
          name.style.textDecoration = deleting ? 'line-through' : '';
          control.disabled = deleting;
          remove.textContent = deleting ? 'Undo delete' : 'Delete';
          remove.setAttribute('aria-label', (deleting ? 'Undo delete of row ' : 'Delete row ') + row.label);
        }
        control.addEventListener('change', function () {
          if (control.value === originalId) delete part.rows[row.key]; else part.rows[row.key] = control.value;
          show(); PiStaging.changed();
        });
        remove.addEventListener('click', function () {
          var at = part.del.indexOf(row.key);
          if (at === -1) part.del.push(row.key); else part.del.splice(at, 1);
          show(); PiStaging.changed();
        });
        var cell = tr.insertCell(); cell.appendChild(control); cell.appendChild(flag);
        tr.insertCell().appendChild(remove);
        show();
      });
      state.pane.appendChild(table);
    }
    ['category', 'brand'].forEach(function (type) {
      var pane = document.getElementById(type === 'category' ? 'pi-category-mappings' : 'pi-brands');
      states[type] = {pane: pane, loaded: false, token: 0};
      if (!id) pane.textContent = 'Save the source first.';
    });
    document.addEventListener('pi-source-tab', function (event) {
      var type = event.detail === 'pi-categories' ? 'category' : event.detail === 'pi-brands' ? 'brand' : null;
      if (type && !states[type].loaded) load(type);
    });
    document.addEventListener('pi-feed-discovered', function () {
      ['category', 'brand'].forEach(function (type) { if (states[type].loaded) load(type); });
    });
    var active = document.querySelector('#pi-source-tabs > .tab-content > .tab-pane.active');
    if (active) document.dispatchEvent(new CustomEvent('pi-source-tab', {detail: active.id}));
    document.getElementById('pi-preview').closest('form').addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && event.target.closest('#pi-categories, #pi-brands')) event.preventDefault();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
</script>
{/literal}
