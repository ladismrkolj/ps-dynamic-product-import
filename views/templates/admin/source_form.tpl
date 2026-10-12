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
    <li role="presentation"><a id="pi-features-link" href="#pi-features" role="tab" aria-controls="pi-features" aria-selected="false">Features</a></li>
  </ul>
  <div class="tab-content">
    <div id="pi-general" class="tab-pane active" role="tabpanel" aria-labelledby="pi-general-link"><div class="pi-pane-head"><span class="pi-muted">Basic settings of this source: where the feed is, how items are identified and what is imported.</span><details class="pi-howto"><summary>How this works</summary><div>The source location, format and identifier decide how the feed is read and how items are linked to products. The filter limits which items are imported. Product fields, Categories, Brands and Features are configured on their own tabs. Mapping-tab changes are saved with the Save button.</div></details></div></div>
    <div id="pi-products" class="tab-pane" role="tabpanel" aria-labelledby="pi-products-link">
<div id="pi-grid-root" data-state="{$pi_state_json|escape:'html':'UTF-8'}" data-carriers="{$pi_carriers_json|escape:'html':'UTF-8'}">
  <div class="pi-grid-toolbar">
    <button type="button" id="pi-refresh-feed" class="btn btn-default">Refresh feed</button>
    <span id="pi-feed-status" role="status" aria-live="polite">Feed not loaded.</span>
  </div>
  <div class="pi-pane-head"><span class="pi-muted">The feed loads automatically for a saved source and records its categories and brands.</span><details class="pi-howto"><summary>How this works</summary><div>The feed loads automatically when a source is set and whenever the feed settings change. It uses a copy fetched in the last 15 minutes; Refresh feed fetches it again. For a saved source the load also records the categories and brands found. Imports always fetch the live feed.</div></details></div>
  <details id="pi-formula-help">
    <summary id="pi-formula-help-toggle" class="btn btn-default"><i class="material-icons pi-ref-caret" aria-hidden="true">expand_more</i> <span id="pi-formula-help-label">Show formula syntax</span></summary>
    <table class="table table-condensed" aria-label="Formula syntax">
      <thead><tr><th scope="col">Symbol or function</th><th scope="col">Meaning and example</th></tr></thead>
      <tbody>
        <tr><td><code>fields['x']</code></td><td>Feed field <code>x</code> of the product. A missing field is <code>null</code>, not an error. <code>fields.x</code> does not work; always use brackets.</td></tr>
        <tr><td><code>variant['x']</code></td><td>Field <code>x</code> of one variants entry (variant formulas only).</td></tr>
        <tr><td><code>path(fields, 'a.b')</code></td><td>Walks nested keys, for example <code>path(fields, 'prices.retail')</code> or <code>path(fields, 'images.0')</code>. A missing key gives <code>null</code>.</td></tr>
        <tr><td><code>~</code></td><td>Joins text: <code>fields['brand'] ~ ' ' ~ fields['model']</code></td></tr>
        <tr><td><code>+ - * / % **</code></td><td>Math, <code>**</code> is power: <code>num(fields['price']) * 1.22</code></td></tr>
        <tr><td><code>== != &lt; &gt; &lt;= &gt;=</code></td><td>Comparison: <code>num(fields['stock']) &gt; 0</code></td></tr>
        <tr><td><code>and or not</code></td><td>Logic (<code>&amp;&amp; || !</code> also work): <code>fields['active'] == 'yes' and not fields['hidden']</code></td></tr>
        <tr><td><code>a ? b : c</code></td><td>If <code>a</code> is true then <code>b</code>, else <code>c</code>: <code>num(fields['stock']) &gt; 0 ? 1 : 0</code></td></tr>
        <tr><td><code>a ?: b</code></td><td>Uses <code>b</code> when <code>a</code> is empty: null, <code>''</code>, <code>0</code>, <code>false</code> or an empty list. <code>fields['sale'] ?: fields['price']</code></td></tr>
        <tr><td><code>a ?? b</code></td><td>Uses <code>b</code> only when <code>a</code> is null or missing; keeps <code>''</code> and <code>0</code>. <code>fields['stock'] ?? 0</code></td></tr>
        <tr><td><code>matches</code></td><td>Regular expression test: <code>fields['name'] matches '/^Kite/i'</code></td></tr>
        <tr><td><code>starts with</code>, <code>ends with</code>, <code>contains</code></td><td>Plain text tests: <code>fields['sku'] starts with 'A-'</code>, <code>fields['name'] contains 'Pro'</code></td></tr>
        <tr><td><code>in</code>, <code>not in</code>, <code>..</code></td><td>List and range membership (<code>..</code> is a list of whole numbers and <code>in</code> compares strictly, so it fits whole-number feed values, not text or decimals): <code>fields['size'] in ['S', 'M']</code>, <code>fields['qty'] in 1..5</code>, <code>fields['brand'] not in ['Nike']</code></td></tr>
        <tr><td><code>[ ]</code>, <code>{ldelim}a: 1{rdelim}</code></td><td>List and map literals: <code>[fields['main'], path(fields, 'extra')]</code>, <code>{ldelim}'S': 1, 'M': 2{rdelim}[fields['size']]</code></td></tr>
        <tr><td><code>num(x)</code></td><td>Number, or <code>null</code> if not numeric: <code>num(fields['price'])</code></td></tr>
        <tr><td><code>str(x)</code></td><td>Text: <code>str(fields['id'])</code></td></tr>
        <tr><td><code>regex('/p/', x)</code></td><td>True if the pattern matches: <code>regex('/^A\\d+$/', fields['sku'])</code></td></tr>
        <tr><td><code>first(list)</code></td><td>First entry of a list: <code>first(fields['images'])</code></td></tr>
        <tr><td><code>lower(s)</code>, <code>upper(s)</code></td><td>Change case, accents included: <code>lower('ČAJ')</code> gives <code>čaj</code></td></tr>
        <tr><td><code>trim(s)</code></td><td>Removes spaces at both ends: <code>trim(fields['name'])</code></td></tr>
        <tr><td><code>replace(s, search, with)</code></td><td>Replaces plain text, not a pattern: <code>replace(fields['name'], '-', ' ')</code></td></tr>
        <tr><td><code>round(n, decimals)</code></td><td>Rounds half up, decimals default 0: <code>round(num(fields['price']) * 1.22, 2)</code></td></tr>
        <tr><td><code>join(list, sep)</code></td><td>Joins a list, skipping empty entries; separator defaults to <code>', '</code>: <code>join([fields['a'], fields['b']], ' / ')</code></td></tr>
        <tr><td><code>pairs(list, key, value)</code></td><td>Turns a list of objects into a name → value map, for the <code>features</code> field: <code>pairs(path(fields, 'attributes.item'), '@attributes.name', '@value')</code>. <code>key</code> and <code>value</code> are dot paths like in <code>path()</code>; entries with an empty key are skipped.</td></tr>
        <tr><td><code>common(list)</code></td><td>Merges a list of objects into one name → value map, keeping only names whose value is the same in every object where the name appears (per-size columns are dropped): <code>common(fields['specification_table'])</code></td></tr>
        <tr><td><code>pick(map, names)</code></td><td>Keeps the listed keys of a map in that order; <code>names</code> is a list, or a <code>{ldelim}feed name: new name{rdelim}</code> map that renames (first present synonym wins): <code>pick(common(fields['specification_table']), {ldelim}'Discipline': 'Discipline', 'Finbox': 'Fin box', 'Fin box type': 'Fin box'{rdelim})</code></td></tr>
        <tr><td><code>row(list, keys, value)</code></td><td>The first object of a list in which one of the keys equals <code>value</code>, or <code>null</code>. <code>keys</code> is a key or a list of keys. Numbers compare as numbers (<code>'12.0'</code> equals <code>'12'</code>), other values as trimmed text ignoring case. For a table with one row per size: <code>row(fields['specification_table'], 'Size', path(fields, 'variants.0.size'))</code></td></tr>
        <tr><td><code>row(list, value)</code></td><td>Automatic form: the one row whose key column (a column every row has, with all-different values) equals <code>value</code>; none or several give <code>null</code>. Constant columns are ignored: <code>row(fields['specification_table'], variant['size'])</code></td></tr>
        <tr><td><code>merge(map, map, ...)</code></td><td>Combines maps; a later map overrides the keys of an earlier one, <code>null</code> arguments are skipped: <code>merge(common(fields['specification_table']), row(fields['specification_table'], 'Size', path(fields, 'variants.0.size')))</code></td></tr>
        <tr><td><code>table(list)</code></td><td>If no name repeats across the objects it is a Name / Value table, else a column table. Shows a list of objects (or one object) as an HTML table: a header with all keys, one row per object, empty cells where a key is missing, everything escaped. A missing or empty list gives <code>null</code>, and <code>~</code> treats <code>null</code> as empty text, so this is safe for a description: <code>fields['description'] ~ table(fields['specification_table'])</code></td></tr>
        <tr><td><code>count(list)</code></td><td>Number of entries, 0 for a missing list: <code>count(fields['images'])</code></td></tr>
        <tr><td>Quoting</td><td>Write text in single quotes. Put a backslash before a quote inside the text: <code>'it\'s'</code>. Doubling the quote (<code>'it''s'</code>) does not work. A backslash itself is written <code>\\</code>.</td></tr>
      </tbody>
    </table>
    <p class="help-block">The text helpers return <code>null</code> for <code>null</code>, so a missing field leaves the PrestaShop field empty. <code>round</code> needs a number: wrap text in <code>num()</code>.</p>
  </details>
  <h3 id="pi-product-heading">PrestaShop product fields</h3>
  <div class="pi-pane-head"><span class="pi-muted">Choose the feed field that fills each PrestaShop field, or write a formula.</span><details class="pi-howto"><summary>How this works</summary><div>Each row is a PrestaShop field. Choose the feed field that fills it, or choose Formula to combine several feed fields, for example <code>fields['brand'] ~ ' ' ~ fields['model']</code>. Feed fields are listed with the number of products that have them; where a product lacks a field, the PrestaShop field stays empty. A list feed field (such as <code>images[*]</code>) mapped to a single-value field uses its first entry. category_paths accepts a single category name or a breadcrumb list; images, related_products and carriers expect lists; features expects a name → value map, whose names the Features tab maps to shop features.</div></details></div>
  <div id="pi-identifier-info" class="pi-identifier-info pi-setting-row" role="group" aria-labelledby="pi-identifier-info-label" aria-disabled="true">
    <strong id="pi-identifier-info-label">Identifier (matches products)</strong>
    <span class="help-block" style="display:inline;margin-left:8px">Internal link between this source's feed items and PrestaShop products. It is not written to the product (it shows only as the supplier reference when the source has a supplier). Use the reference field for a visible product code.</span>
    <div style="margin-top:4px"><span id="pi-identifier-info-value" aria-readonly="true" aria-labelledby="pi-identifier-info-label"><code>— not set —</code></span>
    <a href="#pi-general" id="pi-identifier-info-change" class="btn btn-default btn-sm" style="margin-left:8px">Change on General tab</a></div>
    <p id="pi-identifier-info-warning" class="text-muted" style="display:none;margin:4px 0 0"></p>
  </div>
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
    <summary id="pi-reference-toggle" class="btn btn-default"><i class="material-icons pi-ref-caret" aria-hidden="true">expand_more</i> <span id="pi-reference-toggle-label">Show feed fields</span></summary>
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
    <div id="pi-features" class="tab-pane" role="tabpanel" aria-labelledby="pi-features-link"></div>
  </div>
</div>
{include file='./source_tabs_script.tpl'}
<input type="hidden" id="pi-staged" name="pi_staged" value="{$pi_staged_json|escape:'html':'UTF-8'}">
{include file='./source_staging_script.tpl'}
{include file='./source_mappings_script.tpl'}
{include file='./source_features_script.tpl'}
{include file='./source_editor_script.tpl'}
{/if}
