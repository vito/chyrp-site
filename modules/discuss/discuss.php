<?php
    foreach (glob(MODULES_DIR."/discuss/models/*.php") as $model)
        require $model;

    require "controller.Discuss.php";

        # public function __init() {
            # $this->addAlias("user_grab", "users_get");
        # }

    /**
     * Discuss
     */
    class Discuss extends Modules {
        static function __install() {
            $sql = SQL::current();

            $sql->query("CREATE TABLE IF NOT EXISTS __forums (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             name VARCHAR(100) DEFAULT '',
                             description TEXT,
                             `order` INTEGER DEFAULT 0,
                             clean VARCHAR(100) DEFAULT '',
                             url VARCHAR(100) DEFAULT ''
                         ) DEFAULT CHARSET=utf8");

            $sql->query("CREATE TABLE IF NOT EXISTS __messages (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             body LONGTEXT,
                             topic_id INTEGER DEFAULT 0,
                             user_id INTEGER DEFAULT 0,
                             created_at DATETIME DEFAULT '0000-00-00 00:00:00',
                             updated_at DATETIME DEFAULT '0000-00-00 00:00:00'
                         ) DEFAULT CHARSET=utf8");

            $sql->query("CREATE TABLE IF NOT EXISTS __topics (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             title VARCHAR(100) DEFAULT '',
                             description LONGTEXT,
                             clean VARCHAR(100) DEFAULT '',
                             url VARCHAR(100) DEFAULT '',
                             view_count INTEGER DEFAULT 0,
                             forum_id INTEGER DEFAULT 0,
                             user_id INTEGER DEFAULT 0,
                             created_at DATETIME DEFAULT '0000-00-00 00:00:00',
                             updated_at DATETIME DEFAULT '0000-00-00 00:00:00'
                         ) DEFAULT CHARSET=utf8");

            Group::add_permission("add_forum", "Add Forum");
            Group::add_permission("add_topic", "Add Topic");
            Group::add_permission("add_message", "Add Message");
            
            Group::add_permission("edit_forum", "Edit Forum");
            Group::add_permission("edit_topic", "Edit Topic");
            Group::add_permission("edit_message", "Edit Message");
            
            Group::add_permission("edit_own_topic", "Edit Own Topic");
            Group::add_permission("edit_own_message", "Edit Own Message");
            
            Group::add_permission("delete_forum", "Delete Forum");
            Group::add_permission("delete_topic", "Delete Topic");
            Group::add_permission("delete_message", "Delete Message");
            
            Group::add_permission("delete_own_topic", "Delete Own Topic");
            Group::add_permission("delete_own_message", "Delete Own Message");

            Group::add_permission("code_in_messages", "Can Use HTML In Messages");
        }

        static function __uninstall($confirm) {
            if ($confirm) {
                SQL::current()->query("DROP TABLE __forums");
                SQL::current()->query("DROP TABLE __topics");
                SQL::current()->query("DROP TABLE __messages");
            }

            Group::remove_permission("add_forum");
            Group::remove_permission("add_topic");
            Group::remove_permission("add_message");
            
            Group::remove_permission("edit_forum");
            Group::remove_permission("edit_topic");
            Group::remove_permission("edit_message");
            
            Group::remove_permission("edit_own_topic");
            Group::remove_permission("edit_own_message");
            
            Group::remove_permission("delete_forum");
            Group::remove_permission("delete_topic");
            Group::remove_permission("delete_message");
            
            Group::remove_permission("delete_own_topic");
            Group::remove_permission("delete_own_message");

            Group::remove_permission("code_in_messages");
        }

        public function admin_head() {
            $config = Config::current();
?>
        <script src="<?php echo $config->chyrp_url; ?>/modules/discuss/lib/tablednd.js" type="text/javascript"></script>
        <script src="<?php echo $config->chyrp_url; ?>/modules/discuss/admin.js" type="text/javascript"></script>
<?php
        }

        public function ajax() {
            if ($_POST['action'] != "reorder_forums")
                return;

            foreach (explode(",", $_POST['order']) as $order => $id) {
                $forum = new Forum($id, array("filter" => false));
                $forum->update(null, null, $order);
            }
        }

        static function manage_nav($navs) {
            $visitor = Visitor::current();
            if (!$visitor->group->can("edit_forum", "delete_forum"))
                return $navs;

            $navs["manage_forums"] = array("title" => __("Forums", "discuss"),
                                           "selected" => array("new_forum", "edit_forum", "delete_forum"));

            return $navs;
        }

        static function manage_nav_pages($pages) {
            array_push($pages, "manage_forums", "new_forum", "edit_forum", "delete_forum", "manage_topics", "manage_messages");
            return $pages;
        }

        public function admin_manage_forums($admin) {
            $visitor = Visitor::current();
            if (!$visitor->group->can("edit_forum", "delete_forum"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to manage any forums.", "comments"));

            $admin->display("manage_forums",
                            array("forums" => Forum::find(array("placeholders" => true))));
        }

        public function admin_new_forum($admin) {
            if (!Visitor::current()->group->can("add_forum"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add forums.", "discuss"));

            $admin->display("new_forum", array(), __("New Forum", "discuss"));
        }

        public function admin_add_forum() {
            if (!Visitor::current()->group->can("add_forum"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add forums.", "discuss"));

            Forum::add($_POST['name'], $_POST['description']);
            Flash::notice(__("Forum added.", "discuss"), "/admin/?action=manage_forums");
        }

        public function admin_edit_forum($admin) {
            if (!isset($_GET['id']))
                error(__("Error"), __("No forum ID specified.", "discuss"));

            $forum = new Forum($_GET['id'], array("filter" => false));
            if ($forum->no_results)
                error(__("Error"), __("Invalid forum ID specified.", "discuss"));

            if (!$forum->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this forum.", "discuss"));

            $admin->display("edit_forum",
                            array("forum" => $forum),
                            _f("Edit Forum &#8220;%s&#8221;", array(fix($forum->name)), "discuss"));
        }

        public function admin_update_forum($admin) {
            if (!isset($_POST['forum_id']))
                error(__("Error"), __("No forum ID specified.", "discuss"));

            if (!isset($_POST['hash']) or $_POST['hash'] != Config::current()->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            $forum = new Forum($_POST['forum_id']);
            if ($forum->no_results)
                error(__("Error"), __("Invalid forum ID specified.", "discuss"));

            if (!$forum->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this forum.", "discuss"));

            $forum->update($_POST['name'], $_POST['description']);

            Flash::notice(__("Forum updated.", "discuss"), "/admin/?action=manage_forums");
        }

        public function admin_delete_forum($admin) {
            if (!isset($_GET['id']))
                error(__("Error"), __("No forum ID specified.", "discuss"));

            $forum = new Forum($_GET['id']);
            if ($forum->no_results)
                error(__("Error"), __("Invalid forum ID specified.", "discuss"));

            if (!$forum->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this forum.", "discuss"));

            $admin->display("delete_forum",
                            array("forum" => $forum,
                                  "forums" => Forum::find(array("where" => array("id not" => $forum->id)))),
                            _f("Delete Forum &#8220;%s&#8221;", array(fix($forum->name)), "discuss"));
        }

        public function admin_destroy_forum() {
            if (!isset($_POST['id']))
                error(__("Error"), __("No forum ID specified.", "discuss"));

            if (!isset($_POST['hash']) or $_POST['hash'] != Config::current()->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            $forum = new Forum($_POST['id']);
            if ($forum->no_results)
                error(__("Error"), __("Invalid forum ID specified.", "discuss"));

            if (!$forum->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this forum.", "discuss"));

            foreach ($forum->topics as $topic)
                $topic->update(null, null, $_POST['move_forum']);

            Forum::delete($forum->id);

            Flash::notice(__("Forum deleted.", "discuss"), "/admin/?action=manage_forums");
        }

        public function user_post_count_attr(&$attr, $user) {
            if (isset($this->post_counts[$user->id]))
                return $attr = $this->post_counts[$user->id];

            $counts = SQL::current()->select("messages",
                                             array("user_id", "COUNT(1) AS count"),
                                             null,
                                             null,
                                             array(),
                                             null,
                                             null,
                                             "user_id")->fetchAll();

            foreach ($counts as $row)
                $this->post_counts[$row["user_id"]] = $row["count"];

        	return $attr = $this->post_counts[$user->id];
        }

        public function user_grab(&$options) {
            $options["select"][] = "COUNT(posts.id) AS `post_count`";
        	$options["left_join"][] = array("table" => "posts", "where" => array("users.id = posts.user_id"));
        }
    }
