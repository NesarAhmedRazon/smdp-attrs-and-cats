<?php
/**
 * Phase 2 – Auto-assign attributes to product based on selected categories (on save only)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMDP_Attrs_To_Product_By_Category {
    private static $table_name;

    public function __construct() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'smdp_cat_attr_rel';

        // Hook on product save
        add_action('save_post_product', [ $this, 'assign_attributes_on_save' ], 20, 3);
    }

    /**
     * On product save, add attributes linked to selected categories
     */
    public function assign_attributes_on_save($post_id, $post, $update) {
        // skip autosave / revisions
        if ( wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ) {
            return;
        }

        $product = wc_get_product($post_id);
        if ( ! $product ) return;

        // 1. Get product categories
        $categories = wp_get_post_terms($post_id, 'product_cat', [ 'fields' => 'ids' ]);
        if ( empty($categories) ) return;

        global $wpdb;
        $attr_ids = [];

        // 2. Query attributes related to those categories
        $placeholders = implode(',', array_fill(0, count($categories), '%d'));
        $sql = "SELECT DISTINCT attribute_id FROM " . self::$table_name . " 
                WHERE category_id IN ($placeholders)";
        $prepared = $wpdb->prepare($sql, $categories);
        $results = $wpdb->get_col($prepared);

        if ( ! empty($results) ) {
            $attr_ids = array_map('intval', $results);
        }

        if ( empty($attr_ids) ) return;

        // 3. Add attributes to product
        $product_attributes = $product->get_attributes(); // existing ones

        foreach ($attr_ids as $attr_id) {
            $taxonomy = wc_attribute_taxonomy_name_by_id($attr_id);
            if ( ! taxonomy_exists($taxonomy) ) continue;

            if ( isset($product_attributes[$taxonomy]) ) {
                // already set, skip
                continue;
            }

            $product_attributes[$taxonomy] = [
                'name'         => $taxonomy,
                'value'        => '',
                'position'     => 0,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 1,
            ];
        }

        // 4. Save back to product
        $product->set_attributes($product_attributes);
        $product->save();
    }
}

new SMDP_Attrs_To_Product_By_Category();
