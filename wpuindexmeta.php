<?php

/*
Plugin Name: WPU Index Meta
Description: Handle indexes for meta values and more
Version: 0.1.0
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUIndexMeta {
    public function __construct() {
        add_filter('plugins_loaded', array(&$this, 'plugins_loaded'));
    }

    public function plugins_loaded() {
        $this->keys = apply_filters('wpuindexmeta__meta_keys', array());
        add_action('wpuindexmeta_reindex', array(&$this, 'reindex'), 10, 2);
    }

    /**
     * Init table
     * @param  string $meta_key meta key
     */
    private function maybe_create_table($meta_key = '') {
        global $wpdb;
        if (!$meta_key || !in_array($meta_key, $this->keys)) {
            return false;
        }
        $table_name = $this->get_table_name($meta_key);
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (post_id mediumint(9), meta_value text) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Reindex table
     * @param  string $meta_key [description]
     * @return [type]           [description]
     */
    public function reindex($meta_key = '') {
        global $wpdb;
        if (!$meta_key || !in_array($meta_key, $this->keys)) {
            return false;
        }

        /* Ensure table is ok */
        $this->maybe_create_table($meta_key);
        $table_name = $this->get_table_name($meta_key);

        /* Clear it */
        $wpdb->query("TRUNCATE $table_name");

        /* Index every result */
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key=%s", $meta_key), ARRAY_A);
        foreach ($rows as $_item) {
            $wpdb->insert($table_name, $_item);
        }
    }

    /**
     * Get table name for a meta key
     * @param  string $meta_key [description]
     * @return [type]           [description]
     */
    private function get_table_name($meta_key = '') {
        global $wpdb;
        if (!$meta_key || !in_array($meta_key, $this->keys)) {
            return false;
        }
        return $wpdb->prefix . 'wpu_index_meta__' . $meta_key;
    }
}

$WPUIndexMeta = new WPUIndexMeta();

/*
add_filter('wpuindexmeta__meta_keys', 'demodemo_wpuindexmeta__meta_keys', 10, 1);
function demodemo_wpuindexmeta__meta_keys($keys) {
    $keys[] = 'personne_id';
    $keys[] = 'film_id';
    return $keys;
}

add_action('init', 'demodemo_init');
function demodemo_init() {
    do_action('wpuindexmeta_reindex', 'personne_id');
    do_action('wpuindexmeta_reindex', 'film_id');
}
*/
