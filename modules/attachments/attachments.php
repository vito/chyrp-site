<?php
    require "model.Attachment.php";

    class Attachments extends Modules {
        static function __install() {
            $sql = SQL::current();
            $sql->query("CREATE TABLE IF NOT EXISTS __attachments (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             filename VARCHAR(100) DEFAULT '',
                             path VARCHAR(100) DEFAULT '',
                             entity_type VARCHAR(100) DEFAULT '',
                             entity_id INTEGER DEFAULT 0
                         ) DEFAULT CHARSET=utf8");
        }

        static function __uninstall($confirm) {
            if ($confirm) {
                foreach (Attachment::find() as $attachment)
                    @unlink(uploaded($attachment->path, true));

                SQL::current()->query("DROP TABLE __attachments");
            }
        }
    }

