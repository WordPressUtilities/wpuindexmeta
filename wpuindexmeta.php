<?php

/*
Plugin Name: WPU Index Meta
Description: Handle indexes for meta values and more
Version: 0.3.0
Plugin URI: https://github.com/WordPressUtilities/wpuindexmeta
Author: Darklg
Author URI: https://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUIndexMeta {
    public function __construct() {
        add_filter('plugins_loaded', array(&$this, 'plugins_loaded'));
    }

    public function plugins_loaded() {
        $this->indexes = $this->validate_indexes(apply_filters('wpuindexmeta__indexes', array()));
        add_action('wpuindexmeta_reindex', array(&$this, 'reindex'), 10, 2);
    }

    /**
     * Init table
     * @param  string $index  index name
     */
    private function recreate_table($index = '') {
        global $wpdb;
        if (!$index || !array_key_exists($index, $this->indexes)) {
            return false;
        }
        $table_name = $this->get_table_name($index);
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        $fields = array();
        if (is_string($this->indexes[$index])) {
            $fields[] = 'meta_value text';
        } else {
            foreach ($this->indexes[$index] as $key => $value) {
                $fields[] = $key . ' text';
            }
        }
        $wpdb->query("CREATE TABLE $table_name (post_id mediumint(9), " . implode(',', $fields) . ")");
    }

    /**
     * Reindex table
     * @param  string $index.   index name
     */
    public function reindex($index = '') {
        global $wpdb;
        if (!$index || !array_key_exists($index, $this->indexes)) {
            return false;
        }

        /* Recreate table */
        $this->recreate_table($index);
        $table_name = $this->get_table_name($index);

        /* Quicker insert if there is only a field */
        if (is_string($this->indexes[$index])) {
            $wpdb->query($wpdb->prepare("INSERT INTO $table_name (post_id, meta_value) SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key=%s", $this->indexes[$index]));
        } else {

            foreach ($this->indexes[$index] as $key => $value) {
                if (isset($value['init_meta_value']) && $value['init_meta_value']) {
                    $query = "INSERT INTO $wpdb->postmeta (`post_id`, `meta_key`, `meta_value`) ";
                    $query .= "SELECT ID,'" . esc_sql($key) . "','' FROM $wpdb->posts ";
                    $query .= "WHERE 1=1 ";
                    if (isset($value['post_type'])) {
                        $query .= "AND post_type='".esc_sql($value['post_type'])."' ";
                    }
                    $query .= "AND ID NOT IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key='" . esc_sql($key) . "' )";
                    $wpdb->query($query);
                }
            }

            /* BUILD QUERY */
            $join = array();
            $index_field = '';
            $insert_fields = array();
            $select_fields = array();
            $conditions_fields = array('1=1');
            foreach ($this->indexes[$index] as $key => $value) {
                $insert_fields[] = $key;
                if ($value['type'] == 'table') {
                    $table_alias = '_' . $key;

                    /* JOIN TABLE */
                    $join_line = "LEFT JOIN $wpdb->prefix" . $value['table'];
                    $join_line .= " as " . $table_alias;
                    $join_line .= " ON $wpdb->postmeta.post_id";
                    $join_line .= " =";
                    $join_line .= " " . $table_alias . "." . $value['id'];
                    $join[] = $join_line;

                    /* ADD FIELD TO SELECT */
                    if ($value['table'] == 'posts') {
                        $select_fields[] = isset($value['custom']) ? $value['custom'] : $table_alias . '.' . $key;
                    } elseif ($value['table'] == 'postmeta') {
                        $conditions_fields[] = "AND $table_alias.meta_key='" . esc_sql($key) . "'";
                        $select_fields[] = $table_alias . '.meta_value';
                    }
                } elseif ($value['type'] == 'index') {

                    /* NAMED INDEX META */
                    $index_field = $key;

                    /* ADD FIELD TO SELECT */
                    $select_fields[] = $wpdb->postmeta . '.meta_value';
                }
            }

            $query = "INSERT INTO $table_name (post_id, " . implode(',', $insert_fields) . ")\n";
            $query .= "SELECT $wpdb->postmeta.post_id, \n" . implode(",\n", $select_fields) . "\n";
            $query .= "FROM $wpdb->postmeta\n";
            $query .= implode("\n", $join) . "\n";
            $query .= "WHERE ";

            $conditions_fields[] = "AND $wpdb->postmeta.meta_key='" . esc_sql($index_field) . "'";

            $query .= implode("\n", $conditions_fields);

            $wpdb->query($query);
        }

    }

    /**
     * Validate & clean indexes obtained from hook
     * @param  array  $indexes Initial indexes
     * @return array           Cleaned indexes
     */
    public function validate_indexes($indexes = array()) {
        $_indexes = array();
        foreach ($indexes as $index => $index_details) {
            /* Only a string : continue */
            if (is_string($index_details)) {
                $_indexes[$index] = $index_details;
                continue;
            }
            /* Invalid format : continue */
            if (!is_array($index_details)) {
                continue;
            }
            $_index_details = array();
            /* Set default values */
            $_has_index = false;
            foreach ($index_details as $index_item => $index_item_value) {
                /* Ensure correct type */
                if (!isset($index_item_value['type'])) {
                    $index_item_value['type'] = 'table';
                }
                if ($index_item_value['type'] == 'index') {
                    $_has_index = true;
                }
                if ($index_item_value['type'] != 'index' && !isset($index_item_value['table'])) {
                    continue;
                }
                /* If using a meta : ensure it exists for every post before indexing */
                if (isset($index_item_value['table']) && $index_item_value['table'] == 'postmeta' && !isset($index_item_value['init_meta_value'])) {
                    $index_item_value['init_meta_value'] = false;
                }

                $_index_details[$index_item] = $index_item_value;
            }

            if (!$_has_index) {
                error_log('[WPUIndexMeta] No index defined for index ' . $index . ' !');
            }

            if (!empty($_index_details) && $_has_index) {
                $_indexes[$index] = $_index_details;
            }
        }

        return $_indexes;
    }

    /**
     * Get table name for a meta key
     * @param  string $index [description]
     * @return [type]           [description]
     */
    private function get_table_name($index = '') {
        global $wpdb;
        if (!$index || !array_key_exists($index, $this->indexes)) {
            return false;
        }
        return $wpdb->prefix . 'wpu_index_meta__' . $index;
    }
}

$WPUIndexMeta = new WPUIndexMeta();

/*
add_filter('wpuindexmeta__indexes', 'demodemo_wpuindexmeta__indexes', 10, 1);
function demodemo_wpuindexmeta__indexes($indexes = array()) {
    # COMPLEX INDEX
    $indexes['personne'] = array(
        # META VALUE INDEX
        'personne_id' => array(
            'type' => 'index'
        ),
        'post_title' => array(
            'id' => 'ID',
            'table' => 'posts'
        ),
        'post_name' => array(
            'id' => 'ID',
            'table' => 'posts'
        ),
        # INDEX A META VALUE WITH INIT IF NOT SET
        '_thumbnail_id' => array(
            'id' => 'post_id',
            'table' => 'postmeta',
            'post_type' => 'personnes',
            'init_meta_value' => true
        )
    );
    # SIMPLE INDEX
    $indexes['film'] = 'film_id';
    return $indexes;
}

# REINDEX
add_action('init', 'demodemo_init');
function demodemo_init() {
    do_action('wpuindexmeta_reindex', 'personne');
    do_action('wpuindexmeta_reindex', 'film');
}
*/
