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
        if (name === 'feature') { part.rules = part.rules || {}; } else { part.rows = part.rows || {}; part.add = part.add || []; }
        part.del = part.del || [];
        return part;
      },
      changed: refresh,
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
