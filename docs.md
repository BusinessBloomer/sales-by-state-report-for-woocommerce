## Documentation

### What does this plugin do?

Adds a report showing net and gross sales grouped by state, county or province, for a chosen year and a chosen set of order statuses. With WooCommerce Analytics enabled it appears under **Analytics**, alongside Revenue and Orders; with Analytics disabled it appears under the main **WooCommerce** menu instead - either way it looks and works the same. Gross Sales is the order total; Net Sales is the order total minus tax and shipping - both use the values WooCommerce already stores on the order, so they reconcile with WooCommerce's own reports. Refunds aren't modelled separately: a fully refunded order simply carries the Refunded status, so the order status filter decides whether it's counted.

### How to set it up

1. There's nothing to configure - install and activate the plugin and the report is ready to use.
2. Go to **Analytics > Sales by State** (or **WooCommerce > Sales by State** if Analytics is disabled).
3. Choose a **Country** - any country WooCommerce defines states or provinces for. Defaults to your store's base country.
4. Choose a **Year** - a rolling list starting ten years back. Defaults to the current year.
5. Choose which **Order Status(es)** should count - defaults to Completed.
6. The table updates instantly, showing Net Sales and Gross Sales for every state, with states that had no sales shown as zero rather than hidden.

### Support, downloads, and updates

**Support:** ask a question any time on the [WordPress.org support forum](https://wordpress.org/support/plugin/sales-by-state-report-for-woocommerce/) for this plugin.

**Updates:** since this plugin is hosted on WordPress.org, updates show up as a normal WordPress plugin update - no license key, no separate download step.

**Data and privacy:** the plugin creates one custom database table holding, per order, the order ID, status, dates, billing/shipping country and state, currency, and the order/tax/shipping/net totals - no names, addresses or emails. Nothing is sent anywhere; deleting the plugin removes the table and its options.

**Enjoying it?** A quick [review](https://wordpress.org/support/plugin/sales-by-state-report-for-woocommerce/reviews/#new-post) helps other store owners find it.
