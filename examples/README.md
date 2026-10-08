# Synthetic JSON and XML example feeds

These feeds contain two products each with distinct IDs and references. Use
only in a disposable store. They illustrate stock, active/inactive products,
categories, a brand and a price/regular-price discount; no external images or
network access are needed. The feeds are examples of importer inputs, not
additional PrestaShop modules.

After starting the Docker store and installing productimport, create two
sources under Catalog → Product Import. Use these settings:

| Setting | JSON source | XML source |
| --- | --- | --- |
| Name | Demo JSON | Demo XML |
| Technical key | demo_json | demo_xml |
| Format | JSON | XML |
| JSON/feed URL | leave empty | leave empty |
| JSON/feed file path | `/opt/productimport-examples/products.json` | `/opt/productimport-examples/products.xml` |
| XML item path | unused | `products/product` |
| Identifier field | `product_id` | `product_id` |

Save once, then inspect Test source to confirm the discovered fields. The XML
root is `<catalog>`; item paths are relative to that root. The identifier must be a top-level scalar field; these XML products include
`product_id` for that reason. The `id` attribute also illustrates XML metadata
conversion but is not used as the identifier. In the product mapping
use these expressions (Custom expression where necessary):

| Target | JSON expression | XML expression |
| --- | --- | --- |
| name | `fields['name']` | `fields['name']` |
| reference | `fields['product_id']` | `fields['product_id']` |
| price | `num(fields['price'])` | `num(fields['price'])` |
| regular_price | `num(fields['regular_price'])` | `num(fields['regular_price'])` |
| manufacturer | `fields['brand']` | `fields['brand']` |
| category_paths | `fields['category']` | `fields['category']` |
| quantity | `num(fields['quantity'])` | `num(fields['quantity'])` |
| weight | `num(fields['weight'])` | `num(fields['weight'])` |
| active | `fields['active']` | `fields['active'] == '1'` |
| description | `fields['description']` | `fields['description']` |

Leave filters, variants and images unset. Save, run Test configuration,
discover categories and brands, and leave discovered paths/brand on
auto-create in the disposable store. Run each import.

Expected module-mapped values (before store tax/currency display rules):

| Reference | Price | Regular price | Quantity | Active |
| --- | --- | --- | --- | --- |
| DEMO-JSON-1 | 149.90 | 179.90 | 3 | yes |
| DEMO-JSON-2 | 39.90 | 39.90 | 0 | no |
| DEMO-XML-1 | 499.90 | 599.90 | 2 | yes |
| DEMO-XML-2 | 19.90 | 19.90 | 0 | no |

Verify all four in the back office: inactive products do not appear on the
storefront. Discounted products should have the regular base price with a
specific-price discount. Re-import both sources: the same four product IDs
should be updated, with no duplicate products. Check import-run status/logs.
On a host installation, use the absolute path to these files on that server
instead of `/opt/productimport-examples/`.
