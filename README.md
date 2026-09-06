# Prodigi Direct for WooCommerce

Sell fine-art prints fulfilled by [Prodigi](https://www.prodigi.com) without mapping every variant by hand in the Prodigi dashboard.

Prodigi's own WooCommerce connector needs each product variant mapped one at a time (SKU, sizing, wrap) and offers no bulk import or API for that. This plugin takes the other road: it carries Prodigi's catalogue for fine-art prints, generates the WooCommerce variations from what the artist ticks (materials, sizes, frame colours), and sends each paid order straight to Prodigi's v4 Orders API. Tracking comes back onto the WooCommerce order. The artist never sees a SKU.

**Status: 0.1.0 — built for one shop, tested on WordPress 7.1 / WooCommerce 11.1 / PHP 8.5, with Prodigi's sandbox.** Use with care; read the install section.

## What it does

- **Product → Prints tab** (variable products only). Upload the print file once (JPEG, stored privately). *Suggest from the file* ticks the sizes the file fills with little or no border at 150 dpi or better; every size shows its fit and dpi. Tick materials, sizes and frame colours. Press *Set up print sizes*: variations are created or adopted (existing ones are matched by label, never duplicated, never deleted), each carrying the Prodigi SKU and attributes behind the scenes. The sizes table shows her price, Prodigi's cost, what she keeps, and a file-quality light per size (files under 150 dpi are hidden from the shop automatically).
- **Orders**: a *Print* column (Waiting for you / Sent / Printing / Shipped / Problem) and a *Print this order* box with the live Prodigi quote and one button, *Approve and send to Prodigi*. Automatic sending on payment is a setting. Problems are one sentence in plain words with a *Try again*.
- **Tracking**: each shipment becomes a customer note with carrier and tracking link (WooCommerce emails it); the order is marked Completed when everything has shipped.
- **WooCommerce → Prints**: status checks, a price book (one price per size × material, every painting follows), a sandbox test order, recent activity in sentences, and settings.
- **Catalogue check** every night: every SKU in use is re-verified at Prodigi and re-quoted. A retired SKU hides its variations; a cost move over 5% is reported by email and on the Prints page.
- **Sandbox first**: a persistent banner while in test mode, and real orders are never sent automatically in test mode.

## Catalogue

`catalogue/prodigi.json` holds eight families and 124 SKUs, every one verified against Prodigi's API on 2026-09-06 with a live US quote. Each family links to its Prodigi product page, shown in the admin:

| Family | SKU pattern | Attributes the order needs |
|---|---|---|
| Fine Art Paper (EMA 200gsm) | `GLOBAL-FAP-{WxH}` | none |
| Rolled Canvas | `GLOBAL-CAN-ROL-SC-{WxH}` | none |
| Gallery Wrapped Canvas | `GLOBAL-CAN-{WxH}` | `wrap` (White by default) |
| Classic Framed Print | `GLOBAL-CFP-{WxH}` | `color` |
| Classic Framed Print, matted | `GLOBAL-CFPM-{WxH}` | `color` |
| Box Framed Print | `GLOBAL-BOX-{WxH}` | `color` |
| Box Framed Print, matted | `GLOBAL-BOXM-{WxH}` | `color` |
| Float Framed Canvas | `GLOBAL-FRA-CAN-{WxH}` | `color` + `wrap` |

Sizes: 8x8 through 30x40 in the usual fine-art ladder (see the file). A few SKUs exist at Prodigi but no lab fulfils them; they are flagged `orderable: false` and never offered.

Adding a family or size is a JSON edit plus a run of `wp prodigi-direct check-catalogue`. Prodigi publishes no product-list endpoint, so the file is the source of truth; the nightly check keeps prices and availability honest for everything in use.

## Install

1. Download the release zip and install it under Plugins → Add New → Upload, or clone this repo into `wp-content/plugins/prodigi-direct`.
2. Activate. WooCommerce → Prints opens the settings.
3. **Sandbox first.** Create a sandbox account at https://sandbox-beta-dashboard.pwinty.com, paste its API key, keep *Mode: Test*. Upload a print file on one painting, set up its sizes, then *Send a test order* on the Prints page. Check it appears at https://sandbox-beta-dashboard.pwinty.com.
4. **Protect the print files.** They live in `wp-content/uploads/prodigi-private/`. The plugin writes an `.htaccess` for Apache. On nginx add, inside the site's `server` block:
   ```nginx
   location ^~ /wp-content/uploads/prodigi-private/ { deny all; return 404; }
   ```
   Or move the folder outside the web root: `define( 'PRODIGI_DIRECT_PRIVATE_DIR', '/var/www/private/prodigi' );` in `wp-config.php`. The Prints page tells you if the folder is reachable from the web.
5. **Going live.** Paste the live API key, confirm a card is registered on the Prodigi account (orders stop at Prodigi otherwise), and **disconnect Prodigi's own WooCommerce sales channel** in the Prodigi dashboard, or every order is fulfilled twice. Tick both checks on the Prints page. Switch *Mode: Live*.
6. Existing shops: `wp prodigi-direct adopt-all` reads the variations you already have (labels like `16x20" Paper`) and attaches the Prodigi data without changing prices or status. Then *Fill from the shop's current prices* on the price book.

## Labels

Variations use one custom attribute — whichever one already carries print labels (e.g. **Size / Material** or **Print Options**), created as **Size / Material** on a new product — with values like:

```
8x10" Paper · 16x20" Rolled Canvas · 16x20" Gallery Wrapped · 16x20" Classic Frame, Black · 24x30" Float Frame Canvas, Natural
```

If your shop already uses these labels the plugin adopts them; otherwise it creates them.

## Print files

Prodigi fetches the artwork from a signed link the plugin mints per order (`/wp-json/prodigi-direct/v1/master/{product}?o=…&e=…&s=…`), valid 30 days, revoked once the order has shipped. Range requests are supported (Prodigi downloads in 4 MB chunks). On nginx you can hand the transfer to nginx with `X-Accel-Redirect` by defining `PRODIGI_DIRECT_ACCEL` to an `internal` location that aliases the private folder.

Files must be JPEG (Prodigi rejects TIFF) and under 200 megapixels (larger files hang in Prodigi's pipeline). The plugin tells the uploader in those words.

## WP-CLI

```
wp prodigi-direct seed --csv=examples/seed.csv      # price book from label,price rows
wp prodigi-direct master <product_id> <path.jpg>    # attach a print file from disk
wp prodigi-direct build <product_id> --detect       # adopt existing variations
wp prodigi-direct build <product_id> --families=paper,framed-print-classic --sizes=8x10,16x20 --choices='{"framed-print-classic":["black"]}'
wp prodigi-direct adopt-all
wp prodigi-direct check-catalogue
wp prodigi-direct send <order_id> [--now]
wp prodigi-direct sync <order_id>
wp prodigi-direct lines <order_id>
```

## Development

Pure classes (catalogue, DPI maths, pricing, order payload, status mapping, signing) have PHPUnit tests that run without WordPress:

```
curl -sL -o bin/phpunit.phar https://phar.phpunit.de/phpunit-12.phar
php bin/phpunit.phar
```

The WordPress layer was exercised on a local WordPress + WooCommerce with a generated 4800×6000 test image, then on a staging copy of a real shop against Prodigi's sandbox.

Design notes: `docs/superpowers/specs/2026-09-06-prodigi-direct-design.md`.

## Not included

Mockup images, other print-on-demand providers, multi-currency, Prodigi products outside paper / canvas / framed, syncing retail prices back to Prodigi's dashboard.

## License

MIT.
