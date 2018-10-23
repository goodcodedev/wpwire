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
            $colMeta['default_sql'] = $col->Default;
            if ($col->Default === null) {
                // feels like this could be brittle,
                // depending here on this being separated from
                // empty string
                $colMeta['default'] = false;
            } elseif ($col->Default == 'null') {
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
            // todo: Figure out "Extra" format, possibly space separated
            $colMeta['default_expr'] = ($col->Extra == 'DEFAULT_GENERATED');
            $tableMeta['cols'][$col->Field] = $colMeta;
        }
        // Keys
        $keysRes = $wpdb->get_results("SHOW INDEX FROM `$tableName`");
        $primaryKeys = array();
        $keys = array();
        foreach ($keysRes as $key) {
            if ($key->Key_name == 'PRIMARY') {
                $primaryKeys[] = $key->Column_name;
            } else {
                if (!isset($keys[$key->Key_name])) {
                    $keys[$key->Key_name] = array(
                        'unique' => ($key->Non_unique != '1'),
                        'cols' => array()
                    );
                }
                $keys[$key->Key_name]['cols'][] = $key->Column_name;
            }
        }
        $tableMeta['primary'] = $primaryKeys;
        $tableMeta['keys'] = $keys;
        $meta[$tableName] = $tableMeta;
    }
    return $meta;
}

require_once __DIR__.'/class.wpwire-replace-unserialized.php';

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
                $fieldSql .= ' default ';
                if ($colMeta['default'] === null) {
                    $fieldSql .= 'null';
                } elseif ($colMeta['default_expr']) {
                    $fieldSql .= $colMeta['default_sql'];
                } else {
                    switch ($colMeta['quote']) {
                        case 'd':
                        case 'f':
                        $fieldSql .= $colMeta['default_sql'];
                        break;
                        case 's':
                        $fieldSql .= "'".esc_sql($colMeta['default_sql'])."'";
                        break;
                    }
                }
            }
            if ($colMeta['inc']) {
                $fieldSql .= ' auto_increment';
            }
            $fieldsSql[] = $fieldSql;
        }
        // Add keys
        if (count($tableMeta['primary']) > 0) {
            $fieldsSql[] = 'PRIMARY KEY ('.implode(',', $tableMeta['primary']).')';
        }
        foreach ($tableMeta['keys'] as $keyName => $key) {
            $keySql = ($key['unique']) ? "UNIQUE KEY " : "KEY ";
            $keySql .= $keyName.' ('.implode(',', $key['cols']).')';
            $fieldsSql[] = $keySql;
        }
        $sql .= implode(",\n  ", $fieldsSql);
        $sql .= "\n) DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;\n\n";
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
        $records = $wpdb->get_results($select->toSql(), ARRAY_N);
        if (count($records) > 0) {
            $sql .= "INSERT INTO `$tableName` (\n  ";
            $sql .= implode(",\n  ", $select->cols);
            $sql .= "\n) values\n";
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
                        // Text values might be serialized php values.
                        // Site url's that needs to be replaced might be contained in those,
                        // and a simple replace will mess with the encoding.
                        // So we attempt to treat all text values as serialized values,
                        // which will probably mess up rarely.
                        // TODO: Switch to safe php implementation.
                        if (is_serialized($record[$i])) {
                            $unserialized = @unserialize($record[$i]);
                            if ($unserialized === false) {
                                $sql .= "'".esc_sql(str_replace($site_url, $transfer_url, $record[$i]))."'";
                            } else {
                                // We have unserialized
                                $replacer = new Wpwire_Replace_Unserialized($site_url, $transfer_url);
                                $replaced = $replacer->poly($unserialized);
                                $sql .= "'".esc_sql(serialize($replaced))."'";
                            }
                        } else {
                            $sql .= "'".esc_sql(str_replace($site_url, $transfer_url, $record[$i]))."'";
                        }
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
    header("Content-Length: ".filesize($zipFile));
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
    $genSql = isset($_GET['gen_sql']);
    $zipUploads = isset($_GET['zip_uploads']);
    $zipThemes = isset($_GET['zip_themes']);
    $zipPlugins = isset($_GET['zip_plugins']);
    $pluginDirs = array();
    $zipPluginDirs = array();
    foreach (glob(WP_CONTENT_DIR.'/plugins/*', GLOB_ONLYDIR) as $pluginDir) {
        $baseName = basename($pluginDir);
        if ($baseName == 'wpwire') {
            continue;
        }
        $pluginDirs[] = $baseName;
        if (isset($_GET["plugin-$baseName"])) {
            $zipPluginDirs[] = $pluginDir;
        }
    }
    if ($genSql) {
        wpwire_gen_sql();
    }
    $tempDir = wpwire_get_temp_dir();
    $zipFile = $tempDir.'/export.zip';
    if (is_file($zipFile)) {
        unlink($zipFile);
    }
    $zip = Wpwire_Zip::create();
    $zip->open($zipFile);
    if ($genSql) {
        $zip->addFile($tempDir.'/export.sql', $tempDir);
    }
    if ($zipUploads) {
        wpwire_zip_uploads($zip);
    }
    if ($zipThemes) {
        wpwire_zip_theme($zip);
    }
    if ($zipPlugins) {
        wpwire_zip_plugins($zip);
    }
    // Plugin dirs
    foreach ($zipPluginDirs as $pluginDir) {
        $zip->addDir($pluginDir, WP_CONTENT_DIR);
    }
    $zip->close();
    if ($genSql) {
        unlink($tempDir.'/export.sql');
    }
    ?>
    <form method="get" action="<?php echo esc_url(admin_url('tools.php'));?>">
    <input type="hidden" name="page" value="wpwire">
    <!-- Generate sql -->
    <input type="checkbox" id="gen_sql" name="gen_sql" value="1" <?php if ($genSql) { echo "checked"; }?>>
    <label for="gen_sql">Generate sql</label><br>
    <!-- Zip uploads -->
    <input type="checkbox" id="zip_uploads" name="zip_uploads" value="1" <?php if ($zipUploads) { echo "checked"; }?>>
    <label for="zip_uploads">Uploads</label><br>
    <!-- Zip themes -->
    <input type="checkbox" id="zip_themes" name="zip_themes" value="1" <?php if ($zipThemes) { echo "checked"; }?>>
    <label for="zip_themes">Active theme</label><br>
    <!-- Zip plugins -->
    <input type="checkbox" id="zip_plugins" name="zip_plugins" value="1" <?php if ($zipPlugins) { echo "checked"; }?>>
    <label for="zip_plugins">Active plugins</label><br>
    <!-- List plugin directories -->
    <h2>Plugin directories</h2>
    <?php foreach ($pluginDirs as $pluginDir): ?>
        <input type="checkbox"
            id="plugin-<?php echo $pluginDir?>"
            name="plugin-<?php echo $pluginDir?>" value="1" <?php if (isset($_GET["plugin-$pluginDir"])) { echo "checked"; }?>>
        <label for="plugin-<?php echo $pluginDir?>"><?php echo $pluginDir;?></label><br>
    <?php endforeach;?>
    <input type="submit" value="Export">
    </form>
    <?php
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