{literal}
<style>
#pi-grid-root .pi-grid-toolbar { margin-bottom: 8px; }
#pi-grid-root .pi-grid-toolbar > * { margin-right: 8px; vertical-align: middle; }
#pi-grid-root table td, #pi-grid-root table th { vertical-align: top; }
#pi-grid-root .pi-partial td:first-child { background: #fff8e1; }
#pi-grid-root .pi-samples { color: #555; word-break: break-word; }
#pi-grid-root .pi-map select { max-width: 100%; }
#pi-grid-root .pi-map textarea { width: 100%; }
#pi-grid-root .pi-map .pi-target-help { display: block; color: #666; margin-top: 2px; }
#pi-grid-root .pi-formula-box { margin-top: 6px; }
#pi-grid-root .pi-formula-box > * { margin-right: 6px; }
#pi-grid-root details { margin-bottom: 16px; }
#pi-configuration-panel .pi-cell-error { background: #f2dede; outline: 1px solid #a94442; }
#pi-configuration-panel .pi-preview-scroll { overflow-x: auto; max-width: 100%; }
#pi-configuration-panel .pi-excluded td { color: #777; }
</style>
<script>
(function () {
  'use strict';

  // [name, badge, description]
  var PRODUCT_TARGETS = [
    ['name', 'required for new products', 'Product name.'],
    ['reference', 'recommended', 'Product reference (SKU).'],
    ['price', 'recommended', 'Selling price.'],
    ['regular_price', '', 'Original price; when higher than price, price is applied as a discount.'],
    ['wholesale_price', '', 'Purchase price.'],
    ['short_description', '', 'Short description (HTML allowed).'],
    ['description', '', 'Long description (HTML allowed).'],
    ['ean13', '', 'EAN-13 barcode.'],
    ['weight', '', 'Weight.'],
    ['quantity', '', 'Stock quantity.'],
    ['active', '', 'Enabled flag; false disables the product.'],
    ['manufacturer', '', 'Brand name; see the Brands tab.'],
    ['category_paths', 'recommended, list', 'A single category name or a breadcrumb list; see the Categories tab.'],
    ['images', 'recommended, list of URLs', 'List of image URLs.'],
    ['main_image', '', 'Cover image URL; defaults to the first entry of images.']
  ];
  // These targets receive the whole list; every other canonical target gets first(<list>).
  var LIST_TARGETS = ['images', 'category_paths'];
  var VARIANT_TARGETS = [
    ['reference', 'required for variants', 'Combination reference (SKU).'],
    ['ean13', '', 'Combination EAN-13.'],
    ['price', '', 'Absolute combination price.'],
    ['quantity', '', 'Combination stock.'],
    ['active', '', 'false sets zero stock.'],
    ['image', '', 'Combination image URL.']
  ];

  function quote(value) {
    return "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
  }
  function isListColumn(parts) { return parts[parts.length - 1] === null; }
  // Path of the value itself: the trailing list marker is dropped, a list column is the list.
  function base(parts) { return isListColumn(parts) ? parts.slice(0, -1) : parts; }
  function innerMarker(parts) { return base(parts).indexOf(null) !== -1; }
  function hasDot(parts) { return parts.some(function (part) { return String(part).indexOf('.') !== -1; }); }
  function bracket(parts, root) {
    return root + parts.map(function (part) { return '[' + quote(part) + ']'; }).join('');
  }
  // path() returns null for a missing key; it splits on dots, so keys with dots fall back to brackets.
  function nullSafe(parts, root) {
    return hasDot(parts) ? bracket(parts, root) : 'path(' + root + ', ' + quote(parts.join('.')) + ')';
  }
  // Canonical form: fields['key'] for a top-level key, path(fields, 'a.b') for nested paths.
  function canonical(parts, root) {
    return parts.length === 1 ? root + '[' + quote(parts[0]) + ']' : nullSafe(parts, root);
  }
  function wrapped(expression, wrap) { return wrap ? 'first(' + expression + ')' : expression; }
  function alternatives(parts, root, wrap) {
    var out = [wrapped(canonical(base(parts), root), wrap), wrapped(nullSafe(base(parts), root), wrap)];
    return out.filter(function (expression, index) { return out.indexOf(expression) === index; });
  }
  function colId(col) { return JSON.stringify(col.parts); }
  function percent(present, total) {
    if (!total) return '0';
    return String(present === total ? 100 : Math.floor(present / total * 1000) / 10);
  }
  function age(seconds) {
    if (seconds < 5) return 'just now';
    if (seconds < 60) return seconds + ' s ago';
    return Math.floor(seconds / 60) + ' min ago';
  }
  function typeLabel(col) {
    var types = col.types.join('/');
    if (col.kind === 'list_of_objects') return 'list of objects (max ' + col.max_length + ')';
    if (col.kind === 'list_of_scalars') return 'list of ' + (types || 'values') + ' (max ' + col.max_length + ')';
    return col.kind === 'object' ? 'object' : (types || 'empty');
  }
  function optionLabel(name, col) {
    return name + '  · ' + (isListColumn(col.parts) ? 'list · ' : '') + col.present + '/' + col.total + ' products';
  }
  function selectable(col) { return col.kind !== 'object' && col.kind !== 'list_of_objects'; }
  function section(container, title, values) {
    var heading = document.createElement('h4');
    heading.textContent = title;
    container.appendChild(heading);
    var table = document.createElement('table');
    table.className = 'table';
    Object.keys(values || {}).forEach(function (key) {
      var row = table.insertRow();
      row.insertCell().textContent = key;
      var pre = document.createElement('pre');
      pre.textContent = JSON.stringify(values[key], null, 2);
      row.insertCell().appendChild(pre);
    });
    container.appendChild(table);
  }

  function init() {
    var panel = document.getElementById('pi-preview');
    var root = document.getElementById('pi-grid-root');
    if (!panel || !root || panel.dataset.editorReady) return;
    panel.dataset.editorReady = '1';
    var form = panel.closest('form');
    var referenceBody = root.querySelector('#pi-reference tbody');
    var statusLine = document.getElementById('pi-feed-status');
    var emptyMessage = document.getElementById('pi-reference-empty');
    var searchInput = document.getElementById('pi-feed-search');
    var variantsList = document.getElementById('pi-variants-list');
    var variantsCustom = document.getElementById('pi-variants-custom');
    var variantMappings = document.getElementById('pi-variant-mappings');
    var identifier = form.querySelector('[name="identifier_field"]');
    var identifierPicker = document.getElementById('pi-identifier-picker');
    var identifierTouched = false;
    var identifierChoice = '';
    var saved = {};
    try { saved = JSON.parse(root.dataset.state || '{}'); } catch (error) { saved = {}; }

    var schema = {columns: [], total: 0, truncated: false};
    var byId = Object.create(null);
    var vlist = {mode: 'none', id: '', expr: ''}; // mode: none | col | custom
    var loadSeq = 0;

    function feedBody(action, refresh) {
      var body = new URLSearchParams();
      body.set('ajax', '1');
      body.set('action', action);
      if (Number(panel.dataset.source) > 0) body.set('id_source', panel.dataset.source);
      ['json_url', 'json_file_path', 'source_format', 'xml_item_path'].forEach(function (name) {
        body.set(name, form.querySelector('[name="' + name + '"]').value);
      });
      if (refresh) body.set('refresh', '1');
      return body;
    }
    function post(body) {
      return fetch(panel.dataset.url, {method: 'POST', credentials: 'same-origin', body: body}).then(function (response) { return response.json(); });
    }
    function hasLocation() {
      return form.querySelector('[name="json_url"]').value.trim() !== '' || form.querySelector('[name="json_file_path"]').value.trim() !== '';
    }
    function addSampler(cell, textarea) {
      var controls = document.createElement('div');
      var label = document.createElement('label');
      label.textContent = 'Sample size ';
      var size = document.createElement('input');
      size.type = 'number';
      size.min = '1';
      size.max = '500';
      size.value = '50';
      label.appendChild(size);
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-default pi-sample-values';
      button.textContent = 'Sample values';
      var output = document.createElement('div');
      output.className = 'pi-sample-results';
      output.style.display = 'none';
      output.setAttribute('aria-live', 'polite');
      controls.appendChild(label);
      controls.appendChild(button);
      controls.appendChild(output);
      cell.appendChild(controls);
      button.addEventListener('click', function () {
        output.replaceChildren();
        output.style.display = '';
        if (!textarea.value.trim()) {
          output.textContent = 'Write an expression first';
          return;
        }
        button.disabled = true;
        button.textContent = 'Sampling…';
        var body = feedBody('sampleValues', false);
        body.set('expression', textarea.value);
        body.set('sample_size', size.value);
        post(body).then(function (data) {
          if (data.error) {
            output.textContent = data.error;
            return;
          }
          var status = document.createElement('p');
          status.textContent = data.items_scanned + ' items scanned, ' + data.distinct_total + ' distinct values' +
            (data.truncated ? ' (showing first 50)' : '') + ', ' + data.errors.count + ' errors';
          output.appendChild(status);
          var table = document.createElement('table');
          table.className = 'table table-condensed';
          var header = table.insertRow();
          ['Value', 'Count'].forEach(function (title) {
            var heading = document.createElement('th');
            heading.textContent = title;
            header.appendChild(heading);
          });
          data.values.forEach(function (entry) {
            var row = table.insertRow();
            row.insertCell().textContent = entry.value;
            row.insertCell().textContent = entry.count;
          });
          output.appendChild(table);
          if (data.errors.messages.length) {
            var details = document.createElement('details');
            var summary = document.createElement('summary');
            summary.textContent = 'Errors';
            details.appendChild(summary);
            data.errors.messages.forEach(function (message) {
              var line = document.createElement('div');
              line.textContent = message;
              details.appendChild(line);
            });
            output.appendChild(details);
          }
        })
          .catch(function (error) { output.textContent = 'Sampling failed: ' + error.message; })
          .then(function () { button.disabled = false; button.textContent = 'Sample values'; });
      });
    }

    // ---- Feed fields as a schema view ----
    function productColumns() { return schema.columns.filter(function (col) { return !innerMarker(col.parts); }); }
    function variantListColumns() {
      return productColumns().filter(function (col) { return col.kind === 'list_of_objects'; });
    }
    function variantColumns() {
      if (vlist.mode !== 'col' || !byId[vlist.id]) return [];
      var listParts = byId[vlist.id].parts;
      var out = [];
      schema.columns.forEach(function (col) {
        if (col.parts.length <= listParts.length) return;
        for (var i = 0; i < listParts.length; i++) if (col.parts[i] !== listParts[i]) return;
        var rel = col.parts.slice(listParts.length);
        if (!innerMarker(rel)) out.push({col: col, rel: rel});
      });
      return out;
    }
    function relLabel(col) {
      var rest = col.key.slice(byId[vlist.id].key.length);
      return rest.charAt(0) === '.' ? rest.slice(1) : rest;
    }
    function productInserts() {
      return productColumns().map(function (col) { return {label: col.key, value: canonical(base(col.parts), 'fields')}; });
    }
    function variantInserts() {
      return variantColumns().map(function (item) { return {label: 'Variant: ' + relLabel(item.col), value: canonical(base(item.rel), 'variant')}; })
        .concat(productInserts().map(function (choice) { return {label: 'Product: ' + choice.label, value: choice.value}; }));
    }
    // A list field mapped to a scalar PrestaShop target gives its first entry.
    function productWrap(col, target) {
      return isListColumn(col.parts) && PRODUCT_TARGETS.some(function (entry) { return entry[0] === target; }) && LIST_TARGETS.indexOf(target) === -1;
    }
    function productOptions() {
      return productColumns().filter(selectable).map(function (col) {
        var id = colId(col);
        return {
          value: id, group: '', label: optionLabel(col.key, col),
          forms: function (target) { return alternatives(col.parts, 'fields', productWrap(col, target)); }
        };
      });
    }
    function variantOptions() {
      var own = variantColumns().filter(function (item) { return selectable(item.col); }).map(function (item) {
        return {
          value: 'v:' + colId(item.col), group: 'Variant fields', label: optionLabel(relLabel(item.col), item.col),
          forms: function () { return alternatives(item.rel, 'variant', isListColumn(item.rel)); }
        };
      });
      var product = productColumns().filter(selectable).map(function (col) {
        return {
          value: 'p:' + colId(col), group: 'Product fields', label: optionLabel(col.key, col),
          forms: function () { return alternatives(col.parts, 'fields', isListColumn(col.parts)); }
        };
      });
      return own.concat(product);
    }

    // ---- Insert-at-cursor helper ----
    function insertHelper(textarea, choices) {
      var wrap = document.createElement('span');
      var select = document.createElement('select');
      select.setAttribute('aria-label', 'Feed field to insert');
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-default pi-insert-button';
      button.textContent = '+ Insert feed field at cursor';
      button.disabled = true;
      var start = textarea.value.length, end = start;
      function remember() { start = textarea.selectionStart; end = textarea.selectionEnd; }
      ['select', 'keyup', 'click', 'input', 'blur'].forEach(function (name) { textarea.addEventListener(name, remember); });
      function fill() {
        var list = choices();
        select.replaceChildren(new Option(list.length ? 'Insert feed field…' : 'Load the feed to list fields', ''));
        list.forEach(function (choice) { select.add(new Option(choice.label, choice.value)); });
        button.disabled = true;
      }
      select.addEventListener('change', function () { button.disabled = !select.value; });
      button.addEventListener('click', function () {
        if (!select.value) return;
        textarea.focus();
        textarea.setSelectionRange(start, end);
        textarea.setRangeText(select.value, start, end, 'end');
        remember();
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
      });
      wrap.appendChild(select);
      wrap.appendChild(button);
      fill();
      return {element: wrap, refresh: fill};
    }

    // ---- PrestaShop-field-first mapping editor ----
    // cfg: container, heading, targets (fixed rows), options() -> feed field choices, inserts(), addLabel, placeholder, minRows
    function mapEditor(cfg) {
      var table = document.createElement('table');
      table.className = 'table table-condensed pi-map';
      var head = table.createTHead().insertRow();
      [cfg.heading, 'Feed field'].forEach(function (title) {
        var cell = document.createElement('th');
        cell.scope = 'col';
        cell.textContent = title;
        head.appendChild(cell);
      });
      var tbody = table.createTBody();
      cfg.container.appendChild(table);
      var add = null;
      var rows = [];
      var options = [];
      var optionByValue = Object.create(null);
      var editor = {};

      function fillOptions(row) {
        var groups = Object.create(null);
        row.select.replaceChildren(new Option('— not mapped —', ''));
        options.forEach(function (option) {
          var parent = row.select;
          if (option.group) {
            if (!groups[option.group]) {
              groups[option.group] = document.createElement('optgroup');
              groups[option.group].label = option.group;
              row.select.appendChild(groups[option.group]);
            }
            parent = groups[option.group];
          }
          parent.appendChild(new Option(option.label, option.value));
        });
        row.select.add(new Option('Formula…', '__formula__'));
      }
      function checkDuplicates() {
        var seen = Object.create(null);
        rows.forEach(function (row) {
          var name = row.name();
          row.warning.textContent = '';
          if (name === '') return;
          if (seen[name]) row.warning.textContent = 'Target "' + name + '" is used more than once; only one mapping can be saved.';
          seen[name] = true;
        });
      }
      function expressionOf(row) {
        var value = row.select.value;
        if (value === '') return '';
        if (value === '__formula__') return row.textarea.value;
        var option = optionByValue[value];
        return option ? option.forms(row.name())[0] : '';
      }
      function attach(row, expression) {
        var wanted = expression.trim();
        var match = null;
        if (wanted) {
          var target = row.name();
          match = options.find(function (option) { return option.forms(target).indexOf(wanted) !== -1; }) || null;
        }
        if (match) row.select.value = match.value;
        else row.select.value = wanted ? '__formula__' : '';
        row.textarea.value = match ? '' : expression;
        row.box.style.display = row.select.value === '__formula__' ? '' : 'none';
      }
      function addRow(spec) {
        var row = {custom: spec.custom, fixedName: spec.target};
        var tr = tbody.insertRow();
        var nameCell = tr.insertCell();
        if (spec.custom) {
          row.input = document.createElement('input');
          row.input.type = 'text';
          row.input.value = spec.target;
          row.input.placeholder = cfg.placeholder;
          row.input.setAttribute('aria-label', cfg.heading);
          row.input.addEventListener('input', checkDuplicates);
          nameCell.appendChild(row.input);
        } else {
          var code = document.createElement('code');
          code.textContent = spec.target;
          nameCell.appendChild(code);
          if (spec.badge) {
            nameCell.appendChild(document.createTextNode(' '));
            var badge = document.createElement('span');
            badge.className = 'label label-info';
            badge.textContent = spec.badge;
            nameCell.appendChild(badge);
          }
          var help = document.createElement('span');
          help.className = 'pi-target-help';
          help.textContent = spec.description;
          nameCell.appendChild(help);
        }
        row.warning = document.createElement('span');
        row.warning.className = 'text-danger';
        nameCell.appendChild(row.warning);
        row.name = function () { return row.custom ? row.input.value.trim() : row.fixedName; };

        var cell = tr.insertCell();
        row.select = document.createElement('select');
        row.select.setAttribute('aria-label', 'Feed field for ' + (spec.target || cfg.heading));
        fillOptions(row);
        cell.appendChild(row.select);
        if (spec.custom) {
          var remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'btn btn-default';
          remove.textContent = 'Remove';
          remove.addEventListener('click', function () {
            tr.remove();
            rows.splice(rows.indexOf(row), 1);
            checkDuplicates();
          });
          cell.appendChild(document.createTextNode(' '));
          cell.appendChild(remove);
        }
        row.box = document.createElement('div');
        row.box.className = 'pi-formula-box';
        row.textarea = document.createElement('textarea');
        row.textarea.rows = 3;
        row.textarea.setAttribute('aria-label', 'Expression for ' + (spec.target || cfg.heading));
        row.box.appendChild(row.textarea);
        row.helper = insertHelper(row.textarea, cfg.inserts);
        row.box.appendChild(row.helper.element);
        addSampler(row.box, row.textarea);
        cell.appendChild(row.box);
        row.select.addEventListener('change', function () {
          var current = row.select.value;
          row.box.style.display = current === '__formula__' ? '' : 'none';
          if (current === '__formula__') row.textarea.focus();
        });
        rows.push(row);
        attach(row, spec.expression);
        return row;
      }
      // Every non-blank mapping, in table order.
      editor.rows = function () {
        return rows.map(function (row) { return {target: row.name(), expression: expressionOf(row)}; })
          .filter(function (entry) { return entry.expression.trim() !== ''; });
      };
      // What load() needs to rebuild the table: mapped rows plus named custom rows.
      editor.state = function () {
        return rows.map(function (row) { return {target: row.name(), expression: expressionOf(row)}; })
          .filter(function (entry, index) { return entry.expression.trim() !== '' || (rows[index].custom && entry.target !== ''); });
      };
      editor.load = function (state) {
        options = cfg.options();
        optionByValue = Object.create(null);
        options.forEach(function (option) { optionByValue[option.value] = option; });
        tbody.replaceChildren();
        rows = [];
        var left = state.map(function (entry) { return {target: String(entry.target || ''), expression: String(entry.expression || '')}; });
        (cfg.targets || []).forEach(function (target) {
          var index = left.findIndex(function (entry) { return entry.target.trim() === target[0]; });
          var entry = index === -1 ? null : left.splice(index, 1)[0];
          addRow({target: target[0], badge: target[1], description: target[2], expression: entry ? entry.expression : '', custom: false});
        });
        left.forEach(function (entry) { addRow({target: entry.target, expression: entry.expression, custom: true}); });
        while (rows.length < (cfg.minRows || 0)) addRow({target: '', expression: '', custom: true});
        checkDuplicates();
      };
      if (cfg.addLabel) {
        add = document.createElement('button');
        add.type = 'button';
        add.className = 'btn btn-default';
        add.textContent = cfg.addLabel;
        add.addEventListener('click', function () { addRow({target: '', expression: '', custom: true}).input.focus(); });
        cfg.container.appendChild(add);
      }
      return editor;
    }

    var pEditor = mapEditor({
      container: document.getElementById('pi-product-map'), heading: 'PrestaShop field', targets: PRODUCT_TARGETS,
      options: productOptions, inserts: productInserts, addLabel: '+ Add custom PrestaShop field', placeholder: 'Custom field name'
    });
    var vfEditor = mapEditor({
      container: document.getElementById('pi-variant-field-map'), heading: 'Combination field', targets: VARIANT_TARGETS,
      options: variantOptions, inserts: variantInserts, placeholder: 'Combination field'
    });
    var vaEditor = mapEditor({
      container: document.getElementById('pi-variant-attribute-map'), heading: 'Attribute', targets: [],
      options: variantOptions, inserts: variantInserts, addLabel: '+ Add attribute', placeholder: 'Attribute name, e.g. Size', minRows: 1
    });

    // ---- Filter expression helper ----
    var filterField = form.querySelector('[name="filter_expression"]');
    var filterHelper = insertHelper(filterField, productInserts);
    filterField.insertAdjacentElement('afterend', filterHelper.element);

    // ---- State ----
    function variantsExpression() {
      if (vlist.mode === 'none') return '';
      return vlist.mode === 'custom' ? variantsCustom.value : vlist.expr;
    }
    function collect() {
      return {mapping: pEditor.rows(), variants: variantsExpression(), fields: vfEditor.rows(), attributes: vaEditor.rows()};
    }
    function loadEditors(state) {
      pEditor.load(state.mapping);
      vfEditor.load(state.fields);
      vaEditor.load(state.attributes);
    }
    function matchVariantsList(expression) {
      var wanted = expression.trim();
      vlist = {mode: wanted ? 'custom' : 'none', id: '', expr: expression};
      if (!wanted) return;
      variantListColumns().forEach(function (col) {
        if (vlist.mode === 'col') return;
        if (alternatives(col.parts, 'fields', false).indexOf(wanted) !== -1) vlist = {mode: 'col', id: colId(col), expr: expression};
      });
      if (vlist.mode === 'custom') variantsCustom.value = expression;
    }
    // Rows are read with the old schema, then re-attached to the new one.
    function reconcile(newSchema) {
      var state = {mapping: pEditor.state(), variants: variantsExpression(), fields: vfEditor.state(), attributes: vaEditor.state()};
      schema = newSchema;
      byId = Object.create(null);
      schema.columns.forEach(function (col) { byId[colId(col)] = col; });
      matchVariantsList(state.variants);
      loadEditors(state);
    }

    // ---- Rendering ----
    function renderReference() {
      referenceBody.replaceChildren();
      var text = searchInput.value.trim().toLowerCase();
      var shown = 0;
      schema.columns.forEach(function (col) {
        if (text && col.key.toLowerCase().indexOf(text) === -1) return;
        shown++;
        var tr = referenceBody.insertRow();
        var key = tr.insertCell();
        var code = document.createElement('code');
        code.textContent = col.key;
        key.appendChild(code);
        var presence = tr.insertCell();
        presence.textContent = col.present + '/' + col.total + ' (' + percent(col.present, col.total) + '%)';
        if (col.present < col.total) {
          tr.className = 'pi-partial';
          var flag = document.createElement('span');
          flag.className = 'label label-warning';
          flag.textContent = 'partial';
          presence.appendChild(document.createTextNode(' '));
          presence.appendChild(flag);
        }
        tr.insertCell().textContent = typeLabel(col);
        var samples = document.createElement('span');
        samples.className = 'pi-samples';
        samples.textContent = col.samples.join(' | ');
        tr.insertCell().appendChild(samples);
      });
      emptyMessage.style.display = shown ? 'none' : '';
      emptyMessage.textContent = schema.columns.length ? 'No feed fields match the search.' : 'No feed fields yet. The feed loads once a URL or file path is set.';
    }
    function renderVariantsList() {
      variantsList.replaceChildren(new Option('— no variants —', ''));
      variantListColumns().forEach(function (col) { variantsList.add(new Option(optionLabel(col.key, col), colId(col))); });
      variantsList.add(new Option('Custom expression…', '__custom__'));
      variantsList.value = vlist.mode === 'none' ? '' : (vlist.mode === 'col' ? vlist.id : '__custom__');
      variantsCustom.style.display = vlist.mode === 'custom' ? '' : 'none';
      variantMappings.style.display = vlist.mode === 'none' ? 'none' : '';
    }
    function renderIdentifier() {
      var choices = schema.columns.filter(function (col) { return col.parts.length === 1 && col.kind === 'scalar'; });
      choices = choices.filter(function (col) { return col.present === col.total; })
        .concat(choices.filter(function (col) { return col.present !== col.total; }));
      identifierPicker.replaceChildren(new Option('— choose —', ''));
      choices.forEach(function (col) {
        identifierPicker.add(new Option(col.key + ' (' + percent(col.present, col.total) + '% of products)', String(col.parts[0])));
      });
      identifierPicker.add(new Option('Other key…', '__other__'));
      var selected = identifierTouched ? identifierChoice : identifier.value;
      identifierPicker.value = selected === '' ? '' :
        Array.prototype.some.call(identifierPicker.options, function (option) { return option.value === selected; }) ? selected : '__other__';
      identifier.style.display = identifierPicker.value === '__other__' ? '' : 'none';
    }
    identifierPicker.addEventListener('change', function () {
      identifierTouched = true;
      identifierChoice = this.value;
      if (this.value !== '__other__') identifier.value = this.value;
      identifier.style.display = this.value === '__other__' ? '' : 'none';
      if (this.value === '__other__') identifier.focus();
    });
    identifier.addEventListener('input', function () { identifierTouched = true; identifierChoice = '__other__'; });
    function renderAll() {
      renderReference();
      renderVariantsList();
      renderIdentifier();
      filterHelper.refresh();
    }

    variantsList.addEventListener('change', function () {
      var state = {mapping: pEditor.state(), fields: vfEditor.state(), attributes: vaEditor.state()};
      var previous = variantsExpression();
      if (variantsList.value === '') vlist = {mode: 'none', id: '', expr: ''};
      else if (variantsList.value === '__custom__') {
        if (vlist.mode !== 'custom') variantsCustom.value = previous;
        vlist = {mode: 'custom', id: '', expr: variantsCustom.value};
      } else vlist = {mode: 'col', id: variantsList.value, expr: canonical(base(byId[variantsList.value].parts), 'fields')};
      vfEditor.load(state.fields);
      vaEditor.load(state.attributes);
      renderVariantsList();
      if (vlist.mode === 'custom') variantsCustom.focus();
    });
    searchInput.addEventListener('input', renderReference);
    searchInput.addEventListener('keydown', function (event) { if (event.key === 'Enter') event.preventDefault(); });

    // ---- Feed loading ----
    function applySchema(data) {
      reconcile(data.schema);
      renderAll();
      statusLine.textContent = data.item_count + ' products, ' + schema.columns.length + ' fields, ' +
        (data.cache.cached ? 'cached ' + age(data.cache.age) : 'fetched just now') +
        (schema.truncated ? ' (field list capped at depth 6 / 1000 fields)' : '');
    }
    function loadFeed(refresh) {
      var button = document.getElementById('pi-refresh-feed');
      var seq = ++loadSeq;
      button.disabled = true;
      statusLine.textContent = refresh ? 'Fetching feed…' : 'Loading feed…';
      post(feedBody('inspect', refresh)).then(function (data) {
        if (seq !== loadSeq) return;
        if (data.error) statusLine.textContent = 'Could not load feed: ' + data.error;
        else applySchema(data);
      })
        .catch(function (error) { if (seq === loadSeq) statusLine.textContent = 'Could not load feed: ' + error.message; })
        .then(function () { if (seq === loadSeq) button.disabled = false; });
    }
    document.getElementById('pi-refresh-feed').addEventListener('click', function () {
      if (hasLocation()) loadFeed(true);
      else statusLine.textContent = 'Enter a feed URL or file path to load the feed.';
    });

    // ---- Submission: reproduce the original POST fields exactly ----
    function mappingBody(body) {
      var state = collect();
      state.mapping.forEach(function (row) { body.append('field_mapping_target[]', row.target); body.append('field_mapping_expression[]', row.expression); });
      body.set('variants_expression', state.variants);
      state.fields.forEach(function (row) { body.append('variant_field_target[]', row.target); body.append('variant_field_expression[]', row.expression); });
      state.attributes.forEach(function (row) { body.append('variant_attribute_target[]', row.target); body.append('variant_attribute_expression[]', row.expression); });
      return body;
    }
    form.addEventListener('submit', function () {
      var generated = document.getElementById('pi-generated');
      generated.replaceChildren();
      mappingBody(new URLSearchParams()).forEach(function (value, name) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        generated.appendChild(input);
      });
    });

    // ---- Test source (General tab) ----
    var test = panel.querySelector('.pi-test-source');
    function discovery(container, title, data) { section(container, title, data); }
    test.addEventListener('click', function () {
      var output = panel.querySelector('.pi-source-results');
      test.disabled = true;
      output.textContent = 'Testing…';
      post(feedBody('testSource', false)).then(function (data) {
        output.textContent = '';
        if (data.error) {
          output.textContent = data.error;
          return;
        }
        var categories = data.categories || {};
        var brands = data.brands || {};
        var count = function (value, singular, plural) { return value + ' ' + (value === 1 ? singular : plural); };
        var result = function (sectionData, key, singular, plural) {
          if (sectionData.error) return sectionData.error;
          if (sectionData.skipped) return sectionData.skipped;
          return count(sectionData[key], singular, plural);
        };
        var status = document.createElement('p');
        status.textContent = 'OK - ' + [
          count(data.item_count, 'item', 'items'),
          count(data.schema.columns.length, 'field', 'fields'),
          (categories.skipped || categories.error ? 'categories: ' : '') + result(categories, 'unique_paths', 'category path', 'category paths'),
          (brands.skipped || brands.error ? 'brands: ' : '') + result(brands, 'unique_brands', 'brand', 'brands')
        ].join(', ');
        output.appendChild(status);
        var details = document.createElement('details');
        var summary = document.createElement('summary');
        summary.textContent = 'Details';
        details.appendChild(summary);
        section(details, 'Source status', {connectivity: data.connectivity, item_count: data.item_count});
        discovery(details, 'Category discovery', categories);
        discovery(details, 'Brand discovery', brands);
        output.appendChild(details);
        applySchema(data);
      })
        .catch(function (error) { output.textContent = 'Test failed: ' + error.message; })
        .then(function () { test.disabled = false; document.dispatchEvent(new Event('pi-test-source-complete')); });
    });

    // ---- Preview table ----
    var previewButton = form.querySelector('.pi-preview-table-button');
    previewButton.addEventListener('click', function () {
      var output = form.querySelector('.pi-preview-table-results');
      previewButton.disabled = true;
      output.textContent = 'Building preview…';
      var body = mappingBody(feedBody('previewTable', false));
      body.set('filter_expression', form.querySelector('[name="filter_expression"]').value);
      post(body).then(function (data) {
        output.replaceChildren();
        if (data.error) {
          output.textContent = data.error;
          return;
        }
        var summary = document.createElement('p');
        summary.textContent = 'Showing ' + data.rows.length + ' of ' + data.item_count + ' products (' + (data.cache.cached ? 'cached ' + age(data.cache.age) : 'fetched just now') + ').';
        output.appendChild(summary);
        var wrap = document.createElement('div');
        wrap.className = 'pi-preview-scroll';
        var table = document.createElement('table');
        table.className = 'table table-condensed table-bordered';
        var head = table.createTHead().insertRow();
        ['#', 'Filter'].concat(data.targets, ['Variants', 'Notes']).forEach(function (title) {
          var cell = document.createElement('th');
          cell.scope = 'col';
          cell.textContent = title;
          head.appendChild(cell);
        });
        var body = table.createTBody();
        data.rows.forEach(function (row) {
          var tr = body.insertRow();
          if (row.included === false) tr.className = 'pi-excluded';
          tr.insertCell().textContent = row.index;
          tr.insertCell().textContent = row.included === null ? 'error' : (row.included ? 'imported' : 'excluded');
          data.targets.forEach(function (target) {
            var cell = tr.insertCell();
            var entry = (row.cells || {})[target];
            if (!entry) return;
            if (entry.error) {
              cell.className = 'pi-cell-error';
              cell.title = entry.error;
              cell.textContent = 'Error: ' + entry.error;
            } else if (entry.value === null) {
              cell.className = 'text-muted';
              cell.textContent = '—';
            } else cell.textContent = entry.value;
          });
          var variantCell = tr.insertCell();
          variantCell.textContent = row.variants.total + (row.variants.errors ? ' (' + row.variants.errors + ' errors)' : '');
          if (row.variants.errors) variantCell.className = 'pi-cell-error';
          var noteCell = tr.insertCell();
          noteCell.textContent = Object.keys(row.notes || {}).map(function (key) { return key + ': ' + row.notes[key]; }).join('; ');
          if (noteCell.textContent) noteCell.className = 'pi-cell-error';
        });
        wrap.appendChild(table);
        output.appendChild(wrap);
      })
        .catch(function (error) { output.textContent = 'Preview failed: ' + error.message; })
        .then(function () { previewButton.disabled = false; });
    });

    // ---- Initial state: saved rows start as formulas and attach to feed fields once the feed is loaded ----
    var savedVariants = saved.variants_expression || '';
    vlist = {mode: savedVariants ? 'custom' : 'none', id: '', expr: savedVariants};
    variantsCustom.value = savedVariants;
    variantsCustom.addEventListener('input', function () { vlist.expr = variantsCustom.value; });
    loadEditors({mapping: saved.mapping || [], fields: saved.fields || [], attributes: saved.attributes || []});
    renderAll();
    if (hasLocation()) loadFeed(false);
    else statusLine.textContent = 'Enter a feed URL or file path to load the feed.';
    var reloadTimer = null;
    ['json_url', 'json_file_path', 'source_format', 'xml_item_path'].forEach(function (name) {
      var field = form.querySelector('[name="' + name + '"]');
      ['input', 'change'].forEach(function (eventName) {
        field.addEventListener(eventName, function () {
          clearTimeout(reloadTimer);
          reloadTimer = setTimeout(function () {
            if (hasLocation()) loadFeed(false);
            else statusLine.textContent = 'Enter a feed URL or file path to load the feed.';
          }, 700);
        });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
</script>
{/literal}
