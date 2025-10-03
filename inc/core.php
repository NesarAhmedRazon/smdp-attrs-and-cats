<?php


if (!defined('ABSPATH')) exit;

class SMDP_Category_Attribute_Relations {

    private static $table_name;

    public function __construct() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'smdp_cat_attr_rel';

        // Activation hook
        register_activation_hook(__FILE__, [$this, 'create_table']);

        // Category UI
        add_action('product_cat_add_form_fields', [$this, 'category_add_form']);
        add_action('product_cat_edit_form_fields', [$this, 'category_edit_form'], 10, 2);
        add_action('created_product_cat', [$this, 'save_category_relations']);
        add_action('edited_product_cat', [$this, 'save_category_relations']);

        // Attribute UI
        add_action('woocommerce_after_edit_attribute_fields', [$this, 'attribute_edit_form']);
        add_action('woocommerce_attribute_updated', [$this, 'save_attribute_relations'], 10, 3);

        // Admin Page
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_smdp_save_matrix', [$this, 'save_matrix']);
        add_action('admin_post_smdp_create_table', [$this, 'handle_create_table']);
    }

    /** Create DB Table + migrate old meta */
    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . self::$table_name . " (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id BIGINT(20) UNSIGNED NOT NULL,
            attribute_id BIGINT(20) UNSIGNED NOT NULL,
            attribute_slug VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_rel (category_id, attribute_id),
            KEY cat (category_id),
            KEY attr (attribute_id)            

        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Run migration only if table empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM " . self::$table_name);
        if ($count == 0) {
            $this->migrate_old_meta();
        }
    }

    /** Manual DB create handler */
    public function handle_create_table() {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer('smdp_create_table');

        $this->create_table();

        wp_redirect(admin_url('admin.php?page=smdp-cat-attr-rel&created=1'));
        exit;
    }

    /** Migration from old term meta */
    private function migrate_old_meta() {
        global $wpdb;
        $logger = SMDP_Logger::get_instance( SMDP_AT_CAT_DIR );

        // Categories → old key: smdPicker_attrs_to_cat
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        foreach ($categories as $cat) {
            $attr_ids = get_term_meta($cat->term_id, 'smdPicker_attrs_to_cat', true);
            // get the attribute slug from the attribute ID
            

            

            if (!empty($attr_ids) && is_array($attr_ids)) {
                foreach ($attr_ids as $attr_id) {
                    $attr = wc_get_attribute($attr_id);
                    
                    $attr_slug = $attr ? preg_replace('/^pa_/', '', $attr->slug) : null;
                    // if slug is empty, skip
                    if (empty($attr_slug)) {
                        continue;
                    }
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT IGNORE INTO " . self::$table_name . " (category_id, attribute_id, attribute_slug) VALUES (%d, %d, %s)",
                            intval($cat->term_id), intval($attr_id), $attr_slug
                        )
                    );
                    
                }
                
            }
        }

        // Attributes → old key: smdPicker_cat_to_attr
        $attributes = wc_get_attribute_taxonomies();
        if (!empty($attributes)) {
            foreach ($attributes as $attr) {
                $taxonomy = wc_attribute_taxonomy_name($attr->attribute_name);
                $attr_terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                
                if (empty($attr_terms)) continue;
                $attr_slug = $attr->attribute_name;

                foreach ($attr_terms as $attr_term) {
                    $cat_ids = get_term_meta($attr_term->term_id, 'smdPicker_cat_to_attr', true);
                    
                    if (!empty($cat_ids) && is_array($cat_ids)) {
                        foreach ($cat_ids as $cat_id) {
                            $wpdb->query(
                                $wpdb->prepare(
                                    "INSERT IGNORE INTO " . self::$table_name . " (category_id, attribute_id, attribute_slug) VALUES (%d, %d, %s)",
                                    intval($cat_id), intval($attr->attribute_id), $attr_slug
                                )
                            );
                        }
                    }
                }
            }
        }
    }

    /** Category Add Form */
    public function category_add_form() {
        $attributes = wc_get_attribute_taxonomies();
        if (empty($attributes)) return;
        ?>
        <div class="form-field">
            <label><?php _e('Assign Attributes', 'smdp-textdomain'); ?></label>
            <?php foreach ($attributes as $attr): ?>
                <label>
                    <input type="checkbox" name="smdp_attributes[]" value="<?php echo esc_attr($attr->attribute_id); ?>">
                    <?php echo esc_html($attr->attribute_label); ?>
                </label><br>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** Category Edit Form */
    public function category_edit_form($term, $taxonomy) {
        $attributes = wc_get_attribute_taxonomies();
        if (empty($attributes)) return;

        $saved = $this->get_attributes_for_category($term->term_id);
        ?>
        <tr class="form-field">
            <th scope="row"><label><?php _e('Assign Attributes', 'smdp-textdomain'); ?></label></th>
            <td>
                <?php foreach ($attributes as $attr): ?>
                    <label>
                        <input type="checkbox" name="smdp_attributes[]" value="<?php echo esc_attr($attr->attribute_id); ?>"
                            <?php checked(in_array($attr->attribute_id, $saved)); ?>>
                        <?php echo esc_html($attr->attribute_label); ?>
                    </label><br>
                <?php endforeach; ?>
            </td>
        </tr>
        <?php
    }

    /** Save Category Relations */
    public function save_category_relations($term_id) {
        global $wpdb;
        $wpdb->delete(self::$table_name, ['category_id' => $term_id]);

        if (!empty($_POST['smdp_attributes'])) {
            foreach ($_POST['smdp_attributes'] as $attr_id) {
                $attr_slug = wc_get_attribute($attr_id)->slug ?? null;
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO " . self::$table_name . " (category_id, attribute_id, attribute_slug) VALUES (%d, %d, %s)",
                        $term_id, intval($attr_id), $attr_slug
                    )
                );
            }
        }
    }

    /** Attribute Edit Form */
    public function attribute_edit_form($attribute) {
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);

    // Make sure $attribute is an object and has attribute_id
    $attribute_id = null;
    if (is_object($attribute) && isset($attribute->attribute_id)) {
        $attribute_id = (int) $attribute->attribute_id;
    } elseif (is_numeric($attribute)) {
        $attribute_id = (int) $attribute; // sometimes passed directly as ID
    }

    $saved = [];
    if ($attribute_id) {
        $saved = $this->get_categories_for_attribute($attribute_id);
    }

    ?>
    <div class="form-field">
            <label><?php _e('Assign Categories', 'smdp-textdomain'); ?></label>
            <ul>
                <?php foreach ($categories as $cat): ?>
                    <li>
                        <label>
                            <input type="checkbox" name="smdp_categories[]" value="<?php echo esc_attr($cat->term_id); ?>"
                                <?php checked(in_array($cat->term_id, $saved)); ?>>
                            <?php echo esc_html($cat->name); ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /** Save Attribute Relations */
    public function save_attribute_relations($attribute_id, $args, $raw_args) {
        global $wpdb;
        $wpdb->delete(self::$table_name, ['attribute_id' => $attribute_id]);

        if (!empty($_POST['smdp_categories'])) {
            foreach ($_POST['smdp_categories'] as $cat_id) {
                $attr_slug = wc_get_attribute($attribute_id)->slug ?? null;
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO " . self::$table_name . " (category_id, attribute_id, attribute_slug) VALUES (%d, %d, %s)",
                        intval($cat_id), $attribute_id, $attr_slug
                    )
                );
            }
        }
    }

    /** Helpers */
    public function get_attributes_for_category($cat_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare("SELECT attribute_id FROM " . self::$table_name . " WHERE category_id=%d", $cat_id));
    }

    public function get_categories_for_attribute($attr_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare("SELECT category_id FROM " . self::$table_name . " WHERE attribute_id=%d", $attr_id));
    }

    /** Admin Page */
    public function register_admin_page() {
        add_submenu_page(
            'woocommerce',
            __('Category ↔ Attribute Relations', 'smdp-textdomain'),
            __('Cat-Attr Relations', 'smdp-textdomain'),
            'manage_woocommerce',
            'smdp-cat-attr-rel',
            [$this, 'render_admin_page']
        );
    }

    /** Render Admin Matrix Page */
    public function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) return;

        global $wpdb;
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $attributes = wc_get_attribute_taxonomies();

        // Fetch current relations
        $relations = $wpdb->get_results("SELECT category_id, attribute_id FROM " . self::$table_name, ARRAY_A);
        $map = [];
        foreach ($relations as $rel) {
            $map[$rel['category_id']][] = $rel['attribute_id'];
        }

        ?>
        <div class="wrap" style="position:relative;" >
            <h1><?php _e('Category ↔ Attribute Relations', 'smdp-textdomain'); ?></h1>

            <?php if (isset($_GET['saved'])): ?>
                <div class="updated"><p><?php _e('Relations saved.', 'smdp-textdomain'); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['created'])): ?>
                <div class="updated"><p><?php _e('Table created or updated.', 'smdp-textdomain'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('smdp_save_matrix'); ?>
                <input type="hidden" name="action" value="smdp_save_matrix">

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Category \\ Attribute', 'smdp-textdomain'); ?></th>
                            <?php foreach ($attributes as $attr): ?>
                                <th><?php echo esc_html($attr->attribute_label); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><strong><?php echo esc_html($cat->name); ?></strong></td>
                                <?php foreach ($attributes as $attr): ?>
                                    <td style="text-align:center;">
                                        <input type="checkbox" name="matrix[<?php echo $cat->term_id; ?>][]" value="<?php echo $attr->attribute_id; ?>"
                                            <?php checked(!empty($map[$cat->term_id]) && in_array($attr->attribute_id, $map[$cat->term_id])); ?>>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p ><input type="submit" class="button-primary" value="<?php _e('Save Relations', 'smdp-textdomain'); ?>"></p>
            </form>

            <hr>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('smdp_create_table'); ?>
                <input type="hidden" name="action" value="smdp_create_table">
                <p style="float:right"><input type="submit" style="color: red;" class="button-secondary" value="<?php _e('Create/Repair DB Table', 'smdp-textdomain'); ?>"></p>
            </form>
        </div>
        <?php
    }

    /** Save Matrix */
    public function save_matrix() {
        if (!current_user_can('manage_woocommerce')) wp_die('No permission');
        check_admin_referer('smdp_save_matrix');

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE " . self::$table_name);

        if (!empty($_POST['matrix'])) {
            foreach ($_POST['matrix'] as $cat_id => $attr_ids) {
                foreach ($attr_ids as $attr_id) {
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT IGNORE INTO " . self::$table_name . " (category_id, attribute_id, attribute_slug) VALUES (%d, %d, %s)",
                            intval($cat_id), intval($attr_id), $attr_slug
                        )
                    );
                }
            }
        }

        wp_redirect(admin_url('admin.php?page=smdp-cat-attr-rel&saved=1'));
        exit;
    }
}

new SMDP_Category_Attribute_Relations();
