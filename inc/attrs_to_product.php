<?php
/**
 * Map attributes to products on save, based on category → attribute mapping table
 */

defined('ABSPATH') || exit;

class SMDP_Attrs_To_Product_By_Category {
    public function __construct() {
        add_action('save_post_product', [$this, 'apply_category_attributes_to_product'], 20, 3);
    }

    /**
     * On product save, assign attributes from categories.
     */
    public function apply_category_attributes_to_product($post_id, $post, $update) {
        global $wpdb;
        $logger = SMDP_Logger::get_instance( SMDP_AT_CAT_DIR );
        
        if ($post->post_type !== 'product') {
            return;
        }
        
        $product = wc_get_product($post_id);
        if (!$product) {
            return;
        }

        // 1. Get product categories
        $product_cats = wp_get_post_terms($post_id, 'product_cat', ['fields' => 'ids']);
        if (empty($product_cats)) {
            return;
        }

        // 2. Fetch mapped attributes from DB table
        $table = $wpdb->prefix . 'smdp_cat_attr_rel';
        $placeholders = implode(',', array_fill(0, count($product_cats), '%d'));

        $query = $wpdb->prepare("SELECT DISTINCT attribute_id FROM {$table} WHERE category_id IN ($placeholders)", $product_cats);

        $product_cat_attrs = $wpdb->get_col($query);

        $logger->write( [
            'event' => 'apply_category_attributes_to_product',
            'post_id' => $post_id,
            'type' => $post->post_type,
            'categories' => $product_cats,
            'placeholders' => $placeholders,
            'query' => $query,
            'mapped_attributes' => $product_cat_attrs,
        ] , 'info.log');

        if (empty($product_cat_attrs)) {
            return;
        }

        // 3. Merge with existing attributes
        $ex_attributes = $product->get_attributes() ?? [];
        $ex_attribute_ids = array_map(function($attr) {
            return $attr->get_id();
        }, $ex_attributes);

        $all_attr_ids = array_unique(array_merge($product_cat_attrs, $ex_attribute_ids));

        
        // 4. Build attribute data
        $all_attributes_data = [];
        $term_name = '-'; // default placeholder

        foreach ($all_attr_ids as $attr_id) {
            $attribute_data = wc_get_attribute($attr_id);
            if (!$attribute_data) {
                continue;
            }

            $attr_slug = $attribute_data->slug;
            $existing_attr_val = $product->get_attribute($attr_slug);

            if (!$existing_attr_val) {
                // Create/find placeholder term
                $term = get_term_by('name', $term_name, $attr_slug);
                if (!$term) {
                    $term = wp_insert_term($term_name, $attr_slug, ['slug' => $attr_slug . '-unknown']);
                    if (is_wp_error($term)) {
                        continue;
                    }
                    $term_id = $term['term_id'];
                } else {
                    $term_id = $term->term_id;
                }

                $all_attributes_data[] = [
                    'id'       => $attr_id,
                    'taxonomy' => $attr_slug,
                    'options'  => [$term_id],
                    'visible'  => true,
                ];
            } else {
                // Get existing terms
                $terms = explode(',', $existing_attr_val);
                $term_ids = [];
                foreach ($terms as $t) {
                    $term_data = get_term_by('name', trim($t), $attr_slug);
                    if ($term_data) {
                        $term_ids[] = $term_data->term_id;
                    }
                }

                // Match to existing WC_Product_Attribute if possible
                $ex_attr_obj = null;
                foreach ($ex_attributes as $key => $attr) {
                    if ($attr->get_id() == $attr_id || $key === $attr_slug || $key === 'pa_' . $attr_slug) {
                        $ex_attr_obj = $attr;
                        break;
                    }
                }

                $all_attributes_data[] = [
                    'id'       => $attr_id,
                    'taxonomy' => $attr_slug,
                    'options'  => $term_ids,
                    'position' => $ex_attr_obj ? $ex_attr_obj->get_position() : 0,
                    'visible'  => $ex_attr_obj ? $ex_attr_obj->get_visible() : true,
                ];
            }
        }

        // 5. Convert to WC_Product_Attribute objects with ordering rules
        $attributes = [];
        $position = 0;
        $easyeda_attr = null;

        foreach ($all_attributes_data as $attr_data) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id($attr_data['id']);
            $attribute->set_name($attr_data['taxonomy']);
            $attribute->set_options($attr_data['options']);
            $attribute->set_visible($attr_data['visible'] ?? true);
            $attribute->set_variation(false);

            // Special ordering
            switch ($attr_data['taxonomy']) {
                case 'pa_brand':
                    $attribute->set_position(0);
                    break;
                case 'pa_manufacturer-part-number':
                    $attribute->set_position(1);
                    break;
                case 'pa_package-size':
                    $attribute->set_position(2);
                    break;
                case 'pa_easyeda-id':
                    $easyeda_attr = $attribute; // push later
                    continue 2;
                default:
                    $attribute->set_position($attr_data['position'] ?? $position + 3);
                    $position++;
            }

            $attributes[] = $attribute;
        }

        if ($easyeda_attr) {
            $easyeda_attr->set_position(count($attributes));
            $attributes[] = $easyeda_attr;
        }

        // 6. Save attributes back
        $product->set_attributes($attributes);
        $product->save();

        // Debug logging
        error_log("SMDP: Updated product {$post_id} attributes → " . json_encode(array_map(function($a) {
            return $a->get_name();
        }, $attributes)));
    }
}

new SMDP_Attrs_To_Product_By_Category();
