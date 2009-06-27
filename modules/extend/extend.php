<?php
    foreach (glob(MODULES_DIR."/extend/models/*.php") as $model)
        require $model;

    require "controller.Extend.php";

    /**
     * Extend
     */
    class Extend extends Modules {
        static function __install() {
            $sql = SQL::current();

            $sql->query("CREATE TABLE IF NOT EXISTS __types (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             name VARCHAR(100) DEFAULT '',
                             description TEXT,
                             color VARCHAR(6) DEFAULT 'FFFFFF'
                         ) DEFAULT CHARSET=utf8");

            $sql->query("CREATE TABLE IF NOT EXISTS __extensions (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             name VARCHAR(100) DEFAULT '',
                             clean VARCHAR(100) DEFAULT '',
                             url VARCHAR(100) DEFAULT '',
                             type_id INTEGER DEFAULT 0,
                             user_id INTEGER DEFAULT 0
                         ) DEFAULT CHARSET=utf8");

            $sql->query("CREATE TABLE IF NOT EXISTS __versions (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             number VARCHAR(100) DEFAULT '',
                             description TEXT,
                             compatible TEXT,
                             tags TEXT,
                             filename VARCHAR(100) DEFAULT '',
                             image VARCHAR(100) DEFAULT '',
                             loves INTEGER DEFAULT 0,
                             downloads INTEGER DEFAULT 0,
                             extension_id INTEGER DEFAULT 0,
                             created_at DATETIME DEFAULT '0000-00-00 00:00:00',
                             updated_at DATETIME DEFAULT '0000-00-00 00:00:00'
                         ) DEFAULT CHARSET=utf8");

            $sql->query("CREATE TABLE IF NOT EXISTS __loves (
                             version_id INTEGER DEFAULT 0,
                             user_id INTEGER DEFAULT 0,
                             PRIMARY KEY (version_id, user_id)
                         ) DEFAULT CHARSET=utf8");

            $sql->query("CREATE TABLE IF NOT EXISTS __notes (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             body LONGTEXT,
                             version_id INTEGER DEFAULT 0,
                             user_id INTEGER DEFAULT 0,
                             created_at DATETIME DEFAULT '0000-00-00 00:00:00',
                             updated_at DATETIME DEFAULT '0000-00-00 00:00:00'
                         ) DEFAULT CHARSET=utf8");

            Group::add_permission("add_type", "Add Type");
            Group::add_permission("add_extension", "Add Extension");
            Group::add_permission("add_note", "Add Note");

            Group::add_permission("edit_type", "Edit Type");
            Group::add_permission("edit_extension", "Edit Extension");
            Group::add_permission("edit_note", "Edit Note");
            
            Group::add_permission("edit_own_extension", "Edit Own Extension");
            Group::add_permission("edit_own_note", "Edit Own Note");
            
            Group::add_permission("delete_type", "Delete Type");
            Group::add_permission("delete_extension", "Delete Extension");
            Group::add_permission("delete_note", "Delete Note");
            
            Group::add_permission("delete_own_extension", "Delete Own Extension");
            Group::add_permission("delete_own_note", "Delete Own Note");

            Group::add_permission("code_in_extensions", "Can Use HTML In Extensions");
            Group::add_permission("code_in_notes", "Can Use HTML In Notes");

            Group::add_permission("love_extension", "Love Extensions");
        }

        static function __uninstall($confirm) {
            if ($confirm) {
                SQL::current()->query("DROP TABLE __types");
                SQL::current()->query("DROP TABLE __extensions");
                SQL::current()->query("DROP TABLE __notes");
            }

            Group::remove_permission("add_type");
            Group::remove_permission("add_extension");
            Group::remove_permission("add_note");
            
            Group::remove_permission("edit_type");
            Group::remove_permission("edit_extension");
            Group::remove_permission("edit_note");
            
            Group::remove_permission("edit_own_extension");
            Group::remove_permission("edit_own_note");
            
            Group::remove_permission("delete_type");
            Group::remove_permission("delete_extension");
            Group::remove_permission("delete_note");
            
            Group::remove_permission("delete_own_extension");
            Group::remove_permission("delete_own_note");

            Group::remove_permission("code_in_notes");
        }

        public function admin_head() {
            $config = Config::current();
?>
        <script src="<?php echo $config->chyrp_url; ?>/modules/extend/lib/tablednd.js" type="text/javascript"></script>
        <script src="<?php echo $config->chyrp_url; ?>/modules/extend/admin.js" type="text/javascript"></script>
<?php
        }

        static function manage_nav($navs) {
            $visitor = Visitor::current();
            if (!$visitor->group->can("edit_type", "delete_type"))
                return $navs;

            $navs["manage_types"] = array("title" => __("Types", "extend"),
                                           "selected" => array("new_type", "edit_type", "delete_type"));

            return $navs;
        }

        static function manage_nav_pages($pages) {
            array_push($pages, "manage_types", "new_type", "edit_type", "delete_type", "manage_extensions", "manage_notes");
            return $pages;
        }

        public function admin_manage_types($admin) {
            $visitor = Visitor::current();
            if (!$visitor->group->can("edit_type", "delete_type"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to manage any types.", "comments"));

            $admin->display("manage_types",
                            array("types" => Type::find(array("placeholders" => true))));
        }

        public function admin_new_type($admin) {
            if (!Visitor::current()->group->can("add_type"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add types.", "extend"));

            $admin->display("new_type", array(), __("New Type", "extend"));
        }

        public function admin_add_type() {
            if (!Visitor::current()->group->can("add_type"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add types.", "extend"));

            Type::add($_POST['name'], $_POST['description'], $_POST['color']);
            Flash::notice(__("Type added.", "extend"), "/admin/?action=manage_types");
        }

        public function admin_edit_type($admin) {
            if (!isset($_GET['id']))
                error(__("Error"), __("No type ID specified.", "extend"));

            $type = new Type($_GET['id'], array("filter" => false));
            if ($type->no_results)
                error(__("Error"), __("Invalid type ID specified.", "extend"));

            if (!$type->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this type.", "extend"));

            $admin->display("edit_type",
                            array("type" => $type),
                            _f("Edit Type &#8220;%s&#8221;", array(fix($type->name)), "extend"));
        }

        public function admin_update_type($admin) {
            if (!isset($_POST['type_id']))
                error(__("Error"), __("No type ID specified.", "extend"));

            if (!isset($_POST['hash']) or $_POST['hash'] != Config::current()->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            $type = new Type($_POST['type_id']);
            if ($type->no_results)
                error(__("Error"), __("Invalid type ID specified.", "extend"));

            if (!$type->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this type.", "extend"));

            $type->update($_POST['name'], $_POST['description'], $_POST['color']);

            Flash::notice(__("Type updated.", "extend"), "/admin/?action=manage_types");
        }

        public function admin_delete_type($admin) {
            if (!isset($_GET['id']))
                error(__("Error"), __("No type ID specified.", "extend"));

            $type = new Type($_GET['id']);
            if ($type->no_results)
                error(__("Error"), __("Invalid type ID specified.", "extend"));

            if (!$type->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this type.", "extend"));

            $admin->display("delete_type",
                            array("type" => $type,
                                  "types" => Type::find(array("where" => array("id not" => $type->id)))),
                            _f("Delete Type &#8220;%s&#8221;", array(fix($type->name)), "extend"));
        }

        public function admin_destroy_type() {
            if (!isset($_POST['id']))
                error(__("Error"), __("No type ID specified.", "extend"));

            if (!isset($_POST['hash']) or $_POST['hash'] != Config::current()->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            $type = new Type($_POST['id']);
            if ($type->no_results)
                error(__("Error"), __("Invalid type ID specified.", "extend"));

            if (!$type->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this type.", "extend"));

            foreach ($type->extensions as $extension)
                $extension->update(null, null, $_POST['move_type']);

            Type::delete($type->id);

            Flash::notice(__("Type deleted.", "extend"), "/admin/?action=manage_types");
        }
    }
