# Reading the Sales by State Report

The report is one table: every state, county, or province in your selected country as a row, with Net Sales and Gross Sales as columns, plus a summary of both across every state at the top.

## Gross sales vs net sales

Gross Sales is the order total, exactly as WooCommerce recorded it. Net Sales is the order total minus tax and shipping. Both are read from the same values WooCommerce already stores on the order, so they reconcile with WooCommerce's own reporting instead of introducing a second version of the truth.

## States with no sales still show up

A state with zero orders in the period you selected still shows a row, with $0.00 in both columns, rather than disappearing from the table. That matters more than it sounds like it should: if you're watching for a state approaching a sales tax threshold, a missing row would be easy to miss, and a $0.00 row can't be.

![screenshot](03-report-zero-states.jpg)

## Sorting and paging

Click any column header to sort by it, and use the page controls at the bottom to move through the full list. On a country with a lot of states, like the US, results are split across pages by default.
