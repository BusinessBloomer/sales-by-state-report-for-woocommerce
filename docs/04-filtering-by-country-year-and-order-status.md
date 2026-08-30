# Filtering by Country, Year, and Order Status

Three filters sit at the top of the report, and changing any of them updates the table right away.

## Country

Only countries WooCommerce defines states or provinces for show up in this list, since a country without them has nothing to group by. It defaults to your store's base country.

## Year

A rolling list going ten years back from the current year, with the current year selected by default. The year filter uses the date the order was paid, or the date it was created if it was never paid.

## Order status

A checkbox list of every WooCommerce order status, so you can select more than one at once. It defaults to Completed only, but you might add Processing if you want to include orders that are paid but not yet shipped.

![screenshot](04-report-filters.jpg)

A fully refunded order carries the Refunded status, so this filter also decides whether refunded orders count. A partial refund is never deducted from its order's total, however this filter is set.
