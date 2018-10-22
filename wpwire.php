<?php
/*
Plugin name: WP Wire
*/

require_once __DIR__ . '/class.wpwire-parser.php';
require_once __DIR__ . '/class.wpwire-select.php';

function wpwire_parse_digit_in_brackets($parser) {
    $parser->skipWhite();
    $parser->parseChar('(');
    $num = $parser->parseInt();
    $parser->parseChar(')');
    return $num;
}

function wpwire_parse_is_unsigned($parser) {
    $parser->skipWhite();
    if ($parser->parse('unsigned')) {
        return true;
    } else {
        return false;
    }
}

function wpwire_parse_field_type($field_type) {
    $parser = new Wpwire_Parser($field_type);
    $meta = array(
        'sql_type' => $field_type
    );
    if ($parser->parseOneOf(array('bigint', 'int', 'mediumint', 'smallint', 'tinyint'))) {
        $meta['quote'] = 'd';
        $meta['len'] = wpwire_parse_digit_in_brackets($parser);
        $meta['unsigned'] = wpwire_parse_is_unsigned($parser);
    } elseif ($parser->parseOneOf(array('varchar', 'char'))) {
        $meta['quote'] = 's';
        $meta['len'] = wpwire_parse_digit_in_brackets($parser);
    } elseif ($parser->parseOneOf(array('text', 'mediumtext', 'longtext', 'tinytext'))) {
        $meta['quote'] = 's';
    } elseif ($parser->parseOneOf(array('datetime', 'date', 'time'))) {
        $meta['quote'] = 's';
    } elseif ($parser->parseOneOf(array('float', 'double', 'decimal'))) {
        $meta['quote'] = 'f';
        $parser->skipWhite();
        $parser->parseChar('(');
        $meta['len'] = $parser->parseInt();
        $parser->parseChar(',');
        $parser->skipWhite();
        $meta['dec'] = $parser->parseInt();
        $parser->parseChar(')');
    } elseif ($parser->parse('timestamp')) {
        $meta['quote'] = 'd';
    } elseif ($parser->parse('year')) {
        $meta['quote'] = 'd';
        $meta['len'] = wpwire_parse_digit_in_brackets($parser);
    } elseif ($parser->parseOneOf(array('blob', 'mediumblob', 'longblob', 'tinyblob'))) {
        $meta['quote'] = 's';
    } elseif ($parser->parse('enum')) {
        $parser->parseChar('(');
        $alts = array();
        while ($parser->pos < $parser->len) {
            $alt = $parser->parseSingleQuoted();
            if ($alt !== false) {
                $alts[] = $alt;
            }
            if (!$parser->parseChar(',')) {
                break;
            }
            $parser->skipWhite();
        }
        $parser->parseChar(')');
    }
    return $meta;
}
require_once __DIR__.'/class.wpwire-zip.php';

function wpwire_zip_uploads(Wpwire_Zip $zip) {
    $zip->addDir(WP_CONTENT_DIR.'/uploads', WP_CONTENT_DIR);
}

function wpwire_zip_theme(Wpwire_Zip $zip) {
    $theme = get_template_directory();
    $zip->addDir($theme, WP_CONTENT_DIR);
}

function wpwire_zip_plugins(Wpwire_Zip $zip) {
    $active = get_option('active_plugins');
    foreach ($active as $pluginFile) {
        if ($pluginFile == 'wpwire/wpwire.php') {
            continue;
        }
        $absPath = WP_CONTENT_DIR.'/plugins/'.$pluginFile;
        if (strpos($pluginFile, '/') !== false) {
            $absPath = dirname($absPath);
            $zip->addDir($absPath, WP_CONTENT_DIR);
        } else {
            $zip->addFile($absPath, WP_CONTENT_DIR);
        }
    }
}

function wpwire_get_db_meta() {
    global $wpdb;
    $tables = $wpdb->get_col("SHOW TABLES LIKE '".$wpdb->esc_like($wpdb->prefix)."%'");
    $meta = array();
    foreach ($tables as $tableName) {
        $tableMeta = array(
            'cols' => array()
        );
        $colsRes = $wpdb->get_results("SHOW COLUMNS FROM `$tableName`");
        foreach ($colsRes as $col) {
            $colMeta = wpwire_parse_field_type($col->Type);
            $colMeta['null'] = ($col->Null != 'NO');
            if ($col->Default === '') {
                $colMeta['default'] = false;
            } elseif ($col->Default === null) {
                $colMeta['default'] = null;
            } else {
                switch ($colMeta['quote']) {
                    case 'd':
                    $colMeta['default'] = (int)$col->Default;
                    break;
                    case 's':
                    $colMeta['default'] = $col->Default;
                    break;
                    case 'f':
                    $colMeta['default'] = (float)$col->Default;
                    break;
                }
            }
            $colMeta['pri'] = ($col->Key == 'PRI');
            $colMeta['uni'] = ($col->Key == 'UNI');
            $colMeta['inc'] = ($col->Extra == 'auto_increment');
            $tableMeta['cols'][$col->Field] = $colMeta;
        }
        $meta[$tableName] = $tableMeta;
    }
    return $meta;
}

function wpwire_gen_sql() {
    global $wpdb;
    $meta = wpwire_get_db_meta();
    $site_url = get_site_url();
    $transfer_url = "http://192.168.33.10";
    $sql = "";
    // Create table statements
    foreach ($meta as $tableName => $tableMeta) {
        $sql .= 'drop table if exists `'.$tableName."`;\n";
        $sql .= 'create table `' . $tableName . "`(\n  ";
        $fieldsSql = array();
        foreach ($tableMeta['cols'] as $colName => $colMeta) {
            $fieldSql = '`' . $colName . '` ' . $colMeta['sql_type'];
            if (!$colMeta['null']) {
                $fieldSql .= ' not null';
            }
            if ($colMeta['default'] !== false) {

            }
            if ($colMeta['pri']) {
                $fieldSql .= ' primary key';
            }
            if ($colMeta['inc']) {
                $fieldSql .= ' auto_increment';
            }
            $fieldsSql[] = $fieldSql;
        }
        $sql .= implode(",\n  ", $fieldsSql);
        $sql .= "\n);\n\n";
    }
    // Insert data statements
    foreach ($meta as $tableName => $tableMeta) {
        $select = new Wpwire_Select($tableName);
        $q = array();
        $n = 0;
        foreach ($tableMeta['cols'] as $colName => $colMeta) {
            $select->cols[] = $colName;
            $q[] = $colMeta['quote'];
            $n++;
        }
        $sql .= "INSERT INTO `$tableName` (\n  ";
        $sql .= implode(",\n  ", $select->cols);
        $sql .= "\n) values\n";
        $records = $wpdb->get_results($select->toSql(), ARRAY_N);
        if (count($records) > 0) {
            $first = true;
            foreach ($records as $record) {
                if ($first) {
                    $first = false;
                } else {
                    $sql .= ",\n";
                }
                $sql .= '(';
                for ($i = 0; $i < $n; $i++) {
                    switch ($q[$i]) {
                        case 'd':
                        $sql .= (int) $record[$i];
                        break;
                        case 's':
                        $sql .= "'".esc_sql(str_replace($site_url, $transfer_url, $record[$i]))."'";
                        break;
                        case 'f':
                        $sql .= (float) $record[$i];
                        break;
                    }
                    if ($i < $n - 1) {
                        $sql .= ', ';
                    }
                }
                $sql .= ")";
            }
            $sql .= ";\n\n";
        }
    }
    // Save to file
    $sqlFile = wpwire_get_temp_dir().'/export.sql';
    if (is_file($sqlFile)) {
        unlink($sqlFile);
    }
    $file = fopen($sqlFile, "w") or die("Unable to open file");
    fwrite($file, $sql);
    fclose($file);
}

function wpwire_get_temp_dir() {
    return __DIR__.'/temp';
}

function wpwire_download_export() {
    if (!is_admin()) {
        die('Not authorized');
    }
    $zipFile = wpwire_get_temp_dir().'/export.zip';
    header("Expires: 0");
    header("Cache-Control: no-cache, no-store, must-revalidate"); 
    header('Cache-Control: pre-check=0, post-check=0, max-age=0', false); 
    header("Pragma: no-cache");	
    header("Content-type: application/zip");
    header("Content-Disposition:attachment; filename=export.zip");
    header("Content-Type: application/force-download");
    readfile($zipFile);
    unlink($zipFile);
    exit();
}

function wpwire_init_download_action() {
    // Bit hacky way to download,
    // Hooking into init and "hijack" the
    // request given a query string
    if (isset($_GET['___wpwire_download']) && is_admin()) {
        wpwire_download_export();
    }
}
add_action('init', 'wpwire_init_download_action');

function wpwire_tool_page() {
    wpwire_gen_sql();
    $tempDir = wpwire_get_temp_dir();
    $zipFile = $tempDir.'/export.zip';
    if (is_file($zipFile)) {
        unlink($zipFile);
    }
    $zip = Wpwire_Zip::create();
    $zip->open($zipFile);
    $zip->addFile($tempDir.'/export.sql', $tempDir);
    wpwire_zip_uploads($zip);
    wpwire_zip_theme($zip);
    wpwire_zip_plugins($zip);
    $zip->close();
    unlink($tempDir.'/export.sql');
    echo "Export completed<br>";
    echo '<a href="'.get_site_url().'/wp-admin/?___wpwire_download=1">Download</a>';
}

function wpwire_admin_menu() {
    add_submenu_page(
        'tools.php',
        'WP Wire',
        'WP Wire',
        'export',
        'wpwire',
        'wpwire_tool_page'
    );
}

add_action('admin_menu', 'wpwire_admin_menu');