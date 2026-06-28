<?php

class MeowPro_MWAI_MCP_WooCommerce {
  private $core = null;

  #region Initialize
  public function __construct( $core ) {
    $this->core = $core;
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  public function rest_api_init() {
    add_filter( 'mwai_mcp_tools', [ $this, 'register_rest_tools' ] );
    add_filter( 'mwai_mcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }
  #endregion

  #region Helpers
  private function empty_schema(): array {
    return [ 'type' => 'object', 'properties' => (object) [] ];
  }

  /**
   * Format product data for consistent output
   */
  private function format_product( $product ): array {
    if ( !$product || !is_a( $product, 'WC_Product' ) ) {
      return [];
    }

    return [
      'id' => $product->get_id(),
      'name' => $product->get_name(),
      'slug' => $product->get_slug(),
      'type' => $product->get_type(),
      'status' => $product->get_status(),
      'sku' => $product->get_sku(),
      'price' => $product->get_price(),
      'regular_price' => $product->get_regular_price(),
      'sale_price' => $product->get_sale_price(),
      'stock_status' => $product->get_stock_status(),
      'stock_quantity' => $product->get_stock_quantity(),
      'manage_stock' => $product->get_manage_stock(),
      'description' => $product->get_description(),
      'short_description' => $product->get_short_description(),
      'categories' => array_map( function ( $term ) {
        return [ 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug ];
      }, get_the_terms( $product->get_id(), 'product_cat' ) ?: [] ),
      'permalink' => $product->get_permalink(),
    ];
  }

  /**
   * Format order data for consistent output
   */
  private function format_order( $order ): array {
    if ( !$order || !is_a( $order, 'WC_Order' ) ) {
      return [];
    }

    $line_items = [];
    foreach ( $order->get_items() as $item ) {
      $line_items[] = [
        'id' => $item->get_id(),
        'name' => $item->get_name(),
        'product_id' => $item->get_product_id(),
        'quantity' => $item->get_quantity(),
        'total' => $item->get_total(),
      ];
    }

    return [
      'id' => $order->get_id(),
      'order_number' => $order->get_order_number(),
      'status' => $order->get_status(),
      'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
      'total' => $order->get_total(),
      'currency' => $order->get_currency(),
      'customer_id' => $order->get_customer_id(),
      'billing' => [
        'first_name' => $order->get_billing_first_name(),
        'last_name' => $order->get_billing_last_name(),
        'email' => $order->get_billing_email(),
        'phone' => $order->get_billing_phone(),
      ],
      'line_items' => $line_items,
      'payment_method' => $order->get_payment_method_title(),
    ];
  }

  /**
   * Format customer data for consistent output
   */
  private function format_customer( $customer ): array {
    if ( !$customer || !is_a( $customer, 'WC_Customer' ) ) {
      return [];
    }

    return [
      'id' => $customer->get_id(),
      'email' => $customer->get_email(),
      'first_name' => $customer->get_first_name(),
      'last_name' => $customer->get_last_name(),
      'username' => $customer->get_username(),
      'billing' => [
        'first_name' => $customer->get_billing_first_name(),
        'last_name' => $customer->get_billing_last_name(),
        'company' => $customer->get_billing_company(),
        'address_1' => $customer->get_billing_address_1(),
        'city' => $customer->get_billing_city(),
        'state' => $customer->get_billing_state(),
        'postcode' => $customer->get_billing_postcode(),
        'country' => $customer->get_billing_country(),
        'email' => $customer->get_billing_email(),
        'phone' => $customer->get_billing_phone(),
      ],
      'total_spent' => wc_format_decimal( $customer->get_total_spent(), 2 ),
      'order_count' => $customer->get_order_count(),
    ];
  }
  #endregion

  #region Tools
  private function tools(): array {
    return [

      /* ───────── TIER 1: Products ───────── */
      'wc_list_products' => [
        'name' => 'wc_list_products',
        'description' => 'List and search products with optional filters (status, stock_status, category, search term, limit, offset).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'status' => [
              'type' => 'string',
              'description' => 'Product status: publish, draft, pending, or any.',
            ],
            'stock_status' => [
              'type' => 'string',
              'description' => 'Stock status filter: instock, outofstock, onbackorder.',
            ],
            'category' => [
              'type' => 'string',
              'description' => 'Category slug to filter by.',
            ],
            'search' => [
              'type' => 'string',
              'description' => 'Search term for product name or description.',
            ],
            'limit' => [
              'type' => 'integer',
              'description' => 'Maximum number of products to return (default: 20).',
            ],
            'offset' => [
              'type' => 'integer',
              'description' => 'Number of products to skip (for pagination).',
            ],
          ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_get_product' => [
        'name' => 'wc_get_product',
        'description' => 'Get detailed information about a specific product by ID.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => 'Product ID.',
            ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_create_product' => [
        'name' => 'wc_create_product',
        'description' => 'Create a new product with specified details.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'name' => [
              'type' => 'string',
              'description' => 'Product name.',
            ],
            'type' => [
              'type' => 'string',
              'description' => 'Product type: simple, variable, grouped, external (default: simple).',
            ],
            'status' => [
              'type' => 'string',
              'description' => 'Product status: publish, draft, pending (default: publish).',
            ],
            'description' => [
              'type' => 'string',
              'description' => 'Product description (full).',
            ],
            'short_description' => [
              'type' => 'string',
              'description' => 'Product short description.',
            ],
            'sku' => [
              'type' => 'string',
              'description' => 'Product SKU (Stock Keeping Unit).',
            ],
            'regular_price' => [
              'type' => 'string',
              'description' => 'Regular price.',
            ],
            'sale_price' => [
              'type' => 'string',
              'description' => 'Sale price.',
            ],
            'stock_quantity' => [
              'type' => 'integer',
              'description' => 'Stock quantity.',
            ],
            'manage_stock' => [
              'type' => 'boolean',
              'description' => 'Enable stock management (default: false).',
            ],
          ],
          'required' => [ 'name' ],
        ],
        'accessLevel' => 'write',
      ],

      'wc_update_product' => [
        'name' => 'wc_update_product',
        'description' => 'Update an existing product. Only provided fields will be updated.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => 'Product ID.',
            ],
            'name' => [
              'type' => 'string',
              'description' => 'Product name.',
            ],
            'status' => [
              'type' => 'string',
              'description' => 'Product status: publish, draft, pending.',
            ],
            'description' => [
              'type' => 'string',
              'description' => 'Product description (full).',
            ],
            'short_description' => [
              'type' => 'string',
              'description' => 'Product short description.',
            ],
            'sku' => [
              'type' => 'string',
              'description' => 'Product SKU.',
            ],
            'regular_price' => [
              'type' => 'string',
              'description' => 'Regular price.',
            ],
            'sale_price' => [
              'type' => 'string',
              'description' => 'Sale price.',
            ],
            'stock_quantity' => [
              'type' => 'integer',
              'description' => 'Stock quantity.',
            ],
            'stock_status' => [
              'type' => 'string',
              'description' => 'Stock status: instock, outofstock, onbackorder.',
            ],
            'manage_stock' => [
              'type' => 'boolean',
              'description' => 'Enable stock management.',
            ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'write',
      ],

      'wc_delete_product' => [
        'name' => 'wc_delete_product',
        'description' => 'Delete a product by ID. This action is permanent.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => 'Product ID.',
            ],
            'force' => [
              'type' => 'boolean',
              'description' => 'Whether to bypass trash and permanently delete (default: false).',
            ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'admin',
      ],

      'wc_alter_product' => [
        'name' => 'wc_alter_product',
        'description' => 'Search-and-replace inside a product field without re-uploading the entire content. Efficient for making small edits to long descriptions. Supports regex patterns (PHP-PCRE with delimiters like /pattern/i).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => 'Product ID.',
            ],
            'field' => [
              'type' => 'string',
              'description' => 'Field to modify: description, short_description, or name.',
            ],
            'search' => [
              'type' => 'string',
              'description' => 'Text or regex pattern to search for.',
            ],
            'replace' => [
              'type' => 'string',
              'description' => 'Replacement text.',
            ],
            'regex' => [
              'type' => 'boolean',
              'description' => 'Treat search as regex pattern (default: false).',
            ],
          ],
          'required' => [ 'id', 'field', 'search', 'replace' ],
        ],
        'accessLevel' => 'write',
      ],

      /* ───────── TIER 1: Orders ───────── */
      'wc_list_orders' => [
        'name' => 'wc_list_orders',
        'description' => 'List and search orders with optional filters (status, customer, date range, limit, offset).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'status' => [
              'type' => 'string',
              'description' => 'Order status: pending, processing, on-hold, completed, cancelled, refunded, failed, or any.',
            ],
            'customer' => [
              'type' => 'integer',
              'description' => 'Customer ID to filter orders.',
            ],
            'date_after' => [
              'type' => 'string',
              'description' => 'Filter orders created after this date (YYYY-MM-DD format).',
            ],
            'date_before' => [
              'type' => 'string',
              'description' => 'Filter orders created before this date (YYYY-MM-DD format).',
            ],
            'limit' => [
              'type' => 'integer',
              'description' => 'Maximum number of orders to return (default: 20).',
            ],
            'offset' => [
              'type' => 'integer',
              'description' => 'Number of orders to skip (for pagination).',
            ],
          ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_get_order' => [
        'name' => 'wc_get_order',
        'description' => 'Get detailed information about a specific order by ID, including line items and customer details.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => 'Order ID.',
            ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_update_order_status' => [
        'name' => 'wc_update_order_status',
        'description' => 'Update the status of an order (e.g., mark as completed, processing, shipped).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => 'Order ID.',
            ],
            'status' => [
              'type' => 'string',
              'description' => 'New status: pending, processing, on-hold, completed, cancelled, refunded, failed.',
            ],
          ],
          'required' => [ 'id', 'status' ],
        ],
        'accessLevel' => 'write',
      ],

      'wc_add_order_note' => [
        'name' => 'wc_add_order_note',
        'description' => 'Add a note to an order. Notes can be private (internal) or customer-facing.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => 'Order ID.',
            ],
            'note' => [
              'type' => 'string',
              'description' => 'Note content.',
            ],
            'customer_note' => [
              'type' => 'boolean',
              'description' => 'Whether the note is visible to the customer (default: false).',
            ],
          ],
          'required' => [ 'id', 'note' ],
        ],
        'accessLevel' => 'write',
      ],

      'wc_create_refund' => [
        'name' => 'wc_create_refund',
        'description' => 'Create a refund for an order. Can be full or partial refund.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'order_id' => [
              'type' => 'integer',
              'description' => 'Order ID to refund.',
            ],
            'amount' => [
              'type' => 'string',
              'description' => 'Refund amount (leave empty for full refund).',
            ],
            'reason' => [
              'type' => 'string',
              'description' => 'Reason for the refund.',
            ],
          ],
          'required' => [ 'order_id' ],
        ],
        'accessLevel' => 'admin',
      ],

      'wc_get_orders_by_customer' => [
        'name' => 'wc_get_orders_by_customer',
        'description' => 'Get all orders for a specific customer by customer ID or email.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'customer_id' => [
              'type' => 'integer',
              'description' => 'Customer ID.',
            ],
            'customer_email' => [
              'type' => 'string',
              'description' => 'Customer email address.',
            ],
            'limit' => [
              'type' => 'integer',
              'description' => 'Maximum number of orders to return (default: 20).',
            ],
          ],
        ],
        'accessLevel' => 'read',
      ],

      /* ───────── TIER 1: Inventory ───────── */
      'wc_update_stock' => [
        'name' => 'wc_update_stock',
        'description' => 'Update stock quantity for a specific product.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'product_id' => [
              'type' => 'integer',
              'description' => 'Product ID.',
            ],
            'quantity' => [
              'type' => 'integer',
              'description' => 'New stock quantity.',
            ],
          ],
          'required' => [ 'product_id', 'quantity' ],
        ],
        'accessLevel' => 'write',
      ],

      /* ───────── TIER 2: Analytics ───────── */
      'wc_get_sales_report' => [
        'name' => 'wc_get_sales_report',
        'description' => 'Get sales report data for a specific date range.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'date_after' => [
              'type' => 'string',
              'description' => 'Start date (YYYY-MM-DD format). Default: 30 days ago.',
            ],
            'date_before' => [
              'type' => 'string',
              'description' => 'End date (YYYY-MM-DD format). Default: today.',
            ],
          ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_get_top_sellers' => [
        'name' => 'wc_get_top_sellers',
        'description' => 'Get best-selling products for a specific period.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'limit' => [
              'type' => 'integer',
              'description' => 'Number of top sellers to return (default: 10).',
            ],
            'date_after' => [
              'type' => 'string',
              'description' => 'Start date (YYYY-MM-DD format). Default: 30 days ago.',
            ],
            'date_before' => [
              'type' => 'string',
              'description' => 'End date (YYYY-MM-DD format). Default: today.',
            ],
          ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_get_revenue_stats' => [
        'name' => 'wc_get_revenue_stats',
        'description' => 'Get revenue statistics including total sales, net sales, average order value.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'date_after' => [
              'type' => 'string',
              'description' => 'Start date (YYYY-MM-DD format). Default: 30 days ago.',
            ],
            'date_before' => [
              'type' => 'string',
              'description' => 'End date (YYYY-MM-DD format). Default: today.',
            ],
          ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_get_low_stock_products' => [
        'name' => 'wc_get_low_stock_products',
        'description' => 'Get products with stock below a specified threshold.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'threshold' => [
              'type' => 'integer',
              'description' => 'Stock threshold (default: 5).',
            ],
          ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_get_stock_report' => [
        'name' => 'wc_get_stock_report',
        'description' => 'Get comprehensive stock report showing in-stock, out-of-stock, and low-stock products.',
        'inputSchema' => $this->empty_schema(),
        'accessLevel' => 'read',
      ],

      'wc_bulk_update_stock' => [
        'name' => 'wc_bulk_update_stock',
        'description' => 'Update stock quantities for multiple products at once. Provide an array of product updates.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'updates' => [
              'type' => 'array',
              'description' => 'Array of objects with product_id and quantity fields.',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'product_id' => [ 'type' => 'integer' ],
                  'quantity' => [ 'type' => 'integer' ],
                ],
              ],
            ],
          ],
          'required' => [ 'updates' ],
        ],
        'accessLevel' => 'write',
      ],

      /* ───────── TIER 2: Customers ───────── */
      'wc_list_customers' => [
        'name' => 'wc_list_customers',
        'description' => 'List customers with optional search and filters.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'search' => [
              'type' => 'string',
              'description' => 'Search term for customer name or email.',
            ],
            'limit' => [
              'type' => 'integer',
              'description' => 'Maximum number of customers to return (default: 20).',
            ],
            'offset' => [
              'type' => 'integer',
              'description' => 'Number of customers to skip (for pagination).',
            ],
          ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_get_customer' => [
        'name' => 'wc_get_customer',
        'description' => 'Get detailed customer information including order history and total spent.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => 'Customer ID.',
            ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_update_customer' => [
        'name' => 'wc_update_customer',
        'description' => 'Update customer information.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => 'Customer ID.',
            ],
            'email' => [
              'type' => 'string',
              'description' => 'Customer email.',
            ],
            'first_name' => [
              'type' => 'string',
              'description' => 'First name.',
            ],
            'last_name' => [
              'type' => 'string',
              'description' => 'Last name.',
            ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'write',
      ],

      /* ───────── TIER 2: Reviews ───────── */
      'wc_list_reviews' => [
        'name' => 'wc_list_reviews',
        'description' => 'List product reviews with optional filters (status, product_id).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'status' => [
              'type' => 'string',
              'description' => 'Review status: approve, hold, spam, or all (default: approve).',
            ],
            'product_id' => [
              'type' => 'integer',
              'description' => 'Filter reviews by product ID.',
            ],
            'limit' => [
              'type' => 'integer',
              'description' => 'Maximum number of reviews to return (default: 20).',
            ],
          ],
        ],
        'accessLevel' => 'read',
      ],

      'wc_approve_review' => [
        'name' => 'wc_approve_review',
        'description' => 'Approve a pending product review.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'review_id' => [
              'type' => 'integer',
              'description' => 'Review/comment ID.',
            ],
          ],
          'required' => [ 'review_id' ],
        ],
        'accessLevel' => 'write',
      ],

      'wc_delete_review' => [
        'name' => 'wc_delete_review',
        'description' => 'Delete a product review (spam or inappropriate content).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'review_id' => [
              'type' => 'integer',
              'description' => 'Review/comment ID.',
            ],
          ],
          'required' => [ 'review_id' ],
        ],
        'accessLevel' => 'admin',
      ],
    ];
  }
  #endregion

  #region Register
  public function register_rest_tools( array $prev ): array {
    $tools = $this->tools();

    // Add category and annotations to each tool
    foreach ( $tools as &$tool ) {
      if ( !isset( $tool['category'] ) ) {
        $tool['category'] = 'AI Engine (WooCommerce)';
      }

      // Add MCP tool annotations
      if ( !isset( $tool['annotations'] ) ) {
        $name = $tool['name'];

        // Read-only tools
        $is_readonly = (
          strpos( $name, '_list_' ) !== false ||
          strpos( $name, '_get_' ) !== false
        );

        // Destructive tools
        $is_destructive = (
          strpos( $name, '_delete_' ) !== false ||
          strpos( $name, '_refund' ) !== false
        );

        $tool['annotations'] = [
          'readOnlyHint' => $is_readonly,
          'destructiveHint' => !$is_readonly && $is_destructive,
          'openWorldHint' => false,
        ];
      }
    }

    $merged = array_merge( $prev, array_values( $tools ) );
    return $merged;
  }
  #endregion

  #region Callback
  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    if ( !current_user_can( 'manage_woocommerce' ) ) {
      wp_set_current_user( 1 );
    }
    return $this->dispatch( $tool, $args );
  }
  #endregion

  #region Dispatcher
  private function dispatch( string $tool, array $a ) {

    switch ( $tool ) {

      /* ───────── Products ───────── */
      case 'wc_list_products':
        $args = [
          'limit' => isset( $a['limit'] ) ? absint( $a['limit'] ) : 20,
          'offset' => isset( $a['offset'] ) ? absint( $a['offset'] ) : 0,
          'return' => 'objects',
        ];

        if ( !empty( $a['status'] ) ) {
          $args['status'] = sanitize_text_field( $a['status'] );
        }
        if ( !empty( $a['stock_status'] ) ) {
          $args['stock_status'] = sanitize_text_field( $a['stock_status'] );
        }
        if ( !empty( $a['category'] ) ) {
          $args['category'] = [ sanitize_text_field( $a['category'] ) ];
        }
        if ( !empty( $a['search'] ) ) {
          $args['s'] = sanitize_text_field( $a['search'] );
        }

        $products = wc_get_products( $args );
        $result = array_map( [ $this, 'format_product' ], $products );

        return [
          'total' => count( $result ),
          'products' => $result,
        ];

      case 'wc_get_product':
        $product_id = absint( $a['id'] ?? 0 );
        if ( !$product_id ) {
          throw new Exception( 'Product ID is required.' );
        }

        $product = wc_get_product( $product_id );
        if ( !$product ) {
          throw new Exception( 'Product not found.' );
        }

        return $this->format_product( $product );

      case 'wc_create_product':
        $name = sanitize_text_field( $a['name'] ?? '' );
        if ( empty( $name ) ) {
          throw new Exception( 'Product name is required.' );
        }

        $type = sanitize_text_field( $a['type'] ?? 'simple' );
        $product = new WC_Product_Simple();
        $product->set_name( $name );
        $product->set_status( sanitize_text_field( $a['status'] ?? 'publish' ) );

        if ( !empty( $a['description'] ) ) {
          $product->set_description( wp_kses_post( $a['description'] ) );
        }
        if ( !empty( $a['short_description'] ) ) {
          $product->set_short_description( wp_kses_post( $a['short_description'] ) );
        }
        if ( !empty( $a['sku'] ) ) {
          $product->set_sku( sanitize_text_field( $a['sku'] ) );
        }
        if ( isset( $a['regular_price'] ) ) {
          $product->set_regular_price( sanitize_text_field( $a['regular_price'] ) );
        }
        if ( isset( $a['sale_price'] ) ) {
          $product->set_sale_price( sanitize_text_field( $a['sale_price'] ) );
        }
        if ( isset( $a['stock_quantity'] ) ) {
          $product->set_stock_quantity( absint( $a['stock_quantity'] ) );
        }
        if ( isset( $a['manage_stock'] ) ) {
          $product->set_manage_stock( (bool) $a['manage_stock'] );
        }

        $product_id = $product->save();

        return [
          'id' => $product_id,
          'message' => 'Product created successfully.',
        ];

      case 'wc_update_product':
        $product_id = absint( $a['id'] ?? 0 );
        if ( !$product_id ) {
          throw new Exception( 'Product ID is required.' );
        }

        $product = wc_get_product( $product_id );
        if ( !$product ) {
          throw new Exception( 'Product not found.' );
        }

        if ( isset( $a['name'] ) ) {
          $product->set_name( sanitize_text_field( $a['name'] ) );
        }
        if ( isset( $a['status'] ) ) {
          $product->set_status( sanitize_text_field( $a['status'] ) );
        }
        if ( isset( $a['description'] ) ) {
          $product->set_description( wp_kses_post( $a['description'] ) );
        }
        if ( isset( $a['short_description'] ) ) {
          $product->set_short_description( wp_kses_post( $a['short_description'] ) );
        }
        if ( isset( $a['sku'] ) ) {
          $product->set_sku( sanitize_text_field( $a['sku'] ) );
        }
        if ( isset( $a['regular_price'] ) ) {
          $product->set_regular_price( sanitize_text_field( $a['regular_price'] ) );
        }
        if ( isset( $a['sale_price'] ) ) {
          $product->set_sale_price( sanitize_text_field( $a['sale_price'] ) );
        }
        if ( isset( $a['stock_quantity'] ) ) {
          $product->set_stock_quantity( absint( $a['stock_quantity'] ) );
        }
        if ( isset( $a['stock_status'] ) ) {
          $product->set_stock_status( sanitize_text_field( $a['stock_status'] ) );
        }
        if ( isset( $a['manage_stock'] ) ) {
          $product->set_manage_stock( (bool) $a['manage_stock'] );
        }

        $product->save();

        return [
          'id' => $product_id,
          'message' => 'Product updated successfully.',
        ];

      case 'wc_delete_product':
        $product_id = absint( $a['id'] ?? 0 );
        if ( !$product_id ) {
          throw new Exception( 'Product ID is required.' );
        }

        $product = wc_get_product( $product_id );
        if ( !$product ) {
          throw new Exception( 'Product not found.' );
        }

        $force = (bool) ( $a['force'] ?? false );
        $product->delete( $force );

        return [
          'id' => $product_id,
          'message' => $force ? 'Product permanently deleted.' : 'Product moved to trash.',
        ];

      case 'wc_alter_product':
        $product_id = absint( $a['id'] ?? 0 );
        $field = sanitize_key( $a['field'] ?? '' );
        $search = $a['search'] ?? null;
        $replace = $a['replace'] ?? '';
        $is_regex = (bool) ( $a['regex'] ?? false );

        if ( !$product_id || !$field || $search === null ) {
          throw new Exception( 'Product ID, field, search, and replace are required.' );
        }

        // Map field names to WooCommerce getter/setter methods
        $field_map = [
          'description' => [ 'get_description', 'set_description' ],
          'short_description' => [ 'get_short_description', 'set_short_description' ],
          'name' => [ 'get_name', 'set_name' ],
        ];

        if ( !isset( $field_map[ $field ] ) ) {
          throw new Exception( 'Field must be: description, short_description, or name.' );
        }

        $product = wc_get_product( $product_id );
        if ( !$product ) {
          throw new Exception( 'Product not found.' );
        }

        $getter = $field_map[ $field ][0];
        $setter = $field_map[ $field ][1];
        $content = $product->$getter();
        $count = 0;

        if ( $is_regex ) {
          // Validate regex pattern
          set_error_handler( fn () => false );
          $test = preg_match( $search, '' );
          restore_error_handler();
          if ( $test === false ) {
            throw new Exception( 'Invalid regex pattern.' );
          }
          $new_content = preg_replace( $search, $replace, $content, -1, $count );
          if ( $new_content === null ) {
            throw new Exception( 'Regex error.' );
          }
        }
        else {
          $new_content = str_replace( $search, $replace, $content, $count );
        }

        if ( $count === 0 ) {
          return [
            'id' => $product_id,
            'replacements' => 0,
            'message' => 'No occurrences found; product unchanged.',
          ];
        }

        $product->$setter( $new_content );
        $product->save();

        return [
          'id' => $product_id,
          'field' => $field,
          'replacements' => $count,
          'message' => $count . ' replacement' . ( $count === 1 ? '' : 's' ) . ' applied.',
        ];

        /* ───────── Orders ───────── */
      case 'wc_list_orders':
        $args = [
          'limit' => isset( $a['limit'] ) ? absint( $a['limit'] ) : 20,
          'offset' => isset( $a['offset'] ) ? absint( $a['offset'] ) : 0,
          'return' => 'objects',
        ];

        if ( !empty( $a['status'] ) ) {
          $args['status'] = sanitize_text_field( $a['status'] );
        }
        if ( !empty( $a['customer'] ) ) {
          $args['customer'] = absint( $a['customer'] );
        }
        if ( !empty( $a['date_after'] ) ) {
          $args['date_created'] = '>=' . sanitize_text_field( $a['date_after'] );
        }
        if ( !empty( $a['date_before'] ) ) {
          $args['date_created'] = '<=' . sanitize_text_field( $a['date_before'] );
        }

        $orders = wc_get_orders( $args );
        $result = array_map( [ $this, 'format_order' ], $orders );

        return [
          'total' => count( $result ),
          'orders' => $result,
        ];

      case 'wc_get_order':
        $order_id = absint( $a['id'] ?? 0 );
        if ( !$order_id ) {
          throw new Exception( 'Order ID is required.' );
        }

        $order = wc_get_order( $order_id );
        if ( !$order ) {
          throw new Exception( 'Order not found.' );
        }

        return $this->format_order( $order );

      case 'wc_update_order_status':
        $order_id = absint( $a['id'] ?? 0 );
        $status = sanitize_text_field( $a['status'] ?? '' );

        if ( !$order_id || !$status ) {
          throw new Exception( 'Order ID and status are required.' );
        }

        $order = wc_get_order( $order_id );
        if ( !$order ) {
          throw new Exception( 'Order not found.' );
        }

        // Remove wc- prefix if provided
        $status = str_replace( 'wc-', '', $status );
        $order->update_status( $status );

        return [
          'id' => $order_id,
          'status' => $order->get_status(),
          'message' => 'Order status updated successfully.',
        ];

      case 'wc_add_order_note':
        $order_id = absint( $a['id'] ?? 0 );
        $note = sanitize_textarea_field( $a['note'] ?? '' );

        if ( !$order_id || !$note ) {
          throw new Exception( 'Order ID and note are required.' );
        }

        $order = wc_get_order( $order_id );
        if ( !$order ) {
          throw new Exception( 'Order not found.' );
        }

        $customer_note = (bool) ( $a['customer_note'] ?? false );
        $note_id = $order->add_order_note( $note, $customer_note ? 1 : 0 );

        return [
          'id' => $order_id,
          'note_id' => $note_id,
          'message' => 'Order note added successfully.',
        ];

      case 'wc_create_refund':
        $order_id = absint( $a['order_id'] ?? 0 );
        if ( !$order_id ) {
          throw new Exception( 'Order ID is required.' );
        }

        $order = wc_get_order( $order_id );
        if ( !$order ) {
          throw new Exception( 'Order not found.' );
        }

        $amount = isset( $a['amount'] ) ? sanitize_text_field( $a['amount'] ) : $order->get_total();
        $reason = sanitize_text_field( $a['reason'] ?? '' );

        $refund = wc_create_refund( [
          'order_id' => $order_id,
          'amount' => $amount,
          'reason' => $reason,
        ] );

        if ( is_wp_error( $refund ) ) {
          throw new Exception( $refund->get_error_message() );
        }

        return [
          'order_id' => $order_id,
          'refund_id' => $refund->get_id(),
          'amount' => $amount,
          'message' => 'Refund created successfully.',
        ];

      case 'wc_get_orders_by_customer':
        $customer_id = isset( $a['customer_id'] ) ? absint( $a['customer_id'] ) : 0;
        $customer_email = isset( $a['customer_email'] ) ? sanitize_email( $a['customer_email'] ) : '';

        if ( !$customer_id && !$customer_email ) {
          throw new Exception( 'Customer ID or email is required.' );
        }

        $args = [
          'limit' => isset( $a['limit'] ) ? absint( $a['limit'] ) : 20,
          'return' => 'objects',
        ];

        if ( $customer_id ) {
          $args['customer'] = $customer_id;
        }
        elseif ( $customer_email ) {
          $args['billing_email'] = $customer_email;
        }

        $orders = wc_get_orders( $args );
        $result = array_map( [ $this, 'format_order' ], $orders );

        return [
          'total' => count( $result ),
          'orders' => $result,
        ];

        /* ───────── Inventory ───────── */
      case 'wc_update_stock':
        $product_id = absint( $a['product_id'] ?? 0 );
        $quantity = isset( $a['quantity'] ) ? absint( $a['quantity'] ) : null;

        if ( !$product_id || $quantity === null ) {
          throw new Exception( 'Product ID and quantity are required.' );
        }

        $product = wc_get_product( $product_id );
        if ( !$product ) {
          throw new Exception( 'Product not found.' );
        }

        $product->set_stock_quantity( $quantity );
        $product->save();

        return [
          'product_id' => $product_id,
          'stock_quantity' => $product->get_stock_quantity(),
          'message' => 'Stock updated successfully.',
        ];

        /* ───────── Analytics ───────── */
      case 'wc_get_sales_report':
        $date_after = isset( $a['date_after'] ) ? sanitize_text_field( $a['date_after'] ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_before = isset( $a['date_before'] ) ? sanitize_text_field( $a['date_before'] ) : date( 'Y-m-d' );

        $orders = wc_get_orders( [
          'limit' => -1,
          'status' => [ 'completed', 'processing' ],
          'date_created' => $date_after . '...' . $date_before,
          'return' => 'objects',
        ] );

        $total_sales = 0;
        $order_count = count( $orders );

        foreach ( $orders as $order ) {
          $total_sales += $order->get_total();
        }

        $avg_order_value = $order_count > 0 ? $total_sales / $order_count : 0;

        return [
          'period' => [
            'from' => $date_after,
            'to' => $date_before,
          ],
          'total_sales' => wc_format_decimal( $total_sales, 2 ),
          'order_count' => $order_count,
          'average_order_value' => wc_format_decimal( $avg_order_value, 2 ),
        ];

      case 'wc_get_top_sellers':
        $limit = isset( $a['limit'] ) ? absint( $a['limit'] ) : 10;
        $date_after = isset( $a['date_after'] ) ? sanitize_text_field( $a['date_after'] ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_before = isset( $a['date_before'] ) ? sanitize_text_field( $a['date_before'] ) : date( 'Y-m-d' );

        global $wpdb;

        $query = $wpdb->prepare(
          "SELECT
            oim.meta_value as product_id,
            SUM( oim2.meta_value ) as qty
          FROM {$wpdb->prefix}woocommerce_order_items oi
          LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
          LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi.order_item_id = oim2.order_item_id
          LEFT JOIN {$wpdb->posts} orders ON oi.order_id = orders.ID
          WHERE
            orders.post_type = 'shop_order'
            AND orders.post_status IN ('wc-completed', 'wc-processing')
            AND orders.post_date >= %s
            AND orders.post_date <= %s
            AND oim.meta_key = '_product_id'
            AND oim2.meta_key = '_qty'
          GROUP BY product_id
          ORDER BY qty DESC
          LIMIT %d",
          $date_after . ' 00:00:00',
          $date_before . ' 23:59:59',
          $limit
        );

        $results = $wpdb->get_results( $query );
        $top_sellers = [];

        foreach ( $results as $result ) {
          $product = wc_get_product( $result->product_id );
          if ( $product ) {
            $top_sellers[] = [
              'product_id' => $result->product_id,
              'name' => $product->get_name(),
              'quantity_sold' => absint( $result->qty ),
            ];
          }
        }

        return [
          'period' => [
            'from' => $date_after,
            'to' => $date_before,
          ],
          'top_sellers' => $top_sellers,
        ];

      case 'wc_get_revenue_stats':
        $date_after = isset( $a['date_after'] ) ? sanitize_text_field( $a['date_after'] ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_before = isset( $a['date_before'] ) ? sanitize_text_field( $a['date_before'] ) : date( 'Y-m-d' );

        $orders = wc_get_orders( [
          'limit' => -1,
          'status' => [ 'completed', 'processing' ],
          'date_created' => $date_after . '...' . $date_before,
          'return' => 'objects',
        ] );

        $total_sales = 0;
        $total_refunds = 0;
        $order_count = count( $orders );

        foreach ( $orders as $order ) {
          $total_sales += $order->get_total();
          $total_refunds += $order->get_total_refunded();
        }

        $net_sales = $total_sales - $total_refunds;
        $avg_order_value = $order_count > 0 ? $net_sales / $order_count : 0;

        return [
          'period' => [
            'from' => $date_after,
            'to' => $date_before,
          ],
          'total_sales' => wc_format_decimal( $total_sales, 2 ),
          'total_refunds' => wc_format_decimal( $total_refunds, 2 ),
          'net_sales' => wc_format_decimal( $net_sales, 2 ),
          'order_count' => $order_count,
          'average_order_value' => wc_format_decimal( $avg_order_value, 2 ),
        ];

      case 'wc_get_low_stock_products':
        $threshold = isset( $a['threshold'] ) ? absint( $a['threshold'] ) : 5;

        $args = [
          'limit' => -1,
          'stock_status' => 'instock',
          'return' => 'objects',
        ];

        $products = wc_get_products( $args );
        $low_stock = [];

        foreach ( $products as $product ) {
          if ( $product->managing_stock() ) {
            $stock_qty = $product->get_stock_quantity();
            if ( $stock_qty !== null && $stock_qty <= $threshold ) {
              $low_stock[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'stock_quantity' => $stock_qty,
              ];
            }
          }
        }

        return [
          'threshold' => $threshold,
          'total' => count( $low_stock ),
          'products' => $low_stock,
        ];

      case 'wc_get_stock_report':
        $products = wc_get_products( [ 'limit' => -1, 'return' => 'objects' ] );

        $in_stock = 0;
        $out_of_stock = 0;
        $on_backorder = 0;
        $low_stock = 0;

        foreach ( $products as $product ) {
          $stock_status = $product->get_stock_status();

          if ( $stock_status === 'instock' ) {
            $in_stock++;
            if ( $product->managing_stock() && $product->get_stock_quantity() !== null && $product->get_stock_quantity() <= 5 ) {
              $low_stock++;
            }
          }
          elseif ( $stock_status === 'outofstock' ) {
            $out_of_stock++;
          }
          elseif ( $stock_status === 'onbackorder' ) {
            $on_backorder++;
          }
        }

        return [
          'total_products' => count( $products ),
          'in_stock' => $in_stock,
          'out_of_stock' => $out_of_stock,
          'on_backorder' => $on_backorder,
          'low_stock' => $low_stock,
        ];

      case 'wc_bulk_update_stock':
        if ( empty( $a['updates'] ) || !is_array( $a['updates'] ) ) {
          throw new Exception( 'Updates array is required.' );
        }

        $results = [];
        foreach ( $a['updates'] as $update ) {
          $product_id = absint( $update['product_id'] ?? 0 );
          $quantity = isset( $update['quantity'] ) ? absint( $update['quantity'] ) : null;

          if ( !$product_id || $quantity === null ) {
            $results[] = [
              'product_id' => $product_id,
              'success' => false,
              'message' => 'Invalid product ID or quantity.',
            ];
            continue;
          }

          $product = wc_get_product( $product_id );
          if ( !$product ) {
            $results[] = [
              'product_id' => $product_id,
              'success' => false,
              'message' => 'Product not found.',
            ];
            continue;
          }

          $product->set_stock_quantity( $quantity );
          $product->save();

          $results[] = [
            'product_id' => $product_id,
            'success' => true,
            'stock_quantity' => $product->get_stock_quantity(),
          ];
        }

        return [
          'total_updated' => count( array_filter( $results, fn ( $r ) => $r['success'] ) ),
          'results' => $results,
        ];

        /* ───────── Customers ───────── */
      case 'wc_list_customers':
        $args = [
          'role' => 'customer',
          'number' => isset( $a['limit'] ) ? absint( $a['limit'] ) : 20,
          'offset' => isset( $a['offset'] ) ? absint( $a['offset'] ) : 0,
        ];

        if ( !empty( $a['search'] ) ) {
          $args['search'] = '*' . sanitize_text_field( $a['search'] ) . '*';
        }

        $user_query = new WP_User_Query( $args );
        $users = $user_query->get_results();
        $customers = [];

        foreach ( $users as $user ) {
          $customer = new WC_Customer( $user->ID );
          $customers[] = $this->format_customer( $customer );
        }

        return [
          'total' => count( $customers ),
          'customers' => $customers,
        ];

      case 'wc_get_customer':
        $customer_id = absint( $a['id'] ?? 0 );
        if ( !$customer_id ) {
          throw new Exception( 'Customer ID is required.' );
        }

        $customer = new WC_Customer( $customer_id );
        if ( !$customer->get_id() ) {
          throw new Exception( 'Customer not found.' );
        }

        return $this->format_customer( $customer );

      case 'wc_update_customer':
        $customer_id = absint( $a['id'] ?? 0 );
        if ( !$customer_id ) {
          throw new Exception( 'Customer ID is required.' );
        }

        $customer = new WC_Customer( $customer_id );
        if ( !$customer->get_id() ) {
          throw new Exception( 'Customer not found.' );
        }

        if ( isset( $a['email'] ) ) {
          $customer->set_email( sanitize_email( $a['email'] ) );
        }
        if ( isset( $a['first_name'] ) ) {
          $customer->set_first_name( sanitize_text_field( $a['first_name'] ) );
        }
        if ( isset( $a['last_name'] ) ) {
          $customer->set_last_name( sanitize_text_field( $a['last_name'] ) );
        }

        $customer->save();

        return [
          'id' => $customer_id,
          'message' => 'Customer updated successfully.',
        ];

        /* ───────── Reviews ───────── */
      case 'wc_list_reviews':
        $args = [
          'post_type' => 'product',
          'status' => isset( $a['status'] ) ? sanitize_text_field( $a['status'] ) : 'approve',
          'number' => isset( $a['limit'] ) ? absint( $a['limit'] ) : 20,
        ];

        if ( isset( $a['product_id'] ) ) {
          $args['post_id'] = absint( $a['product_id'] );
        }

        $comments = get_comments( $args );
        $reviews = [];

        foreach ( $comments as $comment ) {
          $reviews[] = [
            'id' => $comment->comment_ID,
            'product_id' => $comment->comment_post_ID,
            'author' => $comment->comment_author,
            'email' => $comment->comment_author_email,
            'content' => $comment->comment_content,
            'rating' => get_comment_meta( $comment->comment_ID, 'rating', true ),
            'date' => $comment->comment_date,
            'status' => $comment->comment_approved,
          ];
        }

        return [
          'total' => count( $reviews ),
          'reviews' => $reviews,
        ];

      case 'wc_approve_review':
        $review_id = absint( $a['review_id'] ?? 0 );
        if ( !$review_id ) {
          throw new Exception( 'Review ID is required.' );
        }

        $result = wp_set_comment_status( $review_id, 'approve' );
        if ( !$result ) {
          throw new Exception( 'Failed to approve review.' );
        }

        return [
          'review_id' => $review_id,
          'message' => 'Review approved successfully.',
        ];

      case 'wc_delete_review':
        $review_id = absint( $a['review_id'] ?? 0 );
        if ( !$review_id ) {
          throw new Exception( 'Review ID is required.' );
        }

        $result = wp_delete_comment( $review_id, true );
        if ( !$result ) {
          throw new Exception( 'Failed to delete review.' );
        }

        return [
          'review_id' => $review_id,
          'message' => 'Review deleted successfully.',
        ];

      default:
        throw new Exception( 'Unknown tool' );
    }
  }
  #endregion
}
