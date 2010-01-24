<?php
    header("Content-type: text/html; charset=UTF-8");

    # Make sure E_STRICT is on so Chyrp remains errorless.
    error_reporting(E_ALL | E_STRICT);
    ini_set('display_errors', false);

    ob_start();

    if (version_compare(PHP_VERSION, "5.1.3", "<"))
        exit("Chyrp requires PHP 5.1.3 or greater. Installation cannot continue.");

    $open = opendir(".");
    $numbers = array(0);
    while (false !== ($list = readdir($open))) {
        preg_match("/([0-9]+)/", $list, $number);
        if (!isset($number[1])) continue;
        $numbers[] = $number[1];
    }
    closedir($open);
    
    $num = (max($numbers) + 1);
    
    dircopy("__stock", $num);
    
    $open_timestamp = fopen("./".$num."/CREATED_AT", "w");
    fwrite($open_timestamp, time());
    fclose($open_timestamp);
    
    function dircopy($src_dir, $dst_dir, $verbose = false, $use_cached_dir_trees = false) {    
        static $cached_src_dir;
        static $src_tree; 
        static $dst_tree;
        $num = 0;

        if (($slash = substr($src_dir, -1)) == "\\" || $slash == "/") $src_dir = substr($src_dir, 0, strlen($src_dir) - 1); 
        if (($slash = substr($dst_dir, -1)) == "\\" || $slash == "/") $dst_dir = substr($dst_dir, 0, strlen($dst_dir) - 1);  

        if (!$use_cached_dir_trees || !isset($src_tree) || $cached_src_dir != $src_dir) {
            $src_tree = get_dir_tree($src_dir);
            $cached_src_dir = $src_dir;
            $src_changed = true;  
        }
        
        if (!$use_cached_dir_trees || !isset($dst_tree) || $src_changed)
            $dst_tree = get_dir_tree($dst_dir);
            
        if (!is_dir($dst_dir))
            mkdir($dst_dir, 0777, true);  

        foreach ($src_tree as $file => $src_mtime) {
            if (!isset($dst_tree[$file]) && $src_mtime === false) // dir
                mkdir("$dst_dir/$file"); 
            elseif (!isset($dst_tree[$file]) && $src_mtime || isset($dst_tree[$file]) && $src_mtime > $dst_tree[$file]) {
                if (copy("$src_dir/$file", "$dst_dir/$file")) {
                    if($verbose) echo "Copied '$src_dir/$file' to '$dst_dir/$file'<br>\r\n";
                    touch("$dst_dir/$file", $src_mtime); 
                    $num++; 
                } else 
                    echo "<font color='red'>File '$src_dir/$file' could not be copied!</font><br>\r\n";
            }        
        }

        return $num; 
    }

    function get_dir_tree($dir, $root = true)  {
        static $tree;
        static $base_dir_length; 

        if ($root) { 
            $tree = array();  
            $base_dir_length = strlen($dir) + 1;  
        }

        if (is_file($dir)) {
            $tree[substr($dir, $base_dir_length)] = filemtime($dir); 
        } elseif (is_dir($dir) && $di = dir($dir)) {
            if (!$root) $tree[substr($dir, $base_dir_length)] = false;  
            while (($file = $di->read()) !== false) 
                if ($file != "." && $file != "..")
                    get_dir_tree("$dir/$file", false);  
            $di->close(); 
        }

        if ($root)
            return $tree;     
    }

    define('DEBUG',        true);
    define('JAVASCRIPT',   false);
    define('ADMIN',        false);
    define('AJAX',         false);
    define('XML_RPC',      false);
    define('TRACKBACK',    false);
    define('UPGRADING',    false);
    define('INSTALLING',   true);
    define('TESTER',       isset($_SERVER['HTTP_USER_AGENT']) and $_SERVER['HTTP_USER_AGENT'] == "tester.rb");
    define('MAIN_DIR',     dirname(__FILE__)."/".$num);
    define('INCLUDES_DIR', MAIN_DIR."/includes");
    define('USE_ZLIB',     false);

    # Make sure E_STRICT is on so Chyrp remains errorless.
    error_reporting(E_ALL | E_STRICT);
    ini_set('display_errors', true);

    ob_start();

    if (version_compare(PHP_VERSION, "5.1.3", "<"))
        exit("Chyrp requires PHP 5.1.3 or greater. Installation cannot continue.");

    require_once INCLUDES_DIR."/helpers.php";

    require_once INCLUDES_DIR."/lib/gettext/gettext.php";
    require_once INCLUDES_DIR."/lib/gettext/streams.php";
    require_once INCLUDES_DIR."/lib/YAML.php";
    require_once INCLUDES_DIR."/lib/PasswordHash.php";

    require_once INCLUDES_DIR."/class/Config.php";
    require_once INCLUDES_DIR."/class/SQL.php";
    require_once INCLUDES_DIR."/class/Model.php";

    require_once INCLUDES_DIR."/model/User.php";

    # Prepare the Config interface.
    $config = Config::current();

    # Atlantic/Reykjavik is 0 offset. Set it so the timezones() function is
    # always accurate, even if the server has its own timezone settings.
    $default_timezone = oneof(ini_get("date.timezone"), "Atlantic/Reykjavik");
    set_timezone($default_timezone);

    # Sanitize all input depending on magic_quotes_gpc's enabled status.
    sanitize_input($_GET);
    sanitize_input($_POST);
    sanitize_input($_COOKIE);
    sanitize_input($_REQUEST);

    $url = "http://chyrp.net/demos/".$num;
    $index = (parse_url($url, PHP_URL_PATH)) ? "/".trim(parse_url($url, PHP_URL_PATH), "/")."/" : "/" ;
    $htaccess = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase {$index}\nRewriteCond %{REQUEST_FILENAME} !-f\n".
                "RewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^.+$ index.php [L]\n</IfModule>";

    $path = preg_quote($index, "/");
    $htaccess_has_chyrp = (file_exists(MAIN_DIR."/.htaccess") and
                           preg_match("/<IfModule mod_rewrite\.c>\n([\s]*)RewriteEngine On\n([\s]*)RewriteBase {$path}\n".
                                      "([\s]*)RewriteCond %\{REQUEST_FILENAME\} !-f\n([\s]*)RewriteCond %\{REQUEST_FILENAME\}".
                                      " !-d\n([\s]*)RewriteRule \^\.\+\\$ index\.php \[L\]\n([\s]*)<\/IfModule>/",
                                      file_get_contents(MAIN_DIR."/.htaccess")));

    $errors = array();
    $installed = false;

    if (file_exists(INCLUDES_DIR."/config.yaml.php") and file_exists(MAIN_DIR."/.htaccess")) {
        $sql = SQL::current(true);
        if ($sql->connect(true) and !empty($config->url) and $sql->count("users"))
            error(__("Already Installed"), __("Chyrp is already correctly installed and configured."));
    }

    if ((!is_writable(MAIN_DIR) and !file_exists(MAIN_DIR."/.htaccess")) or
        (file_exists(MAIN_DIR."/.htaccess") and !is_writable(MAIN_DIR."/.htaccess") and !$htaccess_has_chyrp))
        $errors[] = _f("STOP! Before you go any further, you must create a .htaccess file in Chyrp's install directory and put this in it:\n<pre>%s</pre>", array(fix($htaccess)));

    if (!is_writable(INCLUDES_DIR))
        $errors[] = __("Chyrp's includes directory is not writable by the server. In order for the installer to generate your configuration files, please CHMOD or CHOWN it so that Chyrp can write to it.");

    $settings = array();
    $settings['host'] = "localhost";
    $settings['username'] = "";
    $settings['password'] = "";
    $settings['database'] = "/srv/db/demos/demo".$num.".db";
    $settings['prefix'] = "";
    $settings['adapter'] = "sqlite";
    $settings['name'] = "Chyrp Demo";
    $settings['description'] = "";
    $settings['timezone'] = "America/New_York";
    $settings['login'] = "Admin";
    $settings['password_1'] = "admin";
    $settings['password_2'] = "admin";
    $settings['email'] = "test@example.com";

    echo "<pre>";
    if (!empty($settings)) {
        if ($settings['adapter'] == "sqlite" and !@is_writable(dirname($settings['database'])))
            $errors[] = __("SQLite database file could not be created. Please make sure your server has write permissions to the location for the database.");
        else {
            $sql = SQL::current(array("host" => $settings['host'],
                                      "username" => $settings['username'],
                                      "password" => $settings['password'],
                                      "database" => $settings['database'],
                                      "prefix" => $settings['prefix'],
                                      "adapter" => $settings['adapter']));

            if (!$sql->connect(true))
                $errors[] = _f("Could not connect to the specified database:\n<pre>%s</pre>", array($sql->error));
            elseif ($settings['adapter'] == "pgsql") {
                new Query($sql, "CREATE FUNCTION year(timestamp) RETURNS double precision AS 'select extract(year from $1);' LANGUAGE SQL IMMUTABLE RETURNS NULL ON NULL INPUT");
                new Query($sql, "CREATE FUNCTION month(timestamp) RETURNS double precision AS 'select extract(month from $1);' LANGUAGE SQL IMMUTABLE RETURNS NULL ON NULL INPUT");
                new Query($sql, "CREATE FUNCTION day(timestamp) RETURNS double precision AS 'select extract(day from $1);' LANGUAGE SQL IMMUTABLE RETURNS NULL ON NULL INPUT");
                new Query($sql, "CREATE FUNCTION hour(timestamp) RETURNS double precision AS 'select extract(hour from $1);' LANGUAGE SQL IMMUTABLE RETURNS NULL ON NULL INPUT");
                new Query($sql, "CREATE FUNCTION minute(timestamp) RETURNS double precision AS 'select extract(minute from $1);' LANGUAGE SQL IMMUTABLE RETURNS NULL ON NULL INPUT");
                new Query($sql, "CREATE FUNCTION second(timestamp) RETURNS double precision AS 'select extract(second from $1);' LANGUAGE SQL IMMUTABLE RETURNS NULL ON NULL INPUT");
            }
        }

        if (empty($settings['name']))
            $errors[] = __("Please enter a name for your website.");

        if (!isset($settings['timezone']))
            $errors[] = __("Time zone cannot be blank.");

        if (empty($settings['login']))
            $errors[] = __("Please enter a username for your account.");

        if (empty($settings['password_1']))
            $errors[] = __("Password cannot be blank.");

        if ($settings['password_1'] != $settings['password_2'])
            $errors[] = __("Passwords do not match.");

        if (empty($settings['email']))
            $errors[] = __("E-Mail address cannot be blank.");

        if (empty($errors)) {
            if (!$htaccess_has_chyrp)
                if (!file_exists(MAIN_DIR."/.htaccess")) {
                    if (!@file_put_contents(MAIN_DIR."/.htaccess", $htaccess))
                        $errors[] = _f("Could not generate .htaccess file. Clean URLs will not be available unless you create it and put this in it:\n<pre>%s</pre>", array(fix($htaccess)));
                } elseif (!@file_put_contents(MAIN_DIR."/.htaccess", "\n\n".$htaccess, FILE_APPEND)) {
                    $errors[] = _f("Could not generate .htaccess file. Clean URLs will not be available unless you create it and put this in it:\n<pre>%s</pre>", array(fix($htaccess)));
                }

            $config->set("sql", array());
            $config->set("name", $settings['name']);
            $config->set("description", $settings['description']);
            $config->set("url", $url);
            $config->set("chyrp_url", $url);
            $config->set("feed_url", "");
            $config->set("email", $settings['email']);
            $config->set("locale", "en_US");
            $config->set("theme", "stardust");
            $config->set("posts_per_page", 5);
            $config->set("feed_items", 20);
            $config->set("clean_urls", false);
            $config->set("post_url", "(year)/(month)/(day)/(url)/");
            $config->set("timezone", $settings['timezone']);
            $config->set("can_register", true);
            $config->set("default_group", 0);
            $config->set("guest_group", 0);
            $config->set("enable_trackbacking", true);
            $config->set("send_pingbacks", false);
            $config->set("enable_xmlrpc", true);
            $config->set("enable_ajax", true);
            $config->set("uploads_path", "/uploads/");
            $config->set("enabled_modules", array("markdown", "smartypants"));
            $config->set("enabled_feathers", array("text"));
            $config->set("routes", array());
            $config->set("secure_hashkey", md5(random(32, true)));

            foreach (array("host", "username", "password", "database", "prefix", "adapter") as $field)
                $sql->set($field, $settings[$field], true);

            if ($sql->adapter == "mysql" and class_exists("MySQLi"))
                $sql->method = "mysqli";
            elseif ($sql->adapter == "mysql" and function_exists("mysql_connect"))
                $sql->method = "mysql";
            elseif ($sql->adapter == "sqlite" and in_array("sqlite", PDO::getAvailableDrivers()))
                $sql->method = "pdo";

            $sql->connect();

            # Posts table
            $sql->query("CREATE TABLE IF NOT EXISTS __posts (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             feather VARCHAR(32) DEFAULT '',
                             clean VARCHAR(128) DEFAULT '',
                             url VARCHAR(128) DEFAULT '',
                             pinned BOOLEAN DEFAULT FALSE,
                             status VARCHAR(32) DEFAULT 'public',
                             user_id INTEGER DEFAULT 0,
                             created_at DATETIME DEFAULT NULL,
                             updated_at DATETIME DEFAULT NULL
                         ) DEFAULT CHARSET=utf8");

            # Post attributes table.
            $sql->query("CREATE TABLE IF NOT EXISTS __post_attributes (
                             post_id INTEGER NOT NULL ,
                             name VARCHAR(100) DEFAULT '',
                             value LONGTEXT,
                             PRIMARY KEY (post_id, name)
                         ) DEFAULT CHARSET=utf8");

            # Pages table
            $sql->query("CREATE TABLE IF NOT EXISTS __pages (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             title VARCHAR(250) DEFAULT '',
                             body LONGTEXT,
                             show_in_list BOOLEAN DEFAULT '1',
                             list_order INTEGER DEFAULT 0,
                             clean VARCHAR(128) DEFAULT '',
                             url VARCHAR(128) DEFAULT '',
                             user_id INTEGER DEFAULT 0,
                             parent_id INTEGER DEFAULT 0,
                             created_at DATETIME DEFAULT NULL,
                             updated_at DATETIME DEFAULT NULL
                         ) DEFAULT CHARSET=utf8");

            # Users table
            $sql->query("CREATE TABLE IF NOT EXISTS __users (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             login VARCHAR(64) DEFAULT '',
                             password VARCHAR(60) DEFAULT '',
                             full_name VARCHAR(250) DEFAULT '',
                             email VARCHAR(128) DEFAULT '',
                             website VARCHAR(128) DEFAULT '',
                             group_id INTEGER DEFAULT 0,
                             joined_at DATETIME DEFAULT NULL,
                             UNIQUE (login)
                         ) DEFAULT CHARSET=utf8");

            # Groups table
            $sql->query("CREATE TABLE IF NOT EXISTS __groups (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             name VARCHAR(100) DEFAULT '',
                             UNIQUE (name)
                         ) DEFAULT CHARSET=utf8");

            # Permissions table
            $sql->query("CREATE TABLE IF NOT EXISTS __permissions (
                             id VARCHAR(100) DEFAULT '',
                             name VARCHAR(100) DEFAULT '',
                             group_id INTEGER DEFAULT 0,
                             PRIMARY KEY (id, group_id)
                         ) DEFAULT CHARSET=utf8");

            # Sessions table
            $sql->query("CREATE TABLE IF NOT EXISTS __sessions (
                             id VARCHAR(40) DEFAULT '',
                             data LONGTEXT,
                             user_id INTEGER DEFAULT 0,
                             created_at DATETIME DEFAULT '0000-00-00 00:00:00',
                             updated_at DATETIME DEFAULT '0000-00-00 00:00:00',
                             PRIMARY KEY (id)
                         ) DEFAULT CHARSET=utf8");

            $names = array("change_settings" => "Change Settings",
                           "toggle_extensions" => "Toggle Extensions",
                           "view_site" => "View Site",
                           "view_private" => "View Private Posts",
                           "view_draft" => "View Drafts",
                           "view_own_draft" => "View Own Drafts",
                           "add_post" => "Add Posts",
                           "add_draft" => "Add Drafts",
                           "edit_post" => "Edit Posts",
                           "edit_draft" => "Edit Drafts",
                           "edit_own_post" => "Edit Own Posts",
                           "edit_own_draft" => "Edit Own Drafts",
                           "delete_post" => "Delete Posts",
                           "delete_draft" => "Delete Drafts",
                           "delete_own_post" => "Delete Own Posts",
                           "delete_own_draft" => "Delete Own Drafts",
                           "add_page" => "Add Pages",
                           "edit_page" => "Edit Pages",
                           "delete_page" => "Delete Pages",
                           "add_user" => "Add Users",
                           "edit_user" => "Edit Users",
                           "delete_user" => "Delete Users",
                           "add_group" => "Add Groups",
                           "edit_group" => "Edit Groups",
                           "delete_group" => "Delete Groups");

            foreach ($names as $id => $name)
                $sql->replace("permissions",
                              array("id", "group_id"),
                              array("id" => $id,
                                    "name" => $name,
                                    "group_id" => 0));

            $groups = array("admin" => array_keys($names),
                            "member" => array("view_site"),
                            "friend" => array("view_site", "view_private"),
                            "banned" => array(),
                            "guest" => array("view_site"));

            # Insert the default groups (see above)
            $group_id = array();
            foreach($groups as $name => $permissions) {
                $sql->replace("groups", "name", array("name" => ucfirst($name)));

                $group_id[$name] = $sql->latest("groups");

                foreach ($permissions as $permission)
                    $sql->replace("permissions",
                                  array("id", "group_id"),
                                  array("id" => $permission,
                                        "name" => $names[$permission],
                                        "group_id" => $group_id[$name]));
            }

            $config->set("default_group", $group_id["member"]);
            $config->set("guest_group", $group_id["guest"]);

            if (!$sql->select("users", "id", array("login" => $settings['login']))->fetchColumn())
                $sql->insert("users",
                             array("login" => $settings['login'],
                                   "password" => User::hashPassword($settings['password_1']),
                                   "email" => $settings['email'],
                                   "website" => $config->url,
                                   "group_id" => $group_id["admin"],
                                   "joined_at" => datetime()));

            $sql->insert(
                "posts",
                array(
                    "feather" => "text",
                    "clean" => "welcome",
                    "url" => "welcome",
                    "pinned" => 0,
                    "status" => "public",
                    "user_id" => 1,
                    "created_at" => datetime(),
                    "updated_at" => NULL
                )
            );
            $sql->insert(
                "post_attributes",
                array(
                    "post_id" => 1,
                    "name" => "title",
                    "value" => "Welcome!"
                )
            );
            $sql->insert(
                "post_attributes",
                array(
                    "post_id" => 1,
                    "name" => "body",
                    "value" => "Welcome to your own personal, temporary Chyrp demo! This installation will be deleted after 30 minutes. To begin, log in with the username \"Admin\" and the password \"admin\".\n\nHave fun!\n\nP.S. Don't forget to enable some modules and feathers."
                )
            );

            $installed = true;
        }
    }

    if ($installed)
        header("Location: http://chyrp.net/demos/".$num);
    else {
        echo "Sorry, for some reason the demo could not be created.\n";
        var_dump($errors);
    }
