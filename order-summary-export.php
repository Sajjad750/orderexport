<?php
/*
Plugin Name: Order Summary Export
Description: Export WooCommerce orders with filters (date range, country, state, coupon) and download a memory-safe CSV.
Version: 1.5
Author: SSB
*/

if (!defined('ABSPATH')) exit;

// Add submenu under WooCommerce
add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'Order Summary Export',
        'Order Summary Export',
        'manage_woocommerce',
        'order-summary-export',
        'order_summary_export_page'
    );
});

// Enqueue JS & CSS
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'woocommerce_page_order-summary-export') return;

    wp_enqueue_script('order-summary-export-js', plugin_dir_url(__FILE__) . 'order-summary.js', ['jquery'], null, true);
    wp_localize_script('order-summary-export-js', 'OrderExportAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('order_export_nonce')
    ]);

    wp_enqueue_style('order-summary-export-css', plugin_dir_url(__FILE__) . 'order-summary-export.css');
});

// Render plugin page
function order_summary_export_page() {
    ?>
    <div class="wrap">
        <h2>Order Summary Export</h2>
        <p class="order-export-description">Generate/Download CSV files based on filters like date range, country, state, coupon code.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="order-export-form">
            <input type="hidden" name="action" value="export_order_summary_csv">
            <?php wp_nonce_field('order_export_nonce_action', 'order_export_nonce_field'); ?>
            
            <div class="date-flow">
                <div>
                    <label for="start_date">Start Date:</label>
                    <input type="date" name="start_date" id="start_date">
                </div>  
                <div> 
                    <label for="end_date">End Date:</label>
                    <input type="date" name="end_date" id="end_date"><br><br>
                </div>
            </div>

            <div>
                <label for="country">Country:</label>
                <select name="country" id="country">
                    <option value="">Select Country</option>
                    <?php
                    global $wpdb;
                    $countries = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key = '_billing_country'");
                    $all_countries = WC()->countries->get_countries();

                    if ($countries) {
                        foreach ($countries as $country_code) {
                            if (empty($country_code)) continue;
                            $label = $all_countries[$country_code] ?? esc_html($country_code);
                            echo "<option value='" . esc_attr($country_code) . "'>" . esc_html($label) . "</option>";
                        }
                    }
                    ?>
                </select>
            </div>
            <br>

            <div>
                <label for="state">State:</label>
                <select name="state" id="state">
                    <option value="">All</option> <!-- Default "All" option -->
                </select>
            </div>
            <br>

            <label for="coupon_code">Coupon Code:</label>
            <select name="coupon_code" id="coupon_code">
                <option value="">Select Coupon</option>
                <?php
                $used_coupons = $wpdb->get_col("
                    SELECT DISTINCT order_item_name
                    FROM {$wpdb->prefix}woocommerce_order_items
                    WHERE order_item_type = 'coupon'
                ");
                foreach ($used_coupons as $code) {
                    echo "<option value='" . esc_attr($code) . "'>" . esc_html($code) . "</option>";
                }
                ?>
            </select><br><br>

            <input type="submit" name="export_csv" class="button button-primary" value="Download CSV Report">
        </form>
    </div>
    <?php
}

// AJAX: Load states
add_action('wp_ajax_get_states_by_country', function () {
    check_ajax_referer('order_export_nonce', 'nonce');

    $country = sanitize_text_field($_POST['country']);
    $states = WC()->countries->get_states($country);

    if (!empty($states)) {
        // Return all states for the selected country
        foreach ($states as $key => $name) {
            echo "<option value='" . esc_attr($key) . "'>" . esc_html($name) . "</option>";
        }
    } else {
        // If no states are found, just return the "All" option
        echo "<option value=''>All</option>";
    }
    wp_die();
});

// Handle CSV export
add_action('admin_post_export_order_summary_csv', function () {
    if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
    if (!isset($_POST['order_export_nonce_field']) || !wp_verify_nonce($_POST['order_export_nonce_field'], 'order_export_nonce_action')) {
        wp_die('Invalid nonce');
    }
    generate_csv_report($_POST);
});

// Generate and stream CSV
function generate_csv_report($filters) {
    if (!class_exists('WC_Order_Query')) return;

    // Clear any output buffering
    while (ob_get_level()) ob_end_clean();

    // Set headers for CSV download
    header('Content-Description: File Transfer');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="order-summary-' . date('Ymd-His') . '.csv"');
    header('Expires: 0');
    header('Pragma: public');

    $output = fopen('php://output', 'w');
    if (!$output) wp_die('Unable to open output stream');

    // Always write the CSV header
    fputcsv($output, ['Order ID', 'Date', 'Order Quantity', 'Gross Revenue', 'Refund Total', 'Discounts', 'Net Revenue', 'Tax Collected', 'Coupon Code']);

    $limit = 100;
    $page  = 1;
    $order_found = false; // track if any order is exported

    do {
        $args = [
            'limit'  => $limit,
            'page'   => $page,
            'status' => ['wc-completed', 'wc-processing'],
            'type'   => 'shop_order',
        ];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $args['date_created'] = $filters['start_date'] . '...' . $filters['end_date'];
        }

        $orders = wc_get_orders($args);

        foreach ($orders as $order) {
            if (!($order instanceof WC_Order)) continue;

            // Filter logic
            if (!empty($filters['country']) && $order->get_billing_country() !== $filters['country']) continue;
            if (!empty($filters['state']) && $order->get_billing_state() !== $filters['state']) continue;
            if (!empty($filters['coupon_code']) && !in_array($filters['coupon_code'], $order->get_coupon_codes())) continue;

            // Found at least one order to export
            $order_found = true;

            $refund_total = 0;
            foreach ($order->get_refunds() as $refund) {
                $refund_total += $refund->get_total();
            }

            $quantity = array_sum(array_map(function ($item) {
                return $item->get_quantity();
            }, $order->get_items()));

            $coupon_codes = implode(', ', $order->get_coupon_codes());

            fputcsv($output, [
                $order->get_id(),
                $order->get_date_created()->date('Y-m-d'),
                $quantity,
                $order->get_subtotal(),
                $refund_total,
                $order->get_discount_total(),
                $order->get_total() - $refund_total - $order->get_discount_total(), // Net revenue
                $order->get_total_tax(),
                $coupon_codes
            ]);
        }

        $page++;
    } while (count($orders) === $limit);

    // If no orders were found, the CSV will still contain the header only
    fclose($output);
    exit;
}
?>
