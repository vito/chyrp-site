<?php
    foreach (glob(MODULES_DIR."/discuss/models/*.php") as $model)
        require $model;

    require "controller.Discuss.php";

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
                             clean VARCHAR(100) DEFAULT '',
                             url VARCHAR(100) DEFAULT ''
                         ) DEFAULT CHARSET=utf8");

            $sql->query("CREATE TABLE IF NOT EXISTS __messages (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             body TEXT,
                             topic_id INTEGER DEFAULT 0,
                             user_id INTEGER DEFAULT 0,
                             created_at DATETIME DEFAULT '0000-00-00 00:00:00',
                             updated_at DATETIME DEFAULT '0000-00-00 00:00:00'
                         ) DEFAULT CHARSET=utf8");

            $sql->query("CREATE TABLE IF NOT EXISTS __topics (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             title VARCHAR(100) DEFAULT '',
                             description TEXT,
                             clean VARCHAR(100) DEFAULT '',
                             url VARCHAR(100) DEFAULT '',
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

        public function messages_get(&$options) {
            $options["order"] = "created_at ASC, id ASC";
        }

        static function manage_nav($navs) {
            $visitor = Visitor::current();
            if (!$visitor->group->can("edit_forum", "delete_forum"))
                return $navs;

            $navs["manage_forums"] = array("title" => __("Forums", "discuss"),
                                           "selected" => array("edit_forum", "delete_forum"));

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
    }