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
        $logger->write( [
            'start' => '--------------xxxxxxxxxxxxxxx----------------',
            'post_id' => $post_id,
        ] , 'info.log',true);
        // 2. Fetch mapped attributes from DB table
        $table = $wpdb->prefix . 'smdp_cat_attr_rel';
        $placeholders = implode(',', array_fill(0, count($product_cats), '%d'));

        $query = $wpdb->prepare("SELECT DISTINCT attribute_slug FROM {$table} WHERE category_id IN ($placeholders)", $product_cats);

        $product_cat_attrs_slugs = array_map('sanitize_title', $wpdb->get_col($query)); // attribute slugs

        

        if (empty($product_cat_attrs_slugs)) {
            return;
        }

        // 3. Merge with existing attributes (slugs only)
            $ex_attributes = $product->get_attributes() ?? [];
            $ex_attribute_slugs = [];

            foreach ($ex_attributes as $key => $attr) {
                if ($attr instanceof WC_Product_Attribute) {
                    // New-style WooCommerce attribute object
                    $slug = preg_replace('/^pa_/', '', $attr->get_name());
                    $ex_attribute_slugs[] = $slug;
                } elseif (is_string($key)) {
                    // Legacy array-style attributes
                    $slug = preg_replace('/^pa_/', '', $key);
                    $ex_attribute_slugs[] = $slug;
                }               
            }

        
        
            $all_attr_slugs = array_unique(array_merge($product_cat_attrs_slugs, $ex_attribute_slugs));

            // Remove empty or invalid slugs
            $all_attr_slugs = array_values(array_filter($all_attr_slugs, function($slug) { 
                return !empty($slug) && is_string($slug); 
            }));

            // 🧠 Build a map of all WooCommerce attributes (slug → id)
            $attr_taxonomies = wc_get_attribute_taxonomies();
            $attr_map = [];
            foreach ($attr_taxonomies as $tax) {
                $attr_map['pa_' . $tax->attribute_name] = (int) $tax->attribute_id;
            }

            // 4️⃣ Build attribute data
            $all_attributes_data = [];
            $term_name = '-'; // default placeholder

            foreach ($all_attr_slugs as $attr_slug) {
                $the_slug = 'pa_' . $attr_slug;
                $attr_id  = $attr_map[$the_slug] ?? null;

                if (!$attr_id) {
                    continue; // skip non-existing attributes
                }

                $existing_attr_val = $product->get_attribute($the_slug);

                if (!$existing_attr_val) {
                    $term_slug = $the_slug . '-unknown';

                    // Create or get placeholder term
                    $term = get_term_by('name', $term_slug, $the_slug);
                    if (!$term) {
                        $term = wp_insert_term($term_name, $the_slug, ['slug' => $term_slug]);
                        if (is_wp_error($term)) {
                            continue;
                        }
                        $term_name = $term_name; // keep default '-'
                    } else {
                        $term_name = $term->name;
                    }

                    $data = [
                        'id'       => $attr_id,
                        'taxonomy' => $the_slug,
                        'options'  => [$term_name],
                        'visible'  => true,
                    ];

                    $all_attributes_data[] = $data;

                } else {
                    // Get existing terms
                    $terms = explode(',', $existing_attr_val);
                    $term_names = [];
                    foreach ($terms as $t) {
                        $term_data = get_term_by('name', trim($t), $the_slug);
                        if ($term_data) {
                            $term_names[] = $term_data->name;
                        }
                    }

                    // Match to existing WC_Product_Attribute if possible
                    $position = 0;
                    $ex_attr_obj = null;
                    foreach ($ex_attributes as $attr) {
                        if ($attr instanceof WC_Product_Attribute) {
                            $attr_slug_check = preg_replace('/^pa_/', '', $attr->slug);
                            if ($attr_slug_check === $attr_slug) {
                                $ex_attr_obj = $attr;
                                break;
                            }
                        }
                        $position++;
                    }

                    $data = [
                        'id'       => $attr_id,
                        'taxonomy' => $the_slug,
                        'options'  => $term_names,
                        'position' => $ex_attr_obj ? $ex_attr_obj->get_position() : $position,
                        'visible'  => $ex_attr_obj ? $ex_attr_obj->get_visible() : true,
                    ];

                    $all_attributes_data[] = $data;
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


        $logger->write( [
            'end' => '--------------xxxxxxxxxxxxxxx----------------',
        ] , 'info.log');
    }
}
    
}

new SMDP_Attrs_To_Product_By_Category();


function get_attributes_by_slugs($all_attr_slugs){
    global $wpdb;
    if (empty($all_attr_slugs)) {
        return [];
    }
    // get the attributes ids from WooCommerce and return the array of ids
    $placeholders = implode(',', array_fill(0, count($all_attr_slugs), '%s'));
    $table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
    $query = $wpdb->prepare("SELECT attribute_id, attribute_name FROM {$table} WHERE attribute_name IN ($placeholders)", $all_attr_slugs);
    $results = $wpdb->get_results($query, OBJECT);
    $attr_ids = [];
    foreach ($results as $row) {
        array_push($attr_ids, (int)$row->attribute_id);
    }
    return $attr_ids;
}