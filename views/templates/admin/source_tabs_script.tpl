{literal}
<style>
/* Scoped fallback also keeps these panes usable if the Bootstrap tab plugin is absent. */
#pi-source-tabs > .tab-content > .tab-pane { display: none; }
#pi-source-tabs > .tab-content > .tab-pane.active { display: block; }
#pi-source-tabs > .tab-content { padding-top: 16px; }
/* Every editor tab uses the full width of the card: PrestaShop's horizontal form reserves a label column and indents the wrapper. */
.pi-wide-wrapper { margin-left: 0 !important; margin-right: 0 !important; max-width: none !important; width: 100% !important; padding-left: 0 !important; padding-right: 0 !important; }
#pi-source-tabs > .nav-tabs { padding-left: 0; }
.pi-wide-group { margin-left: 0 !important; margin-right: 0 !important; }
.pi-wide-tabs { width: 100% !important; margin-left: 0 !important; float: none !important; padding: 0 !important; }
#pi-source-tabs .pi-changed { border-color: #f0ad4e !important; background-color: #fff8e1 !important; }
#pi-category-root .form-group { margin-left: 0; margin-right: 0; }
#pi-category-root .form-group > div[class*="col-"] { float: none; width: 100%; margin-left: 0; }
/* General: compact labels, controls as wide as their content needs. */
#pi-general .form-group { display: flex; align-items: flex-start; margin-left: 0; margin-right: 0; }
#pi-general .form-group::before, #pi-general .form-group::after { display: none; }
#pi-general .form-group > label.control-label { flex: 0 0 190px; width: auto; max-width: 190px; float: none; text-align: right; padding-right: 14px; }
#pi-general .form-group > div[class*="col-"] { flex: 1 1 auto; width: auto; max-width: 1200px; float: none; min-width: 0; }
#pi-general textarea.form-control { height: 140px; min-height: 100px; resize: vertical; }
#pi-general .form-group select.form-control { max-width: 360px; }
#pi-general .form-group .fixed-width-sm, #pi-general .input-group.fixed-width-sm { max-width: 140px; }
/* Mapping tables never scroll sideways: fixed column shares, secondary info stacked under the feed value. */
#pi-source-tabs table.pi-map-table { table-layout: fixed; width: 100%; }
#pi-source-tabs table.pi-map-table th, #pi-source-tabs table.pi-map-table td { vertical-align: top; padding: 8px; overflow-wrap: anywhere; }
#pi-source-tabs table.pi-map-table th:nth-child(1) { width: 30%; }
#pi-source-tabs table.pi-map-table th:nth-child(2) { width: 22%; }
#pi-source-tabs table.pi-map-table th:nth-child(3) { width: 38%; }
#pi-source-tabs table.pi-map-table th:nth-child(4) { width: 10%; }
#pi-source-tabs table.pi-map-table td select, #pi-source-tabs table.pi-map-table td input.form-control { width: 100%; max-width: 100%; margin-bottom: 4px; }
#pi-source-tabs table.pi-map-table .pi-row-title { font-weight: 600; }
#pi-source-tabs table.pi-map-table .pi-row-meta { font-size: 12px; color: #6c868e; }
#pi-source-tabs table.pi-map-table .pi-row-meta.text-success { color: #3a8a3a; }
#pi-source-tabs table.pi-map-table .pi-row-meta.text-warning { color: #b5710d; }
#pi-source-tabs table.pi-map-table .pi-feature-samples { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
@media (max-width: 1100px) {
  #pi-source-tabs table.pi-map-table, #pi-source-tabs table.pi-map-table tbody, #pi-source-tabs table.pi-map-table tr, #pi-source-tabs table.pi-map-table td { display: block; width: 100%; }
  #pi-source-tabs table.pi-map-table thead { display: none; }
  #pi-source-tabs table.pi-map-table tr { border-bottom: 2px solid #ddd; padding: 6px 0; }
  #pi-source-tabs table.pi-map-table td { border: 0; padding: 3px 8px; }
  #pi-source-tabs table.pi-map-table td[data-label]:not([data-label=""]):not(:first-child)::before { content: attr(data-label) ": "; display: block; font-size: 11px; text-transform: uppercase; color: #6c868e; }
}
</style>
<script>
(function () {
  function initTabs() {
    var tabs = document.getElementById('pi-source-tabs');
    var form = tabs.closest('form');
    var group = tabs.closest('.form-group');
    var wrapper = group.parentElement;
    var general = document.getElementById('pi-general');
    var categoryRoot = document.getElementById('pi-category-root');
    var product = document.getElementById('pi-products');
    var config = document.getElementById('pi-configuration-panel');
    // Move existing nodes, never clone them: values, IDs, names and listeners survive.
    product.appendChild(config);
    var controls = ['name', 'technical_key', 'location', 'source_format', 'xml_item_path', 'identifier_field', 'filter_expression', 'id_lang_default', 'id_supplier', 'active', 'deactivate_missing', 'delete_missing_days'];
    var selected = new Set();
    function selectGroup(element) {
      if (!element) return;
      while (element.parentElement && element.parentElement !== wrapper) element = element.parentElement;
      if (element.parentElement === wrapper && element !== group && element.classList.contains('form-group')) selected.add(element);
    }
    controls.forEach(function (name) { selectGroup(form.querySelector('[name="' + name + '"]')); });
    var format = form.querySelector('[name="source_format"]');
    var xmlPath = form.querySelector('[name="xml_item_path"]');
    var xmlGroup = xmlPath.closest('.form-group');
    function updateXmlPath() { xmlGroup.style.display = format.value === 'xml' ? '' : 'none'; }
    format.addEventListener('change', updateXmlPath);
    updateXmlPath();
    var deleteDays = form.querySelector('[name="delete_missing_days"]');
    var deleteDaysGroup = deleteDays ? deleteDays.closest('.form-group') : null;
    var deactivateSwitches = Array.from(form.querySelectorAll('[name="deactivate_missing"]'));
    function updateDeleteDays() {
      if (!deleteDaysGroup) return;
      var on = deactivateSwitches.some(function (input) { return input.checked && input.value === '1'; });
      deleteDaysGroup.style.display = on ? '' : 'none';
    }
    deactivateSwitches.forEach(function (input) { input.addEventListener('change', updateDeleteDays); });
    updateDeleteDays();
    ['pi-preview', 'pi-identifier-picker'].forEach(function (id) { selectGroup(document.getElementById(id)); });
    Array.from(wrapper.children).forEach(function (element) { if (selected.has(element)) general.appendChild(element); });
    var tree = document.getElementById('pi-root-category-tree');
    selectGroup(tree);
    if (tree) categoryRoot.appendChild(Array.from(wrapper.children).find(function (element) { return selected.has(element); }));
    // Every tab uses the full width of the card.
    tabs.parentElement.classList.add('pi-wide-tabs');
    group.classList.add('pi-wide-group');
    var formWrapper = tabs.closest('.form-wrapper');
    if (formWrapper) formWrapper.classList.add('pi-wide-wrapper');
    var links = Array.from(tabs.querySelectorAll('.nav-tabs a'));
    var panes = Array.from(tabs.querySelector('.tab-content').children);
    var storageKey = 'productimport-tab:' + location.pathname + ':' + document.getElementById('pi-preview').dataset.source;
    function activate(id) {
      if (!panes.some(function (pane) { return pane.id === id; })) id = 'pi-general';
      panes.forEach(function (pane) { pane.classList.toggle('active', pane.id === id); });
      links.forEach(function (link) {
        var active = link.getAttribute('href') === '#' + id;
        link.parentElement.classList.toggle('active', active);
        link.setAttribute('aria-selected', String(active));
        link.tabIndex = active ? 0 : -1;
      });
      document.dispatchEvent(new CustomEvent('pi-source-tab', {detail: id}));
      try { sessionStorage.setItem(storageKey, id); } catch (error) { /* Storage may be disabled. */ }
    }
    links.forEach(function (link, index) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        // Handle locally: no dependency on whichever Bootstrap JS the wrapper loads.
        event.stopPropagation();
        activate(link.getAttribute('href').slice(1));
      });
      link.addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        var next = links[(index + (event.key === 'ArrowRight' ? 1 : links.length - 1)) % links.length];
        next.click();
        next.focus();
      });
    });
    // Browser validation must reveal required controls even when another pane is open.
    form.addEventListener('invalid', function (event) {
      var pane = event.target.closest('.tab-pane');
      if (pane) activate(pane.id);
    }, true);
    form.addEventListener('submit', function () {
      var active = panes.find(function (pane) { return pane.classList.contains('active'); });
      try { if (active) sessionStorage.setItem('productimport-tab-pending', active.id); } catch (error) { /* Storage may be disabled. */ }
    });
    var remembered = 'pi-general';
    try {
      remembered = sessionStorage.getItem('productimport-tab-pending') || sessionStorage.getItem(storageKey) || remembered;
      sessionStorage.removeItem('productimport-tab-pending');
    } catch (error) { /* General is the fallback. */ }
    activate(remembered);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initTabs);
  else initTabs();
}());
</script>
{/literal}
