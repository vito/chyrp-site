<?php
    foreach (glob(MODULES_DIR."/bbs/models/*.php") as $model)
        require $model;

    require "controller.BBS.php";

    /**
     * BBS
     */
    class BBS extends Modules {
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

            Group::add_permission("add_forum", __("Add Forum"));
            Group::add_permission("add_topic", __("Add Topic"));
            Group::add_permission("add_message", __("Add Message"));
            
            Group::add_permission("edit_forum", __("Edit Forum"));
            Group::add_permission("edit_topic", __("Edit Topic"));
            Group::add_permission("edit_message", __("Edit Message"));
            
            Group::add_permission("edit_own_topic", __("Edit Own Topic"));
            Group::add_permission("edit_own_message", __("Edit Own Message"));
            
            Group::add_permission("delete_forum", __("Delete Forum"));
            Group::add_permission("delete_topic", __("Delete Topic"));
            Group::add_permission("delete_message", __("Delete Message"));
            
            Group::add_permission("delete_own_topic", __("Delete Own Topic"));
            Group::add_permission("delete_own_message", __("Delete Own Message"));
        }

        static function __uninstall($confirm) {
            if ($confirm) {
                SQL::current()->query("DROP TABLE __forums");
                SQL::current()->query("DROP TABLE __topics");
                SQL::current()->query("DROP TABLE __messages");
            }

            Group::remove_permission("add_forum", __("Add Forum"));
            Group::remove_permission("add_topic", __("Add Topic"));
            Group::remove_permission("add_message", __("Add Message"));
            
            Group::remove_permission("edit_forum", __("Edit Forum"));
            Group::remove_permission("edit_topic", __("Edit Topic"));
            Group::remove_permission("edit_message", __("Edit Message"));
            
            Group::remove_permission("edit_own_topic", __("Edit Own Topic"));
            Group::remove_permission("edit_own_message", __("Edit Own Message"));
            
            Group::remove_permission("delete_forum", __("Delete Forum"));
            Group::remove_permission("delete_topic", __("Delete Topic"));
            Group::remove_permission("delete_message", __("Delete Message"));
            
            Group::remove_permission("delete_own_topic", __("Delete Own Topic"));
            Group::remove_permission("delete_own_message", __("Delete Own Message"));
        }

        public function bbs_add_message() {
            $message = Message::add($_POST['body'], $_POST['topic_id']);
            Flash::notice(__("Message added.", "bbs"), $message->topic()->url());
        }

        public function bbs_add_topic() {
            $topic = Topic::add($_POST['title'], $_POST['description'], $_POST['forum_id']);
            Flash::notice(__("Topic added.", "bbs"), $topic->url());
        }
    }