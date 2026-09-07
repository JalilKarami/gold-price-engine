# گلدمیت — محاسبه قیمت طلا

Automatic WooCommerce pricing for gold products, driven by the daily per-gram rate.

## Formula

```
gold   = weight × (daily 18k rate × karat / 18)
wage   = gold × wage%
profit = (gold + wage) × profit%
tax    = (wage + profit [+ accessories]) × tax%
total  = gold + wage + profit + accessories + tax   → rounded
```

Prices are written to the product rather than filtered at runtime, so price sorting,
price filters, the cart and invoices all agree with the figure the shopper saw.

Rounding is shown as its own line wherever the breakdown is displayed, so the listed
amounts always add up to the total.

## Showing the calculation

Two independent switches on the pricing tab, under **نمایش**:

- **نمایش ریز محاسبات** — the itemised table on the product page. For variable
  products it is swapped as the shopper picks a variation.
- **نمایش فرمول قیمت** — the formula underneath, written once in words and once with
  this product's own numbers, e.g. `(5 گرم × 3,500,000) + اجرت + سود + مالیات = ...`

Either can be used without the other.

## Cart, orders and invoices

At the moment an order is placed, the breakdown is **snapshotted onto the order
line** rather than recalculated later. The gold rate moves during the day, so an
invoice reprinted next week must still show what the customer actually paid.

`ریز قیمت در سفارش و فاکتور` chooses how much of it the customer sees:

- **کامل** — weight, karat, rate at purchase, gold amount, wage, profit, accessories,
  VAT, rounding and the unit price.
- **خلاصه** — weight, karat, rate at purchase, VAT and the unit price, hiding the
  wage and profit percentages.
- **نمایش داده نشود** — nothing visible.

The rows are ordinary order item meta, so they appear on the order confirmation page,
in WooCommerce e-mails, on the admin order screen, and in PDF invoice plugins without
any further integration. Whatever the visibility setting, the full breakdown is always
stored as hidden JSON in `_goldmate_breakdown` on the line item, so an old invoice can
be audited afterwards. `ریز قیمت در سبد خرید` shows the same rows in the cart and at
checkout, where they reflect the rate at that moment.

Order item meta is deliberately left in place when the plugin is deleted: it is part
of the order record, not plugin data.

## Admin

Everything lives under the top-level **قیمت طلا** menu:

- **قیمت‌گذاری** — daily rate, profit, VAT, rounding step and direction, whether
  accessories are taxed, and whether the product's WooCommerce tax status is forced
  to `none`.
- **دریافت خودکار قیمت** — the rate source, its endpoint, credentials and JSON path,
  the fetch interval, and the guards (maximum accepted deviation, maximum response
  age, staleness threshold and what to do when the rate goes stale).
- **وضعیت و ابزارها** — current state, background-job progress, a manual rebuild, a
  dry-run fetch, and the rate history.

The old WooCommerce settings tab now only links here.

## Product fields

Simple products carry weight, karat, wage percentage and accessories on the pricing
panel. Variable products use the same fields as family defaults; each variation can
override any of them, or opt out entirely. A blank variation field inherits from the
parent, which usually means only the weight needs to be set per ring size.

## Automatic rate fetching

Any JSON endpoint works: give it a URL, an optional API key (as `{KEY}` in the URL or
as a request header), a dot-separated path to the number, and a multiplier that
converts the response into Toman per gram.

### Selecting the value

A path segment may pick a list entry by one of its own fields rather than by
position:

```
gold[symbol=IR_GOLD_18K].price          BrsApi
atn.products[code=melted_gold_tomorrow].gram_sell    ATN
current.geram18.p                       plain nested keys
```

Prefer the field form wherever the provider returns a list. BrsApi puts 18-karat
gold, 24-karat gold, the ounce — quoted in **dollars** — and five coins in one
`gold` array; pinning to `gold.0` means a reordered feed can silently reprice the
whole catalogue against the wrong item. Both forms are supported, and matching is
case-insensitive text, so a JSON number matches its written form.

### Presets

All three presets were verified against live responses. `brsapi` and `atn` answer in
Toman, so their multiplier is 1; `tgju` answers in Rial with thousands separators
(`"231,091,000"`), so its multiplier is 0.1 — the separators are stripped during
sanitisation.

TGJU publishes its timestamp as Tehran local time with no UTC offset
(`"2026-09-07 18:26:30"`) and no Unix field anywhere in the feed, so it cannot drive
the age guard: leave its time path empty. A stamp that parses into the future is
rejected rather than read as fresh, so the guard fails loudly instead of silently
passing everything.

### Switching provider

There is no automatic failover. When a source misbehaves the shop picks the
replacement itself, so nobody is ever priced against a provider they did not choose.
Changing «منبع» refills the endpoint fields from that provider's preset — replacing
values left from the previous one, after a confirmation, so a hand-tuned custom
endpoint cannot vanish on a misclick. Save, then use «دریافت آزمایشی» on the status
tab to confirm the new source before it prices anything.

### Transport retries

A failed request is retried once before it is reported (`goldmate_fetch_retries`
filters the count, 0–3). Shared hosts resolve DNS slowly and intermittently — this
shop loses roughly one hourly fetch in four to
`cURL error 28: Resolving timed out` — and one extra attempt absorbs that instead of
leaving the rate stale until the next scheduled run.

Only transport errors are retried. An HTTP 401 or a malformed body is a settled
answer; repeating it would just spend the daily quota.

### Guards

Three guards protect the catalogue:

- A fetched rate that differs from the current one by more than the configured
  percentage is held for manual approval instead of being applied, so a provider
  glitch cannot reprice the whole shop.
- **Response age** — set «مسیر زمان به‌روزرسانی» to the timestamp the provider
  publishes next to the price (Unix seconds or a written date both parse) and
  «بیشینه‌ی قدمت پاسخ» to how old it may be. A feed serving a frozen number still
  answers `200` with perfectly shaped JSON, so its own timestamp is the only signal
  that anything is wrong. A path that does not resolve is treated as a failure
  rather than waved through. Leave either field empty or zero to skip the check.
- A stored rate older than the staleness threshold can raise an admin warning or
  block purchases of gold products entirely.

The first two reject the fetch and leave the previous rate in place; the third acts
on a rate that was accepted but has since aged.

## Background repricing

Changing the rate queues a background job through Action Scheduler (falling back to
WP-Cron), processing the catalogue in chunks. A large catalogue can no longer time
out mid-save and leave prices half-updated. Progress is shown on the status tab.

## Recalculation triggers

Prices are recalculated on the admin product form, on any CRUD save (REST API,
WP-CLI, programmatic updates), on variation saves, on CSV import, and whenever the
daily rate changes.

## Note on VAT

`goldmate_wc_tax_status` defaults to `none`, which keeps VAT inside the stored price.
WooCommerce's tax reports will therefore show zero VAT collected. Shops issuing
official invoices should switch it to "leave" and configure a real WooCommerce tax
rate instead. Whether accessories (stones and gems) are subject to VAT is a question
for your accountant; the plugin defaults to excluding them.

## Uninstalling

Deactivating the plugin removes nothing; it only cancels the background jobs.

Deleting the plugin always cancels those jobs, and removes settings and per-product
gold data **only** if «پاک کردن داده‌ها هنگام حذف افزونه» was switched on beforehand
(pricing tab, off by default). Weight, karat, wage and accessories are business
records, so the destructive path has to be chosen deliberately — once deleted they
cannot be recovered. On multisite the routine runs for every site in the network.

## Filters

- `goldmate_breakdown` — the finished breakdown, before pricing or display.
- `goldmate_breakdown_rows` — the rows of the customer-facing table.
- `goldmate_invoice_rows` — the rows written onto an order line.
- `goldmate_batch_chunk_size` — products processed per background step (default 40).
