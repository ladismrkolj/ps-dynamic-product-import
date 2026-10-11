# Synthetic JSON and XML example feeds

These feeds contain two products each with distinct IDs and references. Use
only in a disposable store. They illustrate stock, active/inactive products,
categories, a brand and a price/regular-price discount; no external images or
network access are needed. The feeds are examples of importer inputs, not
additional PrestaShop modules.

After starting the Docker store and installing productimport, create two
sources under Catalog → Product Import. The local Docker store mounts this
folder read-only at the default import folder
(`/var/www/html/var/productimport/feeds`), so relative locations resolve to
these files. Use these settings:

| Setting | JSON source | XML source |
| --- | --- | --- |
| Name | Demo JSON | Demo XML |
| Technical key | demo_json | demo_xml |
| Source | `products.json` | `products.xml` |
| Format | JSON | XML |
| XML item path | unused | `products/product` |

The Source is an https URL or a file path inside the import folder; relative
paths (as above) are resolved inside that folder.

Save the source. The feed loads automatically and the editor suggests an
identifier field, showing each candidate's coverage and uniqueness. Pick
`product_id` for both sources. The XML root is `<catalog>`; item paths are
relative to that root. The identifier must be a top-level scalar field; these
XML products include `product_id` for that reason. The `id` attribute also
illustrates XML metadata conversion but is not used as the identifier. In the
product mapping use these expressions (Custom expression where necessary):

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

Leave filters, variants and images unset. Save. The Categories and Brands
tabs fill automatically from the loaded feed; leave the discovered
paths/brand not mapped (found or created, then connected) in the disposable store. Run each import.

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
On a host installation, put the files in that server's import folder (or set
the folder in the Import folder panel on the source list).
