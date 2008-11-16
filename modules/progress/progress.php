<?php
    foreach (glob(MODULES_DIR."/progress/models/*.php") as $model)
        require $model;

    require "controller.Progress.php";

    /**
     * Progress
     */
    class Progress extends Modules {
        static function __install() {
            $sql = SQL::current();

            $sql->query("CREATE TABLE IF NOT EXISTS __milestones (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             name VARCHAR(100) DEFAULT '',
                             description TEXT,
                             due DATETIME DEFAULT '0000-00-00 00:00:00'
                         ) DEFAULT CHARSET=utf8");

            $sql->query("CREATE TABLE IF NOT EXISTS __tickets (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             title VARCHAR(100) DEFAULT '',
                             description LONGTEXT,
                             state VARCHAR(100) DEFAULT 'new',
                             clean VARCHAR(100) DEFAULT '',
                             url VARCHAR(100) DEFAULT '',
                             attachment VARCHAR(100) DEFAULT '',
                             milestone_id INTEGER DEFAULT 0,
                             owner_id INTEGER DEFAULT 0,
                             user_id INTEGER DEFAULT 0,
                             created_at DATETIME DEFAULT '0000-00-00 00:00:00',
                             updated_at DATETIME DEFAULT '0000-00-00 00:00:00'
                         ) DEFAULT CHARSET=utf8");

            $sql->query("CREATE TABLE IF NOT EXISTS __revisions (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             body LONGTEXT,
                             changes TEXT,
                             attachment VARCHAR(100) DEFAULT '',
                             ticket_id INTEGER DEFAULT 0,
                             user_id INTEGER DEFAULT 0,
                             created_at DATETIME DEFAULT '0000-00-00 00:00:00',
                             updated_at DATETIME DEFAULT '0000-00-00 00:00:00'
                         ) DEFAULT CHARSET=utf8");

            Group::add_permission("add_milestone", "Add Milestone");
            Group::add_permission("add_ticket", "Add Ticket");
            Group::add_permission("add_revision", "Add Revision");
            
            Group::add_permission("edit_milestone", "Edit Milestone");
            Group::add_permission("edit_ticket", "Edit Ticket");
            Group::add_permission("edit_revision", "Edit Revision");
            
            Group::add_permission("edit_own_ticket", "Edit Own Ticket");
            Group::add_permission("edit_own_revision", "Edit Own Revision");
            
            Group::add_permission("delete_milestone", "Delete Milestone");
            Group::add_permission("delete_ticket", "Delete Ticket");
            Group::add_permission("delete_revision", "Delete Revision");
            
            Group::add_permission("delete_own_ticket", "Delete Own Ticket");
            Group::add_permission("delete_own_revision", "Delete Own Revision");

            Group::add_permission("code_in_revisions", "Can Use HTML In Revisions");
        }

        static function __uninstall($confirm) {
            if ($confirm) {
                SQL::current()->query("DROP TABLE __milestones");
                SQL::current()->query("DROP TABLE __tickets");
                SQL::current()->query("DROP TABLE __revisions");
            }

            Group::remove_permission("add_milestone");
            Group::remove_permission("add_ticket");
            Group::remove_permission("add_revision");
            
            Group::remove_permission("edit_milestone");
            Group::remove_permission("edit_ticket");
            Group::remove_permission("edit_revision");
            
            Group::remove_permission("edit_own_ticket");
            Group::remove_permission("edit_own_revision");
            
            Group::remove_permission("delete_milestone");
            Group::remove_permission("delete_ticket");
            Group::remove_permission("delete_revision");
            
            Group::remove_permission("delete_own_ticket");
            Group::remove_permission("delete_own_revision");

            Group::remove_permission("code_in_revisions");
        }

        public function admin_head() {
            $config = Config::current();
?>
        <script src="<?php echo $config->chyrp_url; ?>/modules/progress/lib/tablednd.js" type="text/javascript"></script>
        <script src="<?php echo $config->chyrp_url; ?>/modules/progress/admin.js" type="text/javascript"></script>
<?php
        }

        public function ajax() {
            if ($_POST['action'] != "reorder_Milestones")
                return;

            foreach (explode(",", $_POST['order']) as $order => $id) {
                $milestone = new Milestone($id, array("filter" => false));
                $milestone->update(null, null, $order);
            }
        }

        static function manage_nav($navs) {
            $visitor = Visitor::current();
            if (!$visitor->group->can("edit_milestone", "delete_milestone"))
                return $navs;

            $navs["manage_milestones"] = array("title" => __("Milestones", "progress"),
                                           "selected" => array("new_milestone", "edit_milestone", "delete_milestone"));

            return $navs;
        }

        static function manage_nav_pages($pages) {
            array_push($pages, "manage_milestones", "new_milestone", "edit_milestone", "delete_milestone", "manage_tickets", "manage_revisions");
            return $pages;
        }

        public function admin_manage_milestones($admin) {
            $visitor = Visitor::current();
            if (!$visitor->group->can("edit_milestone", "delete_milestone"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to manage any milestones.", "comments"));

            $admin->display("manage_milestones",
                            array("milestones" => Milestone::find(array("placeholders" => true))));
        }

        public function admin_new_milestone($admin) {
            if (!Visitor::current()->group->can("add_milestone"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add milestones.", "progress"));

            $admin->display("new_milestone", array(), __("New Milestone", "progress"));
        }

        public function admin_add_milestone() {
            if (!Visitor::current()->group->can("add_milestone"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add milestones.", "progress"));

            $due = (empty($_POST['due']) ? "0000-00-00 00:00:00" : datetime($_POST['due']));
            Milestone::add($_POST['name'], $_POST['description'], $due);
            Flash::notice(__("Milestone added.", "progress"), "/admin/?action=manage_milestones");
        }

        public function admin_edit_milestone($admin) {
            if (!isset($_GET['id']))
                error(__("Error"), __("No milestone ID specified.", "progress"));

            $milestone = new Milestone($_GET['id'], array("filter" => false));
            if ($milestone->no_results)
                error(__("Error"), __("Invalid milestone ID specified.", "progress"));

            if (!$milestone->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this milestone.", "progress"));

            $admin->display("edit_milestone",
                            array("milestone" => $milestone),
                            _f("Edit Milestone &#8220;%s&#8221;", array(fix($milestone->name)), "progress"));
        }

        public function admin_update_milestone($admin) {
            if (!isset($_POST['milestone_id']))
                error(__("Error"), __("No milestone ID specified.", "progress"));

            if (!isset($_POST['hash']) or $_POST['hash'] != Config::current()->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            $milestone = new Milestone($_POST['milestone_id']);
            if ($milestone->no_results)
                error(__("Error"), __("Invalid milestone ID specified.", "progress"));

            if (!$milestone->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this milestone.", "progress"));

            $due = (empty($_POST['due']) ? "0000-00-00 00:00:00" : datetime($_POST['due']));
            $milestone->update($_POST['name'], $_POST['description'], $due);

            Flash::notice(__("Milestone updated.", "progress"), "/admin/?action=manage_milestones");
        }

        public function admin_delete_milestone($admin) {
            if (!isset($_GET['id']))
                error(__("Error"), __("No milestone ID specified.", "progress"));

            $milestone = new Milestone($_GET['id']);
            if ($milestone->no_results)
                error(__("Error"), __("Invalid milestone ID specified.", "progress"));

            if (!$milestone->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this milestone.", "progress"));

            $admin->display("delete_milestone",
                            array("milestone" => $milestone,
                                  "milestones" => Milestone::find(array("where" => array("id not" => $milestone->id)))),
                            _f("Delete Milestone &#8220;%s&#8221;", array(fix($milestone->name)), "progress"));
        }

        public function admin_destroy_milestone() {
            if (!isset($_POST['id']))
                error(__("Error"), __("No milestone ID specified.", "progress"));

            if (!isset($_POST['hash']) or $_POST['hash'] != Config::current()->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            $milestone = new Milestone($_POST['id']);
            if ($milestone->no_results)
                error(__("Error"), __("Invalid milestone ID specified.", "progress"));

            if (!$milestone->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this milestone.", "progress"));

            foreach ($milestone->tickets as $ticket)
                $ticket->update(null, null, $_POST['move_milestone']);

            Milestone::delete($milestone->id);

            Flash::notice(__("Milestone deleted.", "progress"), "/admin/?action=manage_milestones");
        }
    }
