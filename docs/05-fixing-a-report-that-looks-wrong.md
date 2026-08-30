# Fixing a Report That Looks Wrong

The report reads from its own table, built from your orders, rather than querying every order live. That's what keeps it fast, but it also means the table can occasionally fall behind or need a rebuild.

## The report shows zeros but you have orders

Your existing orders are still being read into the report table. Open the report and a progress bar shows how far along it is, and it continues on its own even if you leave the page. Give it a moment and check back.

## The figures still look wrong once it's finished

Go to WooCommerce, then Status, then Tools, and look for the Sales by State Report entries. Rebuild from scratch empties the table and reads every order again from the beginning, which is the right move if the numbers look off rather than merely incomplete. Data check is read-only and reports row counts by status and country, useful for spotting whether something is missing before you rebuild.

![screenshot](05-tools-rebuild.jpg)

## It still doesn't add up

Check which address the report is grouping by. It uses the shipping address, falling back to billing for orders with no shipping address, such as virtual-only orders. If an order's total looks right but its state doesn't, that's usually where to look first.
