{literal}
<style>
/* Scoped fallback also keeps these panes usable if the Bootstrap tab plugin is absent. */
#pi-source-tabs > .tab-content > .tab-pane { display: none; }
#pi-source-tabs > .tab-content > .tab-pane.active { display: block; }
#pi-source-tabs > .tab-content { padding-top: 16px; }
/* The mapping tabs hold wide tables: let them use the full width of the form. */
.pi-wide-tabs { width: 100% !important; margin-left: 0 !important; }
#pi-features table { table-layout: auto; width: 100%; }
#pi-features td, #pi-features th { vertical-align: top; }
#pi-features { overflow-x: auto; }
#pi-features select.pi-feature-action { min-width: 190px; }
#pi-features input.pi-feature-formula { min-width: 170px; }
#pi-features input.pi-feature-create { min-width: 190px; }
#pi-features .pi-feature-samples { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#pi-features .pi-feature-status { min-width: 120px; max-width: 180px; font-size: 12px; }
#pi-features td { padding: 6px 8px; }
#pi-categories table select, #pi-brands table select { min-width: 220px; }
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
    var controls = ['name', 'technical_key', 'location', 'source_format', 'xml_item_path', 'identifier_field', 'filter_expression', 'id_lang_default', 'active', 'deactivate_missing', 'delete_missing_days'];
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
    var wideColumn = tabs.parentElement;
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
      wideColumn.classList.toggle('pi-wide-tabs', ['pi-categories', 'pi-brands', 'pi-features'].indexOf(id) !== -1);
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
