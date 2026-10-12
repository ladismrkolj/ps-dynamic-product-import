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
      // Button that stages the deletion of every stale row (seen before, but not in the latest scan); nothing is written until Save.
      staleButton: function (data, part, reload) {
        var box = document.createElement('span');
        var button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-default';
        button.textContent = 'Mark rows not seen in the last scan for deletion';
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
    refresh();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
</script>
{/literal}
