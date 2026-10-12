# PrestaShop Product Import

A PrestaShop 9.x module (PHP 8.2+) that imports products from JSON or XML feeds: mapped fields, categories, brands, images, variants (combinations), filters and scheduled runs. Scrapers that produce the feeds live in [webshop-scraper](https://github.com/ladismrkolj/webshop-scraper).

Related docs: [example feeds and mappings](examples/README.md), [Docker test store](local-dev/README.md), [store testing](docs/store-testing.md), [contributor rules](AGENTS.md).

Upgrading: upload the newer zip in Modules > Module Manager (or run `bin/console prestashop:module upgrade productimport`). Tables and data migrate automatically from any older version; sources, mappings and product links are kept. Back up the database first. Each upgrade runs `ProductImport\Service\Upgrade\Upgrader`: it adds missing tables and columns from `SchemaCheck`, runs the migration steps (`Upgrade\Migrations`) not yet recorded in the `PIIMPORT_MIGRATIONS` setting, in order, then tightens columns to their final definition. A step that changes data (for example moving `json_url`/`json_file_path` into `location`, or rewriting saved mappings) runs once and stops the upgrade with its id and the database error if it fails. A fresh install records every step as applied. Columns are only dropped by a step. If an upgrade did not run, the source list and editor show an "older version" banner with an **Update module data** button that runs the same upgrade. Every release that changes the schema or data ships an `upgrade/upgrade-X.Y.Z.php` that calls `Upgrader::create()->run()`; PrestaShop runs every upgrade file between the installed and the new version. Upgrade files must `require_once __DIR__ . '/autoload.php';` first: during a Module Manager upload PrestaShop still has the old version's Composer autoloader registered, so classes added in the new version are not found without it. A ProductImport class the old version already loaded in the same request cannot be redefined; the upgrade path loads none before the upgrade files run, and the "Update module data" banner button (a fresh request) is the fallback.

## Installation

1. Download `productimport-<version>.zip` from the repository's GitHub Releases. To build it yourself, commit your changes and run `./local-dev/dev.sh package` (or `php bin/package.php` with PHP 8.3, Composer, git and the zip extension). The zip lands in `dist/`.
2. In the back office open Modules > Module Manager > Upload a module and upload the zip, then install.

GitHub's "Download ZIP" does not work: its top-level folder is `ps-dynamic-product-import-<ref>/` instead of `productimport/`, which PrestaShop rejects, and it has no `vendor/` (production Composer dependencies). The release zip has a single `productimport/` folder with `vendor/` included and dev-only files left out.

Screens: Catalog > Product Import and Product Import runs. Sources belong to the installation, not to a shop. Uninstall removes the module tables, the tabs and the cron token. Imported catalog data stays.

### Releasing

1. Bump the version in `config.xml` and in `productimport.php` (`$this->version`). They must match.
2. Commit, then tag `vX.Y.Z` (the same version) and push the tag: `git tag vX.Y.Z && git push origin vX.Y.Z`.
3. The Release workflow checks the tag against `config.xml`, builds the zip and attaches it to the GitHub Release. It can also be started manually from the Actions tab.

## Quick start

1. **Catalog > Product Import > + Add source.** Fill in **Name**, a unique lowercase **Technical key** (`a-z`, `0-9`, `_`, `-`), a **Source** and the **Format** (JSON or XML). A Source starting with `http://` or `https://` is downloaded; anything else is a file path. File paths must stay inside the import folder (configuration `PIIMPORT_FEED_DIR`, default `var/productimport/feeds` under the shop root, editable in the **Import folder** panel on the source list); relative paths resolve inside it, absolute paths and symlinks must resolve inside it, and other schemes (`file://`, `php://`, `ftp://`) are rejected. The file need not exist when you save. XML needs an **XML item path** (see "XML feeds").
2. The Product fields tab **loads the feed automatically** as soon as the source is set, even before the first save. It scans every product and lists each feed field with coverage and samples. It reloads when the source, format or item path changes. **Refresh feed** bypasses the 15-minute cache. For a saved source the load also records the categories and brands found, using the saved `category_paths` and `manufacturer` expressions; the status line shows the counts and how many are new.
3. Pick the **Identifier field**, a key that is unique per product within the source, so re-imports update products instead of duplicating them. The picker lists top-level fields with coverage and uniqueness (`product_id · 80/80 · unique ✓`, `name · 80/80 · 3 duplicates`, `sku · 78/80 · missing in 2`), best first. A new source gets the best candidate pre-selected and marked *suggested*. A warning appears if the chosen field is not unique (duplicates overwrite each other) or missing on some products (those are skipped); saving is never blocked.
   The Product fields tab shows the chosen identifier as a read-only, greyed row (with the same warning) and a **Change on General tab** link; it submits nothing.
4. For each PrestaShop field, pick the **Feed field** that fills it, or **Formula…** to write an expression.
5. Check the **Preview** table. It maps the first 20 products with the form's current (even unsaved) mapping, without writing anything, and shows per-cell errors, filter results and variant counts. Products with variants have a "▸ N variants" button that expands sub-rows (up to 5 variants) with attributes, reference, EAN-13, price and its impact versus the base price, quantity, active and image.
6. Save, then **Run import now**. Or run one or all sources from the source list, or schedule them (see "Running imports").

Feeds must be a top-level array of items, with no redirect following. Only HTTP(S) URLs are fetched, and local paths must be readable regular files. Real imports always fetch the live feed. Preview, Sample values and discovery use a decoded copy cached for 15 minutes in `<cache dir>/productimport/`.

## Field mapping

Every field has one Feed field select, or a formula. Selecting a feed field generates its canonical expression: `fields['name']` for a top-level key, `path(fields, 'a.b')` for a nested path, bracket form for keys containing dots. A list mapped to a single-value field becomes `first(fields['images'])`. `images`, `category_paths`, `related_products`, `features` and `carriers` receive the whole list. A saved expression that matches a feed field's canonical form reopens with that field selected, and anything else reopens as Formula. **Insert feed field at cursor** and **Sample values** help with formulas. **+ Add custom PrestaShop field** adds extra targets. The target list is not an enforced allowlist.

| Target | Meaning |
|---|---|
| `name` | Required for new products. |
| `reference` | Visible PrestaShop product code. Skipped (not written, logged) when another product already uses it, including an earlier item of the same feed run. Products only; combination references are not checked. The editor's **Use pattern** helper builds `'PREFIX-' ~ fields['id']`. |
| `ean13` | Exactly 13 digits with a valid GS1 check digit. Anything else (EAN-8, 12-digit UPC, lost leading zeros, scientific notation) is not written and logged as `invalid EAN-13`; empty is not a warning. The preview flags it. |
| `mpn` | Manufacturer part number (`Product::$mpn`, max 40 characters). Also a combination field. |
| `price` | Tax-excluded `Product::price`, stored as given. Tax-inclusive prices are not converted. |
| `regular_price` | If both are mapped and `regular_price` > `price`, the base price becomes `regular_price` and an always-on, all-customer specific price (a fixed reduction of `regular_price` − `price`) brings it down to `price`. A regular price alone becomes the base price. Otherwise the discount slot is removed. Variant impacts are computed against the sale price while the discount exists, so variants show their own mapped price (see Variants). Base products only. A hand-made always-on discount may be overwritten. |
| `wholesale_price` | Cost price. |
| `short_description` | HTML stripped, then cut to `PS_PRODUCT_SHORT_DESC_LIMIT` (800 if unset). |
| `description` | HTML kept. |
| `weight` | Null or missing becomes 0 for new products. |
| `quantity` | Stock. Combinations use their own quantity. |
| `active` | Null or missing means true. |
| `manufacturer` | Brand name, matched exactly in `manufacturer.name`, auto-created if missing. |
| `category_paths` | A single name, a single path (strings or `{title: …}` dicts) or a list of paths, for example `[path(fields, 'breadcrumbs')]`. Hierarchy matters only for auto-created categories. |
| `images` | List of URLs. Null and empty entries are dropped. |
| `main_image` | Tried first. If absent, the first image is used. It can stand alone. |
| `related_products` | List of strings (or one string). Each entry is matched first as a feed identifier of the same source, then as a PrestaShop product reference (any product). Resolved in a second pass at the end of each run, after every product exists, so a related product created in the same run works. Replaces the product's related products (accessories) unless they were edited by hand. Unmatched entries are logged as `item N field related_products: "X" not found`. A product never relates to itself. The preview notes entries that are not found yet; they may still be created by the import. |
| `features` | Map `{feed feature name: value}` or a list of `{name, value}`: the raw feed data. The **Features** tab maps each feed name to a shop feature, ignores it or creates it (see [Features](#features)); names without a mapping follow the source setting `unmapped_features`. Predefined feature values are shared across products and matched to existing values ignoring case and repeated or edge whitespace, in the source language. Replaces the product's features unless edited by hand (ids are compared, so renaming a feature or a value is not an edit). A variant `features` field adds more, possibly several values per feature (see [Variants](#variants)). Empty or null values are skipped, and names or values over PrestaShop's length limits are skipped with a log line. Use `pairs(list, keyPath, valuePath)` to turn a list of objects into a map. |
| `available_now`, `available_later` | Text shown when the product is in stock / out of stock (source language). Also combination fields. |
| `delivery_time` | `'none'`, `'default'` or `'custom'` (0, 1 and 2 also work); stored in `additional_delivery_times`. When `delivery_in_stock` or `delivery_out_stock` is mapped without `delivery_time`, `'custom'` is used. The editor has a quick-value select. An invalid value is flagged in the preview. |
| `delivery_in_stock`, `delivery_out_stock` | Delivery labels shown in stock / out of stock (source language). |
| `additional_shipping_cost` | Number, parsed like `price`. |
| `carriers` | List of carrier names or numeric carrier IDs (`id_reference`), or one string. Names are matched exactly against active, non-deleted carriers; unknown names are logged and skipped. An empty list means all carriers. Replaces the product's carriers unless edited by hand. The editor lists the shop's carriers next to the formula; the preview flags unknown ones. |
| `on_sale` | Boolean, the "On sale!" flag. |
| `meta_title`, `meta_description` | SEO texts (source language). Over-long text is truncated to PrestaShop's limits (255 and 512 characters) with a warning in the log; the preview flags it. |

The new fields above follow the same manual-edit protection as the other fields (see "Keeping manual edits").

The **supplier** is set on the General tab. It tags every product of the source and needs suppliers to exist (Catalog > Brands & Suppliers). Each imported product also gets a supplier association with the feed identifier as its supplier reference (see "Identity"). Clearing it leaves existing suppliers untouched. Other General settings: root category (default `PS_HOME_CATEGORY`, i.e. Home; the **Use Home** button clears the choice), source language (`id_lang_default`, default 1) and `deactivate_missing`.

Existing products keep fields you do not map. New products without categories go to `PS_HOME_CATEGORY`, and the first resolved category becomes the default category. Category and brand names are matched exactly under their parent and filled in every installed language.

### Images

Images are downloaded over HTTP(S) and created with the PrestaShop `Image` API and the configured product thumbnails. The first successful image becomes the cover only if the product has none. Existing images and covers are kept, and one failed image does not abort the product. Each image's content fingerprint is compared with the product's existing image files, so a known image creates no duplicate. A changed photo at the same URL is added and the old one is not deleted. Two near-identical photos may be treated as one.

## Identity

Five different identifiers are involved. They are easy to confuse:

- **Import identifier** (General tab): an internal per-source link, feed ID → `id_product` through `pi_external_product`. It is not written to the product. The importer never searches the catalogue by reference, EAN or name, so a product created by hand is not matched and will be duplicated by the first import.
- **Supplier reference**: that feed ID made visible per supplier. When the source has a supplier, the product gets a `product_supplier` row (combination 0) with the feed identifier as `product_supplier_reference`, and every combination its own row with the variant reference. Currency is the shop default; the product's default supplier is the source supplier. It is always written (link information, no manual-edit protection). Without a supplier nothing is created.
- **PrestaShop reference**: your visible shop code, built from the feed (for example `'PREFIX-' ~ fields['id']`) and kept unique by the import: a reference already used by a different product is not written, the product keeps its current reference (empty if new), and the run log says `item N field reference: "X" is already used by product #M — not written`. Products unchanged since the last run are not re-checked.
- **EAN-13 and brand + MPN**: global identifiers meant for matching the same product across sources. Matching is not implemented yet; the fields are only stored.
- **PrestaShop product ID**: PrestaShop's internal row number.

## Variants

On Product fields > **Variants (optional)**, pick a **Variants list** (a list of objects) or write a list expression. Then map the combination fields `reference`, `ean13`, `mpn`, `price`, `quantity`, `active`, `image`, `features` and attribute rows (name plus Feed field or Formula). Variant expressions see `fields` and `variant`. An attribute row and a `reference` field are required when variants are enabled. An empty variants expression disables the section.

- A combination's external key is `<parent id>:<variant reference>`, limited to 191 characters. Avoid colon ambiguity (`a:b` + `c` collides with `a` + `b:c`).
- `price` is the absolute variant price: the shop shows exactly that price for the variant. The module stores the impact against the price PrestaShop adds impacts to: the sale price when the product has the always-on discount written from `regular_price`/`price` (see `regular_price`), otherwise the product's saved `price` (when the base price is protected as a manual edit, the store's own price and discount are used). Impacts may be negative. Both must use the same tax basis. A missing price keeps the existing impact. The crossed-out regular price of a variant is the product's regular price plus its impact, so it can differ slightly from a per-variant regular price in the feed, which is not read.
- Attribute groups and values are global. They are reused by exact, case-sensitive name and created in every language (`select` type).
- Combinations have no active flag, so `active` false sets stock to 0. It does not hide the combination, and the shop's out-of-stock ordering policy still applies. A missing quantity keeps stock.
- The first imported combination becomes the default. Later imports keep the existing default.
- `image` sets the combination's image via `Combination::setImages`, replacing its previous associations.
- **Variant `features`.** An optional combination field (feed field or Formula; variables `variant` and `fields`) returns a `{feed name: value}` map or a list of `{name, value}`, like the product-level `features` field. Examples: `{'Area (m²)': variant['size']}` or `row(fields['specification_table'], variant['size'])` (or name the column: `row(fields['specification_table'], ['Area (m²)'], variant['size'])`). The product's features are its own `features` (if mapped) plus these: for every feed name the **distinct values across all variants**, in variant order, the product-level value first (values equal ignoring case and repeated or edge spaces count once; the first spelling is kept). Names go through the same Features tab mapping (ignore, existing feature, create, value formula applied per value), and a feature may end up with several values on the product (for example `Area: 7, 9, 12`; PrestaShop stores one row per value and shows them all on the product page and in filters). The `features` field is not written to the combination. Discovery scans the variants too, so their names appear in the tab, with product counts. The preview shows the combined result in the features column and each variant's own map in its sub-row. Without the field nothing changes.
- A variant with a missing reference, or with attribute errors, is rejected and counted as failed. The product itself still counts as updated.

## Expressions

Expressions use Symfony ExpressionLanguage. Product mappings see `fields`, the whole item. Variant mappings also see `variant`.

Formula syntax (the same table is in the **Formula syntax** toggle at the top of the Product fields tab):

| Symbol or function | Meaning and example |
|---|---|
| `fields['x']` | Feed field `x` of the product. A missing field is `null`, not an error. `fields.x` does not work; always use brackets. |
| `variant['x']` | Field `x` of one variants entry (variant formulas only). |
| `path(fields, 'a.b')` | Walks nested keys, for example `path(fields, 'prices.retail')` or `path(fields, 'images.0')`. A missing key gives `null`. |
| `~` | Joins text: `fields['brand'] ~ ' ' ~ fields['model']` |
| `+ - * / % **` | Math, `**` is power: `num(fields['price']) * 1.22` |
| `== != < > <= >=` | Comparison: `num(fields['stock']) > 0` |
| `and or not` | Logic (`&&` `\|\|` `!` also work): `fields['active'] == 'yes' and not fields['hidden']` |
| `a ? b : c` | If `a` is true then `b`, else `c`: `num(fields['stock']) > 0 ? 1 : 0` |
| `a ?: b` | Uses `b` when `a` is empty: null, `''`, `0`, `false` or an empty list. `fields['sale'] ?: fields['price']` |
| `a ?? b` | Uses `b` only when `a` is null or missing; keeps `''` and `0`. `fields['stock'] ?? 0` |
| `matches` | Regular expression test: `fields['name'] matches '/^Kite/i'` |
| `starts with`, `ends with`, `contains` | Plain text tests: `fields['sku'] starts with 'A-'`, `fields['name'] contains 'Pro'` |
| `in`, `not in`, `..` | List and range membership (`..` is a list of whole numbers and `in` compares strictly, so it fits whole-number feed values, not text or decimals): `fields['size'] in ['S', 'M']`, `fields['qty'] in 1..5`, `fields['brand'] not in ['Nike']` |
| `[ ]`, `{a: 1}` | List and map literals: `[fields['main'], path(fields, 'extra')]`, `{'S': 1, 'M': 2}[fields['size']]` |
| `num(x)` | Number, or `null` if not numeric: `num(fields['price'])`. Accepts PHP numeric values and strings, not localized currency strings. |
| `str(x)` | Text: `str(fields['id'])`. Accepts scalars, stringable objects and `null`. |
| `regex('/p/', x)` | True if the pattern matches (false for `null`): `regex('/^A\\d+$/', fields['sku'])`. A malformed pattern is an error. |
| `first(list)` | First entry of a list: `first(fields['images'])` |
| `lower(s)`, `upper(s)` | Change case, accents included: `lower('ČAJ')` gives `čaj` |
| `trim(s)` | Removes spaces at both ends: `trim(fields['name'])` |
| `replace(s, search, with)` | Replaces plain text, not a pattern: `replace(fields['name'], '-', ' ')` |
| `round(n, decimals)` | Rounds half up, decimals default 0: `round(num(fields['price']) * 1.22, 2)` |
| `join(list, sep)` | Joins a list, skipping empty entries; separator defaults to `', '`: `join([fields['a'], fields['b']], ' / ')` |
| `pairs(list, keyPath, valuePath)` | Turns a list of objects into a `{key: value}` map; both paths are dot paths like `path()`. A single object counts as a one-entry list, `null` gives `null`, and entries with an empty key are skipped: `pairs(path(fields, 'dodatneLastnosti.lastnost'), '@attributes.naziv', '@value')` |
| `common(list)` | Merges a list of objects into one `{name: value}` map, keeping a name only when every occurrence has the same value (compared as trimmed text). Names whose values differ between objects (per-size or size-chart columns) are dropped. Null, empty and non-scalar values and non-object entries are skipped; `null` gives `{}`, a single object counts as a one-entry list, and first-seen order is kept: `common([{'Area': '7', 'Discipline': 'Freeride'}, {'Area': '8', 'Discipline': 'Freeride'}])` gives `{'Discipline': 'Freeride'}` |
| `pick(map, names)` | Keeps only the listed keys of a map, in the order of `names`. `names` is a list of keys, or a `{feed name: new name}` map that also renames. When several feed names map to the same new name the first one present wins. Missing keys, null and empty values are skipped, and a null map gives `{}`: `pick(fields['attributes'], {'Finbox': 'Fin box', 'Fin box type': 'Fin box'})`, `pick(map, ['Discipline', 'Struts'])` |
| `row(list, keys, value)` | The first object of a list in which one of `keys` equals `value`; `keys` is a key or a list of keys. When both sides are numeric they compare as numbers (`'12.0'` equals `'12'`), otherwise as trimmed, case-insensitive text. A single object counts as a one-entry list. Gives `null` when nothing matches or `value` or `list` is `null`: `row(fields['specification_table'], 'Size', path(fields, 'variants.0.size'))` |
| `row(list, value)` | Automatic form: finds the one row of a table that belongs to `value` without naming a column. Key columns are the columns every row has whose values (numbers by value, `'5,5'` = `'5.5'`, text trimmed and case-insensitive) are all different across rows; constant columns such as `Struts` are ignored. Exactly one matching row is returned, none or several give `null`. A one-row table is returned when any of its values equals `value`. A leading BOM is removed from keys: `row(fields['specification_table'], variant['size'])` |
| `merge(map, map, ...)` | Combines maps: later maps override the keys of earlier ones. `null` arguments are skipped: `merge(common(fields['specification_table']), row(fields['specification_table'], 'Size', 'M'))` |
| `table(list)` | Shows a list of objects (or one object) as an HTML `<table>`: a header with all keys in first-seen order, one row per object, empty cells for missing keys, keys and values HTML-escaped. Empty and non-scalar values are skipped; a missing or empty list gives `null`. `~` turns `null` into empty text, so appending it to a description is safe: `fields['description'] ~ table(fields['specification_table'])`. The markup has no attributes or styles, so PrestaShop's description validation accepts it. |
| `count(list)` | Number of entries, 0 for a missing list: `count(fields['images'])` |
| Quoting | Write text in single quotes. Put a backslash before a quote inside the text: `'it\'s'`. Doubling the quote (`'it''s'`) does not work. A backslash itself is written `\\`. |

The text helpers return `null` for `null`, so a missing field leaves the PrestaShop field empty. `round` needs a number: wrap text in `num()`. `join` of a missing list is `null`; a list with a non-scalar entry is an error, as is `count` of a non-list.

Plain `fields['x']` also evaluates to `null` when the top-level key is missing. A failing field is set to `null` and reported as an error, and the other fields are kept. A malformed expression is checked when it is evaluated, not when you save, so work in progress can be saved. A broken filter raises the same error.

### Filter

The optional **Filter expression** on the General tab decides which items are imported. A blank filter imports everything. Skipped items are counted as `skipped`.

- `path(fields, 'breadcrumbs.0.title') in ['Windsurf', 'Sails', 'Boards']`
- `num(fields['price']) < 100`
- `fields['brand'] != 'Nike'`

### XML feeds

Choose **Format: XML**. The **XML item path** is the slash-separated path from inside the root element to each repeating product element, for example `products/product` for `<catalog><products><product id="A1">…` (the root name is never part of the path). With the path empty the editor scans the feed and offers a **Detected** list such as `izdelki/izdelek · 1240 items (izdelekID, izdelekIme, …)`; the best candidate is filled in and marked *(detected)*, and picking another one reloads the fields. You can still type a path yourself, and a path is required to save. Detection streams the file without loading it whole, matches elements by local name (namespaces and prefixes are ignored) and stops counting after 200,000 elements (the count then shows a `+`). A candidate is an element that occurs at least twice at the same path and has child elements; if nothing repeats, the root's child elements are offered with count 1. Candidates are ranked by count, then by shallower path, except that a list nested inside a repeating element and repeating no more per parent than that element occurs (`images/image` inside every product) is listed after it. Results are cached for 15 minutes with the feed. Items are converted to the same structure as JSON items, with flattened keys such as `["@attributes"].id`, `name`, `categories.category[0]`. `@attributes` and `@value` are synthetic keys the module adds during conversion. For example, `<dobava id="1">Na zalogi</dobava>` becomes `{"@attributes": {"id": "1"}, "@value": "Na zalogi"}`.

### Cheat sheet

- **Missing keys.** Use `path(fields, 'x')` for keys that some items omit, such as `dodatnaSlika1..N`. Use `fields['x']` only for keys that are always present.
- **Dots are for objects, brackets for arrays.** Write `fields['dobava']['@attributes']['id']`, never `fields['dobava']['@attributes'].id`.
- **Tiers.** Use nested ternaries: `fields['x'] matches '/Only 1/' ? 1 : (fields['x'] matches '/More than 5/' ? 10 : 5)`. `matches` is a real PCRE operator.
- **Several optional fields as one list.** Write `[fields['main'], path(fields, 'extra1'), path(fields, 'extra2')]`. Null entries are dropped from `images`.
- **Booleans.** Write `true` and `false` in lowercase.
- **Discount pairs.** Apply the identical markup to `price` and `regular_price` (for example `* 1.5` on both), or the discount comparison breaks.
- **Features from name/value objects.** `pairs(path(fields, 'dodatneLastnosti.lastnost'), '@attributes.naziv', '@value')` maps `[{'@attributes': {'naziv': 'Barva'}, '@value': 'rdeča'}, …]` to `{'Barva': 'rdeča'}`.
- **Features from a specification table.** `pick(common(fields['specification_table']), {'Discipline': 'Discipline', 'Volume (l)': 'Volume (l)', 'Finbox': 'Fin box', 'Fin box type': 'Fin box', 'Weight (kg)': 'Weight (kg)'})`. `common()` merges the table's rows into one map and drops every name whose value differs between rows (per-size columns such as `Size` or `Head (cm)`), so only product-wide values remain. `pick()` then keeps the listed names and renames them to the feature names; synonyms listed for one feature use the first one present. A table that only has per-size columns gives no features. With the Features tab you can instead return all names and map or rename them there.
- **Features from a table with one row per size (a kite).** `merge(common(fields['specification_table']), row(fields['specification_table'], ['Area (m²)', 'Size', 'Volume (l)', 'Length (ft)'], path(fields, 'variants.0.size')))`: the product-wide values plus the columns of the row for this product's size.
- **Delivery labels from days.** `delivery_in_stock`: `fields['stock_availability_time_days'] !== null ? 'Dobava v ' ~ fields['stock_availability_time_days'] ~ ' dneh' : null` (a `null` leaves the label empty). Add `delivery_time` = `'custom'`, or leave it unmapped.
- **Carriers as a list.** `['DPD', 'Pošta Slovenije']`, or one string for a single carrier. `[]` means all carriers. Names must match the shop's carriers exactly; the editor's carrier select inserts them for you.
- **on_sale from a regular price.** `fields['regular_price'] !== null` flags every item that carries a regular price; for a real discount use `num(fields['regular_price']) > num(fields['price'])`.
- **Meta title.** `fields['name'] ~ ' | ' ~ fields['brand']`. A missing brand leaves a trailing ` | `, so use `join([fields['name'], fields['brand']], ' | ')` when the brand is optional (`join` skips empty entries).

#### Recharge (XML)

Format XML, item path `izdelki/izdelek` (offered as the first detected path), identifier `izdelekID`.

| Field | Expression |
|---|---|
| `name` | `fields['izdelekIme']` |
| `price` | `fields['PPC']` |
| `wholesale_price` | `fields['nabavnaCena']` |
| `description` / `short_description` | `fields['opis']` |
| `category_paths` | `fields['kategorija']['@value']` |
| `manufacturer` | `fields['blagovnaZnamka']['@value']` |
| `ean13` | `fields['EAN']` |
| `active` | `fields['dobava']['@attributes']['id'] == '1'` |
| `quantity` | `fields['dobava']['@attributes']['id'] == '1' ? 5 : 0` (the feed has no real counts) |
| `images` | `[fields['slikaVelika'], path(fields, 'dodatnaSlika1'), …, path(fields, 'dodatnaSlika10')]` |
| `features` | `pairs(path(fields, 'dodatneLastnosti.lastnost'), '@attributes.naziv', '@value')` |

#### JSON (easy-surfshop-style)

Identifier `product_id`.

| Field | Expression |
|---|---|
| `price` | `fields['price']` (add `* 1.5` for a markup) |
| `regular_price` | `fields['regular_price']` (same markup as `price`) |
| `category_paths` | `fields['category']` or `fields['breadcrumbs']` |
| `manufacturer` | `fields['brand']` |
| `quantity` from text tier | `fields['stock_info'] matches '/Only 1/' ? 1 : (fields['stock_info'] matches '/More than 5/' ? 10 : 5)` |
| Variants list | `fields['variants']` |
| Variant attribute with fallback | `variant['size'] !== null ? variant['size'] : variant['name']` |
| Variant `quantity` from two pools | `num(variant['quantity_external']) + num(variant['quantity_internal'])` |

## Categories and brands

The editor's **Categories** and **Brands** tabs list every category path and brand name found. Discovery (**Discover categories from full JSON** and the brand equivalent) scans the whole feed with only that expression, and ignores the filter. It creates nothing and only records the values. Each entry can be mapped to an existing PrestaShop category or brand. Each tab also has a **Default for anything not mapped below**. A blank brand name or an empty category path gets no default. Mappings and defaults save immediately, independently of the main Save button. Source saves leave them untouched.

### How a value is resolved

A category path or brand name is resolved in this order:

1. its mapping (the existing category or brand chosen for it, or stored by an earlier import), as long as that target still exists;
2. the source default. It applies only to values that are not mapped;
3. otherwise a category path is walked by exact name under the source's root category, creating the missing categories; a brand is looked up by exact name and created when it is missing.

**The result of step 3 is connected on the first import.** A committed import writes the category or brand it found or created into that row as its mapping. From then on the row is an ordinary mapping: the tab shows `existing category #12 (Sport > Boards)` or `existing manufacturer #4 (Acme)`, you can change it in the dropdown, and you can **move or rename** the category or brand in the back office and the next import keeps using it, with no duplicate. Values resolved by a source default are not connected, because the default decides them. The preview shows what an import would do and never writes mappings.

To make a row resolve again, set its dropdown to the empty choice (the mapping is cleared). The next import resolves it by name or default and connects it again. Use this when a feed value should go to a different, name-matching category or brand.

If a mapped category or brand has been deleted in the shop, the next import clears that mapping, resolves the value again as in step 2 or 3 (which connects the recreated or matching target) and writes one line per value to the run log. The preview shows the source default if one is set, otherwise what the name walk or lookup would pick (or auto-create when nothing matches), and changes nothing.

The root category defaults to **Home** (`PS_HOME_CATEGORY`), so new categories appear in the storefront menu. The technical Root category next to Home is used only if you select it. Changing the root does not move existing categories.

Connected rows are exported to YAML like any other mapping, by category path or brand name.

## Features

The `features` field formula still returns the raw feed data: a `{feed name: value}` map or a list of `{name, value}`. The **Features** tab then decides, per feed feature name, what becomes of it. The mapping lives in `pi_feature_mapping` (one row per source and feed name, compared exactly and case-sensitively) and each row has an action:

- **Not mapped (source setting)**: the source setting **Feed names without a mapping** (`unmapped_features`) decides. `ignore` (the default for new sources) does not import the feature. `create` makes a feature named like the feed name, which is the behaviour before 0.20.0. Upgrading sets existing sources whose field mapping has a `features` row to `create`, and all others to `ignore`.
- **Ignore**: never imported.
- **Existing feature**: the product gets that shop feature. If the feature is deleted in the shop, the next import clears the mapping with a log line and the name falls back to the source setting.
- **Create as…**: the product gets a feature with the name you enter (an existing feature of that exact name in the source language is used, otherwise one is created). A committed import then **connects** the row: it becomes **Existing feature** with the feature's id, so you can rename the feature in the shop afterwards without breaking the mapping.

Each row also has an optional **value formula** with the variables `value` (the feed value) and `fields` (the feed item), for example `value == 'TBA' ? null : value`. A null or empty result skips that feature for the product; an error skips it and is logged for that item like a field error. The formula applies to every row whose name is imported, including names the source setting creates.

**Precedence for one product, in feed order:** the row's action decides (ignore, existing feature, create) or, without a mapping, the source setting; then the value formula runs; then the value is matched against the target feature's existing values in the source language, **ignoring case and repeated or edge whitespace**, before a new value is created (a new value keeps the first-seen spelling, so `US-BOX` and `US-Box` are one value). Several feed names, or several sources, may map to the same shop feature. If two feed names of one product end up in the same feature, the first one in feed order wins and the conflict is logged once for that item.

**Discover features from full feed** evaluates the saved `features` formula over the whole feed (ignoring the filter) and records every name with its product count and up to five sample values. Imports also record names they have not seen (the **seen in feed** date), so new names appear in the tab. Mappings, the source setting and discovery save immediately, independently of the main Save button. Because the resolved features are part of the product's feed hash, changing a mapping reimports the affected products on the next run. The manual-edit protection of the `features` field works as before, and compares feature and value **ids**: renaming a feature or correcting the spelling of a value in the shop is not a manual edit and keeps syncing, while choosing a different feature or value on the product by hand is.

The **preview** shows the final mapped features in the `features` column. A note lists the ignored and unmapped names (the count and the first five) and any formula error or duplicate-target conflict. The preview never writes rows, features or values.

A specification table with one row per size (a kite with `Area (m²)` 7 to 14) needs the row that belongs to the product's own size. The feed item's size is `variants[0].size`:

```
merge(common(fields['specification_table']), row(fields['specification_table'], ['Area (m²)', 'Size', 'Volume (l)', 'Length (ft)'], path(fields, 'variants.0.size')))
```

`common()` keeps the values that are the same in every row and `row()` adds all columns of the matching row (`Area (m²)`, `Windrange (kts)`, …). Map the result's names on the Features tab.

## Keeping manual edits

For each linked product the module stores a fingerprint of every field it last wrote, read back from the store. On the next run a mapped field is written only if the store still holds that value. A field edited in the back office is left alone. Other fields keep following the feed. Reverting the field resumes syncing.

- Products: name, description, short description, reference, EAN13, price, wholesale price, weight, active, brand, supplier and categories. `price`, `regular_price` and the discount move together, and a hand-edited base price keeps all three. Edits to the discount itself are not detected.
- Descriptions are compared by visible text, so formatting-only edits do not count.
- Combinations: reference, EAN13 and price impact.
- Quantity follows the feed whenever a product is imported. Images are attach-only.
- A stale product that was reactivated by hand is not deactivated again.

A link without fingerprints (a newly mapped field, a changed source language) is overwritten once, then protected. **Run and overwrite manual edits** (source list and editor) clears the fingerprints and hashes of the selected sources and starts an import that rewrites every mapped field. If the run cannot start, for example because the lock is busy, the next run overwrites instead.

## Unchanged products are skipped

Each linked product stores a `feed_hash`: an MD5 of the raw feed item, the source's field and variant mapping, source language, supplier, resolved category IDs and brand ID, and the module version. If the hash is unchanged and the product exists, the product and its variants are skipped and counted as `unchanged`. They still count as seen, so `deactivate_missing` leaves them alone. The hash is saved only after the product and all variants succeed, and a failure clears it. Stock is part of the item, so a changed quantity triggers a full import. A hand-edited product is not repaired by an unchanged run. Use **Run and overwrite manual edits** for that.

## Running imports

Manual and scheduled imports share one execution path and one lock.

- **Admin:** Run import on the source list (all active sources, or one, including an inactive one) and in the saved editor (saved config only). The run starts as a background CLI process with live status. If spawning is unavailable, it runs inline. A confirmation warns when a source deactivates missing products.
- **Cron URL:** copy the HTTPS URL from the source list (`index.php?fc=module&module=productimport&controller=cron&token=SECRET`). The secret is a 40-hex token generated at install, compared with `hash_equals`, so treat the URL as a secret. By default it runs all active sources synchronously. Add `&source=<id_source>` for one source, even an inactive one, so each feed can have its own schedule. Add `&background=1` to start a background import and get HTTP 202 `{"started":true}`, with a synchronous fallback. The endpoint lifts the PHP time limit, but web-server and proxy timeouts still apply. Check `runs[].status` in the JSON, because per-source failures are not transport errors.

  ```cron
  0 3 * * * /usr/bin/curl --fail --silent --show-error 'FULL_CRON_URL&source=3' >> /path/to/productimport-cron.log 2>&1
  ```

- **CLI:** `php bin/import.php all|<id_source> [cli|cron-background|admin-background]`, run as the web user. The optional second argument is the trigger recorded for the run and defaults to `cli`. `admin-manual` (the inline admin fallback) is set internally and is not accepted by `bin/import.php`. Exit codes: 3 when the lock is busy and 1 on other errors. Background logs go to the PrestaShop cache directory as `productimport-run-*.log`.

  ```cron
  0 3 * * * /usr/bin/php /path/to/modules/productimport/bin/import.php all
  ```

The module does not edit the server's crontab. A nonblocking local `flock` in the cache directory prevents overlap on one host. Hosts without a shared lock filesystem are not serialized.

### Run log

Catalog > Product Import runs shows the latest 100 runs (or one source's latest 20 with `?id_source=`), read-only, with source, time, trigger (`cron`, `cron-background`, `admin-background`, `admin-manual`, `cli`), status, counts and error log. Counts are created, updated, unchanged, skipped (filtered or tombstoned), failed (item, variant and stale-cleanup failures) and deleted (auto-deleted missing products). Status is `completed`, `completed_with_errors` or `failed` (fetch, start or configuration failure). Field mapping errors are logged but do not count as failed. Logs are capped at 65,536 bytes, with a truncation marker.

### Missing products: deactivate, redirect, delete

With `deactivate_missing` on and after a successful fetch, every linked product not seen in this run is set inactive and every unseen combination gets zero stock. That includes items now filtered out or failing, and every link after a valid empty feed. Stale combinations are not hidden. A fetch failure does no cleanup, and the empty-feed guard (a first empty feed skips cleanup) also guards deletion.

- **Redirect.** When the import hides a product as missing and its redirect is `default` or empty, it sets the redirect to `301-category` (moved permanently to the default category, so PrestaShop uses `id_category_default`). A redirect you chose yourself is never changed. A returning product is reactivated by the next import and its redirect fields are left alone, because they do not matter while it is active.
- **Missing since.** `pi_external_product.date_missing` records when a link was first found missing. It is set by the first stale cleanup that misses the item and cleared when the item is seen again.
- **Auto-delete.** The source setting **Delete after (days)** (`delete_missing_days`, default 90, shown with the deactivate setting; empty or 0 = never; also in the YAML export) deletes the product (`Product::delete()`) when all of these hold during stale cleanup: `date_missing` is more than that many days old, the product is still inactive (a product you reactivated by hand is kept), and no other live link (another source, or another item) points to the same product. The link, its combination links and its image memory are removed first and the product is deleted after that (the link is put back if the delete fails), with no tombstone, so a returning item is created fresh with a new ID and URL. Choose a period longer than the seasonal gaps in your feed. Sources that existed before 0.18.0 got the default; since `date_missing` starts empty, nothing can be deleted before that many days after the upgrade. Each deletion is logged with the product ID and external ID, and counted as `deleted` in the run list.
- **What counts as missing.** Only items that are absent from the feed or filtered out start the clock and can be deleted (filtering an item out is the way to get rid of it). An item that is in the feed and passes the filter but fails to import is *not* missing: it is still deactivated as before, but its clock never starts and it is never deleted. A run with any failure (a failing item, a variant or related-product error, or an item without an identifier value, for example after renaming the identifier field) deletes nothing at all and logs why; deactivation and the missing-since bookkeeping still run.
- **PrestaShop has no trash.** A deleted product is gone for good and can only be recovered from a database backup.

### Products deleted in the store (tombstones)

When anyone other than the module deletes a linked product, the hook `actionProductDelete` marks its links as deleted (`pi_external_product.date_deleted`, plus the last known product name) and removes its combination links and image memory. The import then skips that item: no create, no update, no error, no deactivation. The run counts it as skipped and the log says so once, summarised. Stale cleanup ignores tombstones. A link whose product is missing but has no tombstone (deleted before 0.18.0 or while the hook was absent) is turned into a tombstone the same way, by the import and by stale cleanup, instead of failing.

The source editor lists the tombstones under **Deleted products** (external ID, last known name or else the external ID, date deleted) with **Restore** per row and **Restore all** (edit permission and the admin token are required). Restore removes the link row, so the next run creates the product again from the feed. The new product gets a new ID and URL; fully restoring the old product needs a database backup.

**Combinations** deleted by hand (hook `actionObjectCombinationDeleteAfter`) only lose their combination link, with no tombstone. The next run recreates the variant while the feed still has it.

### Resilience

- **Fetch retries and size cap.** Network errors, HTTP 5xx and 429 are retried twice (waits of 2 s and 5 s). Other 4xx errors fail at once. Feeds over 200 MB are rejected.
- **Stuck runs.** When an import takes the lock, any run still `running` is marked `failed` ("Interrupted: …").
- **Background start.** An exclusive `productimport-starting.marker` in the cache directory stops two simultaneous requests from both spawning an import. The second gets HTTP 409. The import clears the marker once it holds the lock, and a marker older than 60 s is stale.
- **Image memory.** `pi_image_source` maps each image URL (SHA-1) to the image it produced, so a known URL is reused without a download. If the image was deleted, the mapping is dropped and the URL is fetched, matched by fingerprint and reattached.
- **Per-run caches.** Category, brand and attribute lookups are cached once per distinct input per run. Preview misses are never cached.
- Catalog writes and links are not transactional, so a crash can leave a partial product or a `running` log. Concurrent auto-creation is not protected, so run imports serially. Only the current shop context is supported, with no multistore orchestration. In a multistore install, removing a product from just one shop does not fire the delete hook (PrestaShop fires `actionProductDelete` only when the product disappears completely), so no tombstone is recorded for it.

## Config export and import

The source list has an **Export** row action that downloads `<technical_key>.productimport.yaml`, and an **Import source configuration** panel (file upload or pasted text). The file is YAML with `format: productimport-source` and `version: 1`. It holds the full configuration: settings, source language as ISO code, `field_mapping` (`field: expression`, multi-line as `|` blocks), `variant_mapping`, and category and brand mappings. It uses names instead of IDs: root and default categories as `Home > Sport` paths below the shop root, and default brand and supplier by name. It never contains the cron token, products or run history.

```yaml
source:
  location: https://example.com/feed.json   # URL, or a file path inside the import folder
  unmapped_features: ignore                 # feed feature names without an entry below: ignore | create
category_mappings:
  'Windsurf > Sails': 'Home > Boards'   # feed category path: PrestaShop path
  'Demo Fins': ~                        # ~ or null = not mapped (default, else find or create, then connect)
brand_mappings:
  'Demo Watersports': 'Graphic Corner'
feature_mappings:
  'Fin box': {create: 'Fin box'}
  'Discipline': {feature: 'Discipline'}
  'Weight (kg)': {create: 'Weight (kg)', value: "value == 'TBA' ? null : value"}
  'Colour': {ignore: true}
```

Export writes every mapping key and value as a single-quoted string (a quote is doubled: `'Kid''s'`). Import also accepts unquoted values, but names that YAML would read as a number, `true`/`false` or `null` must be quoted (`'2024': 'Home'`).

Paths are joined with ` > `. A category name that itself contains ` > ` cannot be written; export and import reject it. Each feed value may appear once (a duplicate key is an error). Entries for feed categories, brands or features the feed has not shown yet are stored as pre-mapped rows ("not seen yet" in the editor) and apply once the feed contains them.

`feature_mappings` is keyed by the feed feature name (a name that differs only by edge spaces counts as a duplicate). Each entry is `{ignore: true}`, `{feature: 'Shop feature name'}` (an existing feature, matched exactly in the source language) or `{create: 'Name'}`, optionally with `value: 'formula'`; `{}` is a row without a mapping. At most one of `ignore`, `feature` and `create` is allowed. The source setting is `source.unmapped_features: ignore | create` (new sources without it get `ignore`). A feature that was already created and connected exports as `{feature: 'Name'}`.

- **Create new source** (default) never touches existing sources. A taken key becomes `<key>_2`, `_3`, … and ` (imported)` is appended to the name.
- **Overwrite existing source** (needs edit permission) applies the file in one transaction. Settings in the file replace the source's settings and missing ones stay. `technical_key` never changes. A present `field_mapping` or `variant_mapping` replaces the whole mapping. Each listed category, brand or feature entry is added or updated, and entries not listed stay. `~` clears that category or brand entry (not mapped: default, else find or create, then connect); a feature entry replaces the row's action and value formula as written. The merged source is validated like the form. Changed mappings make the next run reimport affected products.

Names are matched in this store: categories by exact, case-sensitive path from the shop root in the source's language (each segment must match exactly one category), features by exact name in the source language, brands and suppliers by exact name, and the language by ISO code. Unmatched settings are left unset (new source) or unchanged (overwrite). A mapping entry whose destination category, brand or feature does not exist is skipped with a warning: an existing row stays as it is and no row is created. Import never creates categories, brands or features. Files over 1 MB, unknown format or version, and invalid values are rejected.

## Removing duplicate images

`php bin/dedupe-images.php all|<id_source>` lists duplicate images on products linked by this module (dry run). Add `--apply` to delete them, keeping the lowest image ID per fingerprint group. PrestaShop handles files, thumbnails and covers. Combination image associations on removed duplicates are restored on the next import.

## Shipping

The module has no shipping fields beyond `weight`. Use PrestaShop's own features.

1. **Estimated weight by category.** Map `weight` to an expression, then price by weight range under Shipping > Carriers:

   ```
   fields['category'] == 'Boards' ? 8 : (
   fields['category'] == 'Sails' ? 3 : (
   fields['category'] == 'Harness Lines' ? 0.3 : 1
   ))
   ```

2. **Oversized items.** Create a "contact us" carrier (Shipping > Carriers > Add new carrier). On each affected product, open Shipping > Available carriers and allow only that carrier. This is a per-product PrestaShop setting that the mapping cannot set.

## Development and testing

The target is PrestaShop 9.x. The module requires PHP 8.2+ and production runs PHP 8.3. PHPUnit 13 needs PHP 8.4. The Docker setup does not need host PHP:

```sh
./local-dev/dev.sh test       # PHPUnit in Docker
./local-dev/dev.sh up         # PrestaShop 9.1.5 test store on http://localhost:8080/
./local-dev/dev.sh install    # install the module into it
```

With PHP 8.4 and Composer on the host, run `composer install`, `./vendor/bin/phpunit` and `./vendor/bin/php-cs-fixer fix --dry-run --diff`. `vendor/` stays local. The store setup and the live checks are described in [local-dev/README.md](local-dev/README.md) and [docs/store-testing.md](docs/store-testing.md). PrestaShop rewrites the installed module's `config.xml`, so don't commit that regenerated copy.

### Verification boundaries

The unit tests cover parsing and mapping logic with temporary files and handwritten doubles: expression evaluation, field and variant mapping, filters, category path normalization, feed fetching and schema, preview building, import runner orchestration, config transfer and similar. They do not cover classes that need PrestaShop core and a database. These are the repositories, resolvers, `ProductImporter`, `CombinationImporter`, `ImportCatalog`, controllers and admin JS and templates, install and uninstall, SQL, stock, image APIs and HTTP serving. No fake PrestaShop bootstrap is used. A passing unit run is not store verification. Anything that changes how products reach the store needs a run on the Docker test store, never a production store.
