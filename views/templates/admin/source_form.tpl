{if isset($pi_help_section) && $pi_help_section == 'filter'}
Optional filter. Examples to adapt to your JSON (not defaults):<br>
Only selected categories: <code>path(fields, 'breadcrumbs.0.title') in ['Windsurf', 'Sails', 'Boards']</code><br>
Only below a price threshold: <code>num(fields['price']) &lt; 100</code><br>
Exclude one brand: <code>fields['brand'] != 'Nike'</code>
{else}
<div id="pi-source-tabs">
  <ul class="nav nav-tabs" role="tablist">
    <li class="active" role="presentation"><a id="pi-general-link" href="#pi-general" role="tab" aria-controls="pi-general" aria-selected="true" data-toggle="tab">General</a></li>
    <li role="presentation"><a id="pi-products-link" href="#pi-products" role="tab" aria-controls="pi-products" aria-selected="false" data-toggle="tab">Product fields</a></li>
    <li role="presentation"><a id="pi-categories-link" href="#pi-categories" role="tab" aria-controls="pi-categories" aria-selected="false">Categories</a></li>
    <li role="presentation"><a id="pi-brands-link" href="#pi-brands" role="tab" aria-controls="pi-brands" aria-selected="false">Brands</a></li>
  </ul>
  <div class="tab-content">
    <div id="pi-general" class="tab-pane active" role="tabpanel" aria-labelledby="pi-general-link"></div>
    <div id="pi-products" class="tab-pane" role="tabpanel" aria-labelledby="pi-products-link">
<div id="pi-grid-root" data-state="{$pi_state_json|escape:'html':'UTF-8'}">
  <div class="pi-grid-toolbar">
    <button type="button" id="pi-refresh-feed" class="btn btn-default">Refresh feed</button>
    <span id="pi-feed-status" role="status" aria-live="polite">Feed not loaded.</span>
  </div>
  <p class="help-block">The feed loads automatically when a URL or file path is set and whenever the feed settings change. It uses a copy fetched in the last 15 minutes; Refresh feed fetches it again. Imports always fetch the live feed.</p>
  <h3 id="pi-product-heading">PrestaShop product fields</h3>
  <p class="help-block">Each row is a PrestaShop field. Choose the feed field that fills it, or choose Formula to combine several feed fields, for example <code>fields['brand'] ~ ' ' ~ fields['model']</code>. Feed fields are listed with the number of products that have them; where a product lacks a field, the PrestaShop field stays empty. A list feed field (such as <code>images[*]</code>) mapped to a single-value field uses its first entry. category_paths accepts a single category name or a breadcrumb list; images expects a list of URLs.</p>
  <div id="pi-product-map"></div>
  <section id="pi-variants">
    <h3>Variants (optional)</h3>
    <p class="help-block">A variants list contains one entry per combination, such as <code>variants</code>. Map PrestaShop combination fields and named attributes such as Size to its sub-fields (or to product fields). At least one attribute and a reference field are required when variants are enabled. Variant price is absolute; active=false sets zero stock.</p>
    <label for="pi-variants-list">Variants list</label>
    <select id="pi-variants-list"></select>
    <input id="pi-variants-custom" type="text" value="" aria-label="Custom variants expression" style="display:none">
    <div id="pi-variant-mappings" style="display:none">
      <h4>PrestaShop combination fields</h4>
      <p class="help-block">Formulas use <code>variant</code> for one entry, for example <code>path(variant, 'size')</code>, and <code>fields</code> for the product.</p>
      <div id="pi-variant-field-map"></div>
      <h4>Attributes</h4>
      <div id="pi-variant-attribute-map"></div>
    </div>
  </section>
  <h3 id="pi-reference-heading">Feed fields (reference)</h3>
  <details id="pi-reference-details">
    <summary>Show every field found in the feed</summary>
    <label for="pi-feed-search" class="sr-only">Search feed fields</label>
    <input type="search" id="pi-feed-search" placeholder="Search feed fields" autocomplete="off">
    <table id="pi-reference" class="table table-condensed" aria-labelledby="pi-reference-heading">
      <thead><tr><th scope="col">Feed field</th><th scope="col">Present in</th><th scope="col">Type</th><th scope="col">Samples</th></tr></thead>
      <tbody></tbody>
    </table>
    <p id="pi-reference-empty" class="help-block"></p>
  </details>
  <div id="pi-generated" hidden></div>
</div>
    </div>
    <div id="pi-categories" class="tab-pane" role="tabpanel" aria-labelledby="pi-categories-link"><div id="pi-category-root"></div><div id="pi-category-mappings"></div></div>
    <div id="pi-brands" class="tab-pane" role="tabpanel" aria-labelledby="pi-brands-link"></div>
  </div>
</div>
{include file='./source_tabs_script.tpl'}
{include file='./source_mappings_script.tpl'}
{include file='./source_editor_script.tpl'}
{/if}
