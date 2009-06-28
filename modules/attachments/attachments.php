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

        public function parse_urls(&$urls) {
            $urls["|/delete_attachment/([0-9]+)/|"] = '/?action=delete_attachment&amp;id=$1';
        }

        public function parse_url($route) {
            if ($route->arg[0] == "delete_attachment" and is_numeric(@$route->arg[1])) {
                $_GET['id'] = $route->arg[1];
                $route->action = "delete_attachment";
            }
        }

        public function main_delete_attachment() {
            if (!isset($_GET['id']))
                error(__("No ID Specified"), __("An ID is required to delete an attachment.", "attachments"));

            $attachment = new Attachment($_GET['id']);
            if ($attachment->no_results)
                error(__("Error"), __("Invalid attachment ID specified.", "attachments"));

            if (!$attachment->deletable())
                error(__("Access Denied"), __("You cannot delete this attachment.", "attachments"));

            Attachment::delete($attachment->id);

            Flash::notice(__("Attachment deleted.", "attachments"), $_SESSION['redirect_to']);
        }
    }
