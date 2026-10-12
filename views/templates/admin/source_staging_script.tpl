{literal}
<script>
// Edits on the Categories, Brands and Features tabs are staged here and written only by the main Save button.
(function () {
  function init() {
    var tabs = document.getElementById('pi-source-tabs');
    var field = document.getElementById('pi-staged');
    if (!tabs || !field || window.PiStaging) return;
    var form = tabs.closest('form');
    var data = {};
    try { data = JSON.parse(field.value || '{}') || {}; } catch (error) { data = {}; }
    var notice = document.createElement('div');
    notice.className = 'alert alert-warning';
    notice.setAttribute('role', 'status');
    notice.style.display = 'none';
    tabs.parentElement.insertBefore(notice, tabs);
    var submitting = false;
    var listeners = [];
    function empty(value) {
      if (Array.isArray(value)) return value.length === 0;
      if (value && typeof value === 'object') return Object.keys(value).every(function (key) { return empty(value[key]); });
      return value === undefined;
    }
    function dirty() { return !empty(data); }
    function refresh() {
      field.value = dirty() ? JSON.stringify(data) : '';
      notice.style.display = dirty() ? '' : 'none';
      notice.textContent = dirty() ? 'You have unsaved changes on the Categories, Brands or Features tab. They are saved only when you press Save.' : '';
      listeners.forEach(function (listener) { listener(); });
    }
    window.PiStaging = {
      data: data,
      // The named part (category, brand, feature) with its containers created.
      part: function (name) {
        var part = data[name] = data[name] || {};
        if (name === 'feature') { part.rules = part.rules || {}; } else { part.rows = part.rows || {}; }
        part.add = part.add || [];
        part.del = part.del || [];
        return part;
      },
      changed: refresh,
      badge: function (text, kind) { var node = document.createElement('span'); node.className = 'pi-badge pi-' + (kind || 'off'); node.textContent = text; node.title = text; return node; },
      // Search box, filter select and count for a mapping table; rows carry label/key, status, mapped, action, seen, stale.
      listControls: function (rows, features, redraw) {
        var el = document.createElement('div'); el.className = 'pi-toolbar';
        var row1 = document.createElement('div'); row1.className = 'pi-tb-row'; var row2 = document.createElement('div'); row2.className = 'pi-tb-add';
        var rowLabel = document.createElement('span'); rowLabel.className = 'pi-tb-label'; rowLabel.textContent = 'Add a feed value'; row2.appendChild(rowLabel);
        el.appendChild(row1); el.appendChild(row2);
        var actions = document.createElement('div'); actions.className = 'pi-tb-actions';
        var search = document.createElement('input'); search.type = 'search'; search.className = 'form-control pi-grow'; search.placeholder = 'Search'; search.setAttribute('aria-label', 'Search the list');
        var filter = document.createElement('select'); filter.className = 'form-control'; filter.setAttribute('aria-label', 'Filter the list');
        [['all', 'All'], ['mapped', 'Mapped'], ['notmapped', 'Not mapped']].concat(features ? [['ignored', 'Ignored']] : []).concat([['notinfeed', 'Not in feed']]).forEach(function (o) { filter.add(new Option(o[1], o[0])); });
        var count = document.createElement('span'); count.className = 'pi-count'; count.setAttribute('aria-live', 'polite');
        var timer = null;
        search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(redraw, 150); });
        filter.addEventListener('change', redraw);
        row1.appendChild(search); row1.appendChild(filter); row1.appendChild(count); row1.appendChild(actions);
        function ok(row) {
          var f = filter.value;
          if (f === 'mapped' && !row.mapped) return false;
          if (f === 'notmapped' && row.mapped) return false;
          if (f === 'ignored' && row.action !== 'ignore') return false;
          if (f === 'notinfeed' && row.seen && !row.stale) return false;
          var q = search.value.trim().toLowerCase();
          return !q || ((row.label || row.key || '') + ' ' + (row.status || '')).toLowerCase().indexOf(q) !== -1;
        }
        return {
          el: el, actions: actions, addRow: row2,
          visible: function () { return rows.filter(ok); },
          count: function (shown, total) { count.textContent = shown + ' of ' + total + ' shown'; }
        };
      },
      // "All values" panel: opts.load(onlyFiltered) -> Promise of a scan; opts.results(values) -> Promise of results (features); opts.origin shows P/V.
      valuesPanel: function (opts) {
        var el = document.createElement('div'); el.className = 'pi-values';
        var bar = document.createElement('div'); bar.className = 'pi-tb-row';
        var search = document.createElement('input'); search.type = 'search'; search.className = 'form-control pi-grow'; search.placeholder = 'Search values'; search.setAttribute('aria-label', 'Search values');
        var sort = document.createElement('select'); sort.className = 'form-control'; sort.setAttribute('aria-label', 'Sort values');
        sort.add(new Option('Most frequent first', 'count')); sort.add(new Option('A to Z', 'value'));
        var lab = document.createElement('label'); lab.style.margin = '0'; lab.style.fontWeight = 'normal';
        var only = document.createElement('input'); only.type = 'checkbox'; lab.appendChild(only); lab.appendChild(document.createTextNode(' Only items passing the filter'));
        var info = document.createElement('span'); info.className = 'pi-count'; info.setAttribute('aria-live', 'polite');
        bar.appendChild(search); bar.appendChild(sort); bar.appendChild(lab); bar.appendChild(info);
        var body = document.createElement('div'); el.appendChild(bar); el.appendChild(body);
        var data = null, shown = 200, timer = null, token = 0, results = {};
        function visible() {
          var q = search.value.trim().toLowerCase();
          var list = data.values.filter(function (v) { return !q || (v.value + ' ' + v.also.map(function (a) { return a.value; }).join(' ')).toLowerCase().indexOf(q) !== -1; });
          if (sort.value === 'value') list = list.slice().sort(function (a, b) { return a.value.localeCompare(b.value); });
          return list;
        }
        function draw() {
          if (!data) return;
          var list = visible(), part = list.slice(0, shown);
          body.replaceChildren();
          var notes = [data.distinct_total + ' distinct values' + (data.distinct_total > data.values.length ? ' (first ' + data.values.length + ' by frequency)' : '') + ', ' + data.items_scanned + ' products scanned'];
          if (data.empty) notes.push(data.empty + ' empty');
          if (data.truncated) notes.push('truncated after ' + data.evaluations + ' evaluations');
          info.textContent = notes.join(' · ');
          var table = document.createElement('table'); table.className = 'table pi-map-table pi-values-table';
          var head = table.createTHead().insertRow();
          ['Value', 'Products'].concat(opts.results ? ['P / V', 'Result'] : []).forEach(function (t) { var th = document.createElement('th'); th.textContent = t; head.appendChild(th); });
          var tb = table.createTBody();
          part.forEach(function (v) {
            var tr = tb.insertRow();
            var c = tr.insertCell(); c.setAttribute('data-label', 'Value'); var strong = document.createElement('span'); strong.className = 'pi-row-title'; strong.textContent = v.value; c.appendChild(strong);
            if (v.also.length) { var also = document.createElement('div'); also.className = 'pi-row-meta'; also.textContent = 'also: ' + v.also.map(function (a) { return a.value + ' (' + a.count + ')'; }).join(' · '); also.title = also.textContent; c.appendChild(also); }
            var n = tr.insertCell(); n.setAttribute('data-label', 'Products'); n.textContent = v.count;
            if (opts.results) {
              var pv = tr.insertCell(); pv.setAttribute('data-label', 'P / V'); pv.textContent = (v.p ? 'P ' + v.p : '') + (v.p && v.v ? ' · ' : '') + (v.v ? 'V ' + v.v : '');
              var rc = tr.insertCell(); rc.setAttribute('data-label', 'Result'); var r = results[v.value];
              if (!r) rc.textContent = '…';
              else if (r.kind === 'skipped') rc.appendChild(PiStaging.badge('skipped', 'warn'));
              else if (r.kind === 'error') { rc.appendChild(PiStaging.badge('error', 'bad')); var e = document.createElement('div'); e.className = 'pi-row-meta'; e.textContent = r.text; rc.appendChild(e); }
              else { rc.appendChild(document.createTextNode(r.text)); if (r.match) rc.appendChild(PiStaging.badge('matches existing value', 'ok')); }
            }
          });
          body.appendChild(table);
          if (data.errors && data.errors.length) { var det = document.createElement('details'); var sm = document.createElement('summary'); sm.textContent = 'Errors (' + data.errors.reduce(function (a, e) { return a + e.count; }, 0) + ')'; det.appendChild(sm); data.errors.forEach(function (e) { var d = document.createElement('div'); d.textContent = e.count + ' × ' + e.message; det.appendChild(d); }); body.appendChild(det); }
          if (list.length > shown) body.appendChild(PiStaging.more(list.length, shown, function () { shown += 200; draw(); evaluate(); }));
        }
        function evaluate() {
          if (!opts.results || !data) return;
          var mine = ++token, vals = visible().slice(0, shown).map(function (v) { return v.value; });
          opts.results(vals).then(function (res) {
            if (mine !== token) return;
            vals.forEach(function (v, i) { results[v] = res[i]; });
            draw();
          }).catch(function (error) { if (mine === token) info.textContent = error.message; });
        }
        function load() {
          info.textContent = 'Scanning the whole feed…'; body.replaceChildren(); results = {}; shown = 200;
          opts.load(only.checked).then(function (d) { data = d; draw(); evaluate(); }).catch(function (error) { info.textContent = error.message; });
        }
        search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { shown = 200; draw(); evaluate(); }, 150); });
        sort.addEventListener('change', function () { draw(); evaluate(); });
        only.addEventListener('change', load);
        return {el: el, load: load, refresh: function () { clearTimeout(timer); timer = setTimeout(function () { results = {}; evaluate(); }, 400); }};
      },
      more: function (total, shown, onMore) {
        var box = document.createElement('div'); box.className = 'pi-more';
        var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-outline-secondary btn-default'; b.textContent = 'Show more (' + (total - shown) + ' left)';
        b.addEventListener('click', onMore); box.appendChild(b); return box;
      },
      // Button that stages the deletion of every stale row (seen before, but not in the latest scan); nothing is written until Save.
      staleButton: function (data, part, reload) {
        var box = document.createElement('span');
        var button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline-secondary btn-sm';
        button.textContent = 'Mark stale…'; button.title = 'Mark rows not seen in the last scan for deletion';
        var note = document.createElement('span'); note.setAttribute('aria-live', 'polite'); note.style.marginLeft = '8px';
        button.addEventListener('click', function () {
          var rows = data.rows.filter(function (row) { return row.stale && part.del.indexOf(row.key) === -1; });
          if (!data.stale_cutoff || !rows.length) { note.textContent = 'No rows to mark: none was last seen more than ' + data.stale_hours + ' hours before the newest scan' + (data.stale_cutoff ? ' (' + data.stale_cutoff + ')' : '') + '.'; return; }
          var mapped = rows.filter(function (row) { return row.mapped; }).length;
          if (!window.confirm('Mark ' + rows.length + ' row(s) for deletion? They were last seen before ' + data.stale_cutoff + ' (more than ' + data.stale_hours + ' hours before the newest scan of this list); ' + mapped + ' of them have a mapping you set. Nothing is deleted until you press Save, and each row can be restored with Undo delete. A deleted row comes back on the next import or discovery if the feed still has the value.')) return;
          rows.forEach(function (row) { part.del.push(row.key); });
          refresh(); reload();
        });
        box.appendChild(button); box.appendChild(note);
        return box;
      },
      onChange: function (listener) { listeners.push(listener); },
      dirty: dirty
    };
    form.addEventListener('submit', function () { submitting = true; refresh(); });
    window.addEventListener('beforeunload', function (event) {
      if (submitting || !dirty()) return;
      event.preventDefault();
      event.returnValue = '';
    });
    var footer = form.querySelector('.panel-footer');
    var counter = document.createElement('span'); counter.className = 'pi-bar-count';
    if (footer) { footer.insertBefore(counter, footer.firstChild); var cancel = document.createElement('a'); cancel.href = location.href; cancel.className = 'btn btn-outline-secondary btn-default'; cancel.textContent = 'Cancel'; cancel.addEventListener('click', function (e) { e.preventDefault(); submitting = true; location.reload(); }); footer.insertBefore(cancel, footer.querySelector('button[type=submit]')); }
    function tally() {
      var n = 0;
      ['category', 'brand', 'feature'].forEach(function (t) {
        var p = data[t]; if (!p) return;
        n += Object.keys(p.rows || p.rules || {}).length + (p.add || []).length + (p.del || []).length + (Object.prototype.hasOwnProperty.call(p, 'default') || Object.prototype.hasOwnProperty.call(p, 'setting') ? 1 : 0);
      });
      counter.textContent = n ? n + ' unsaved change' + (n === 1 ? '' : 's') + ' in the mapping tabs' : 'No unsaved mapping changes';
      counter.classList.toggle('pi-has', n > 0);
    }
    listeners.push(tally);
    refresh();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
</script>
{/literal}
