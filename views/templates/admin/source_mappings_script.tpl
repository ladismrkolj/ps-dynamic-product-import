{literal}
<script>
(function () {
  function init() {
    var source = document.getElementById('pi-preview');
    var form = source.closest('form');
    var id = Number(source.dataset.source);
    var states = {};
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
      element.add(new Option(value == null ? empty : (options.find(function (option) { return Number(option.id) === Number(value); }) || {}).label || 'Unavailable #' + value, value == null ? '' : String(value)));
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
    // Every reload takes a token; only the newest response renders, so overlapping loads never drop or clobber each other.
    function load(type, notice) {
      var state = states[type];
      if (!id) return;
      var token = ++state.token;
      if (!state.loaded) state.pane.textContent = 'Loading…';
      post('mappings', type).then(function (data) {
        if (token !== state.token) return;
        state.loaded = true;
        state.pane.replaceChildren();
        var help = document.createElement('p');
        help.textContent = data.rows.length + ' listed. Changes here are saved immediately, independent of the main Save button. The feed is read automatically when the source settings change; rows appear here once the feed contains them.';
        state.pane.appendChild(help);
        var label = document.createElement('label'); label.textContent = 'Default for anything not mapped below';
        state.pane.appendChild(label);
        var defaultSelect = select(data.options, data.default, 'Auto-create (no default)');
        label.appendChild(defaultSelect);
        function save(control, action, key, indicator, status, rematch) {
          control.addEventListener('change', function () {
            var value = control.value;
            control.disabled = true; indicator.textContent = 'Saving…';
            post(action, type, key == null ? {value: value} : {key: key, value: value})
              .then(function (result) {
                indicator.textContent = 'Saved';
                if (status) status.textContent = result.status;
                if (rematch) rematch.hidden = !result.auto;
                if (action === 'saveDefault') load(type);
              })
              .catch(function (error) { indicator.textContent = error.message; })
              .then(function () { control.disabled = false; });
          });
        }
        var defaultIndicator = document.createElement('span'); defaultIndicator.setAttribute('aria-live', 'polite'); label.appendChild(defaultIndicator);
        save(defaultSelect, 'saveDefault', null, defaultIndicator, null);

        var add = document.createElement('div'); add.className = 'form-inline'; add.style.margin = '10px 0';
        var addText = document.createElement('input'); addText.type = 'text'; addText.className = 'form-control'; addText.style.minWidth = '260px';
        addText.placeholder = type === 'category' ? 'Feed category, e.g. Sports > Sails' : 'Feed brand name';
        addText.setAttribute('aria-label', type === 'category' ? 'Feed category path to add' : 'Feed brand name to add');
        var addSelect = select(data.options, null, 'Auto-create');
        addSelect.setAttribute('aria-label', 'Destination for the new row');
        var addButton = document.createElement('button'); addButton.type = 'button'; addButton.className = 'btn btn-default'; addButton.textContent = 'Add row';
        var addIndicator = document.createElement('span'); addIndicator.setAttribute('aria-live', 'polite');
        addText.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); addButton.click(); } });
        addButton.addEventListener('click', function () {
          addButton.disabled = true; addIndicator.textContent = 'Adding…';
          post('addMapping', type, {label: addText.value, value: addSelect.value})
            .then(function () { load(type, 'Added.'); })
            .catch(function (error) { addIndicator.textContent = error.message; })
            .then(function () { addButton.disabled = false; });
        });
        [addText, addSelect, addButton, addIndicator].forEach(function (element) { add.appendChild(element); add.appendChild(document.createTextNode(' ')); });
        state.pane.appendChild(add);
        if (notice) { addIndicator.textContent = notice; setTimeout(function () { if (addIndicator.textContent === notice) addIndicator.textContent = ''; }, 3000); }

        if (!data.rows.length) {
          var empty = document.createElement('p');
          empty.textContent = 'No rows yet. Add one above, or load the feed (set the ' + (type === 'category' ? 'category_paths' : 'manufacturer') + ' mapping on the Product fields tab and save).';
          state.pane.appendChild(empty); return;
        }
        var table = document.createElement('table'); table.className = 'table';
        var header = table.createTHead().insertRow();
        ['Feed value', 'In feed', 'Current status', 'Destination', ''].forEach(function (title) { var cell = document.createElement('th'); cell.textContent = title; header.appendChild(cell); });
        var body = table.createTBody();
        data.rows.forEach(function (row) {
          var tr = body.insertRow(); tr.insertCell().textContent = row.label;
          var badge = document.createElement('span');
          badge.className = 'label ' + (row.seen ? 'label-success' : 'label-warning');
          badge.textContent = row.seen ? 'seen in feed ' + row.seen : 'not seen yet';
          tr.insertCell().appendChild(badge);
          var status = tr.insertCell(); status.textContent = row.status;
          var cell = tr.insertCell();
          var control = select(data.options, row.id, 'Use default (auto-create if none)');
          control.setAttribute('aria-label', 'Destination for ' + row.label);
          cell.appendChild(control);
          var indicator = document.createElement('span'); indicator.setAttribute('aria-live', 'polite'); cell.appendChild(indicator);
          var rematch = null;
          if (type === 'category') {
            rematch = document.createElement('button'); rematch.type = 'button'; rematch.className = 'btn btn-default btn-sm'; rematch.textContent = 'Re-match by name';
            rematch.setAttribute('aria-label', 'Re-match by name: ' + row.label);
            rematch.hidden = !row.auto;
            rematch.addEventListener('click', function () {
              if (!window.confirm('Forget the remembered category for "' + row.label + '"? The next import looks the category up by name again (under the source root category, Home by default) and may create a new one there.')) return;
              rematch.disabled = true;
              post('rematchCategory', type, {key: row.key})
                .then(function () { load(type); })
                .catch(function (error) { indicator.textContent = error.message; rematch.disabled = false; });
            });
          }
          save(control, 'saveMapping', row.key, indicator, status, rematch);
          var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-default btn-sm'; remove.textContent = 'Delete';
          remove.setAttribute('aria-label', 'Delete row ' + row.label);
          remove.addEventListener('click', function () {
            if (!window.confirm('Delete the row for "' + row.label + '"? If the feed still contains it, it reappears as auto-create the next time the feed is read.')) return;
            remove.disabled = true;
            post('deleteMapping', type, {key: row.key})
              .then(function () { load(type); })
              .catch(function (error) { indicator.textContent = error.message; remove.disabled = false; });
          });
          var actions = tr.insertCell();
          if (rematch) { actions.appendChild(rematch); actions.appendChild(document.createTextNode(' ')); }
          actions.appendChild(remove);
        });
        state.pane.appendChild(table);
      }).catch(function (error) { if (token === state.token) state.pane.textContent = error.message; });
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
      ['category', 'brand'].forEach(function (type) { load(type); });
    });
    var active = document.querySelector('#pi-source-tabs > .tab-content > .tab-pane.active');
    if (active) document.dispatchEvent(new CustomEvent('pi-source-tab', {detail: active.id}));
    form.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && event.target.closest('#pi-categories, #pi-brands')) event.preventDefault();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
</script>
{/literal}
