# Analytics Metric Definitions

All metrics are tenant-isolated and respect store/date/timezone filters where applicable.

Revenue is not profit. Financial margin is only reported when reliable cost snapshots exist.

## Orders over time (`orders_over_time`)

- Description: Count of orders placed per day in the selected range, filtered by tenant/store and timezone.
- Unit: count
- Source: orders
- Filters: tenant_id, store_id, date_range, timezone
- Notes: Counts orders by placed_at in workspace timezone. Excludes drafts if not placed.

## Order status distribution (`order_status_distribution`)

- Description: Distribution of orders by status in the selected range.
- Unit: count
- Source: orders
- Filters: tenant_id, store_id, date_range, timezone
- Notes: Based on orders placed in range; status is current status.

## Sales trends (`sales_trends`)

- Description: Revenue trends over time (sum of grand_total for relevant orders).
- Unit: currency
- Source: orders
- Filters: tenant_id, store_id, date_range, timezone
- Notes: Does not equal profit. Revenue recognition follows orders. Cancellations/refunds handled as per data.

## Customer repeat purchase rate (`customer_repeat_purchase_rate`)

- Description: Share of customers with more than one order in the analysis window (where measurable).
- Unit: percent
- Source: orders, customers
- Filters: tenant_id, store_id, date_range
- Notes: Only counts customers who placed >=2 orders. Requires customer linkage.

## Product performance (`product_performance`)

- Description: Units sold and revenue by product from order items.
- Unit: mixed
- Source: order_items, orders, products
- Filters: tenant_id, store_id, date_range
- Notes: Aggregated from order_items joined to orders; excludes cancelled orders where applicable.

## Stock health (`stock_health`)

- Description: Inventory levels, low stock, out of stock, slow moving where determinable.
- Unit: mixed
- Source: inventories, products, inventory_movements
- Filters: tenant_id, store_id
- Notes: Uses current inventory state and movement history; slow moving is inferred where possible.

## Conversation response metrics (`conversation_response_metrics`)

- Description: Response times and counts where timestamps are available (first response, etc.).
- Unit: mixed
- Source: conversations, conversation_messages
- Filters: tenant_id, store_id, date_range
- Notes: Only computed if timestamps exist; returns insufficient data if missing.

## Sales recovery funnel (`sales_recovery_funnel`)

- Description: Funnel of recovery opportunities (eligible/contacted/responded/recovered) with counts and recovered revenue where order linked.
- Unit: mixed
- Source: recovery_opportunities, recovery_events, orders
- Filters: tenant_id, store_id, date_range
- Notes: Recovered revenue counted only from linked non-cancelled orders.

## Message delivery metrics (`message_delivery_metrics`)

- Description: Delivery status counts for conversation messages (sent/delivered/read/failed).
- Unit: count
- Source: conversation_messages
- Filters: tenant_id, store_id, date_range
- Notes: Counts by delivery_status where present.

## Financial margin (`financial_margin`)

- Description: Gross margin where reliable cost data exists (from order item cost snapshots).
- Unit: currency/percent
- Source: orders, order_items
- Filters: tenant_id, store_id, date_range, currency, timezone
- Notes: Only reported when cost snapshots are complete/reliable; otherwise marked insufficient data. Net profit not inferred.

