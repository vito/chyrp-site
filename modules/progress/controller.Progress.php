<?php
    class ProgressController {
        # Array: $urls
        # An array of clean URL => dirty URL translations.
        public $urls = array(
            "|/milestone/([0-9]+)/|" => '/?action=milestone&id=$1',
            "|/ticket/([^/]+)/page/([0-9]+)/|" => '/?action=ticket&url=$1&page=$2',
            "|/ticket/([^/]+)/|" => '/?action=ticket&url=$1',
            "|/search/([^/]+)/|" => '/?action=search&query=$1',
            "|/edit_ticket/([0-9]+)/|" => '/?action=edit_ticket&id=$1',
            "|/delete_ticket/([0-9]+)/|" => '/?action=delete_ticket&id=$1',
            "|/edit_revision/([0-9]+)/|" => '/?action=edit_revision&id=$1',
            "|/delete_revision/([0-9]+)/|" => '/?action=delete_revision&id=$1'
        );

        # Boolean: $displayed
        # Has anything been displayed?
        public $displayed = false;

        # Array: $context
        # Context for displaying pages.
        public $context = array();

        # String: $base
        # The base path for this controller.
        public $base = "progress";

        # Boolean: $feed
        # Is the visitor requesting a feed?
        public $feed = false;

        public function __construct() {
            $cache = (is_writable(INCLUDES_DIR."/caches") and
                      !DEBUG and
                      !PREVIEWING and
                      !defined('CACHE_TWIG') or CACHE_TWIG);
            $this->twig = new Twig_Loader(THEME_DIR,
                                          $cache ?
                                              INCLUDES_DIR."/caches" :
                                              null) ;
        }

        public function parse($route) {
            $config = Config::current();

            if (empty($route->arg[0]) and !isset($config->routes["progress"]["/"]))
                return $route->action = "index";

            # Protect non-responder functions.
            if (in_array($route->arg[0], array("__construct", "parse", "display", "current")))
                show_404();

            # Feed
            if (preg_match("/\/feed\/?$/", $route->request)) {
                $this->feed = true;
                $this->post_limit = $config->feed_items;

                if ($route->arg[0] == "feed") # Don't set $route->action to "feed" (bottom of this function).
                    return $route->action = "index";
            }

            # Feed with a title parameter
            if (preg_match("/\/feed\/([^\/]+)\/?$/", $route->request, $title)) {
                $this->feed = true;
                $this->post_limit = $config->feed_items;
                $_GET['title'] = $title[1];

                if ($route->arg[0] == "feed") # Don't set $route->action to "feed" (bottom of this function).
                    return $route->action = "index";
            }

            # Paginator
            if (preg_match_all("/\/((([^_\/]+)_)?page)\/([0-9]+)/", $route->request, $page_matches)) {
                foreach ($page_matches[1] as $key => $page_var)
                    $_GET[$page_var] = (int) $page_matches[4][$key];

                if ($route->arg[0] == $page_matches[1][0]) # Don't fool ourselves into thinking we're viewing a page.
                    return $route->action = (isset($config->routes["/"])) ? $config->routes["/"] : "index" ;
            }

            # Viewing a milestone
            if (in_array($route->arg[0], array("milestone", "edit_ticket", "delete_ticket", "edit_revision", "delete_revision"))) {
                $_GET['id'] = $route->arg[1];
                return $route->action = $route->arg[0];
            }

            # Viewing a ticket
            if ($route->arg[0] == "ticket") {
                $_GET['url'] = $route->arg[1];
                return $route->action = "ticket";
            }

            # Searching
            if ($route->arg[0] == "search") {
                if (isset($route->arg[1]))
                    $_GET['query'] = $route->arg[1];

                return $route->action = "search";
            }

            # Custom pages added by Modules, Feathers, Themes, etc.
            foreach ($config->routes as $path => $action) {
                if (is_numeric($action))
                    $action = $route->arg[0];

                preg_match_all("/\(([^\)]+)\)/", $path, $matches);

                if ($path != "/")
                    $path = trim($path, "/");

                $escape = preg_quote($path, "/");
                $to_regexp = preg_replace("/\\\\\(([^\)]+)\\\\\)/", "([^\/]+)", $escape);

                if ($path == "/")
                    $to_regexp = "\$";

                if (preg_match("/^\/{$to_regexp}/", $route->request, $url_matches)) {
                    array_shift($url_matches);

                    if (isset($matches[1]))
                        foreach ($matches[1] as $index => $parameter)
                            $_GET[$parameter] = urldecode($url_matches[$index]);

                    $params = explode(";", $action);
                    $action = $params[0];

                    array_shift($params);
                    foreach ($params as $param) {
                        $split = explode("=", $param);
                        $_GET[$split[0]] = fallback($split[1], "", true);
                    }

                    $route->try[] = $action;
                }
            }
        }

        public function index() {
            $milestones = Milestone::find();

            $this->display("progress/index",
                           array("milestones" => $milestones),
                           __("Index"));
        }

        public function ticket() {
            if (!isset($_GET['url']))
                exit; # TODO

            $ticket = new Ticket(null, array("where" => array("url" => $_GET['url'])));

            if ($ticket->no_results)
                exit; # TODO

            $this->display("progress/ticket/view",
                           array("ticket" => $ticket,
                                 "users" => ($ticket->editable() ? User::find() : array()),
                                 "milestones" => ($ticket->editable() ? Milestone::find() : array())),
                           $ticket->title);
        }

        public function milestone() {
            if (!isset($_GET['id']))
                exit; # TODO

            $milestone = new Milestone($_GET['id']);

            if ($milestone->no_results)
                exit; # TODO

            $can_create = Visitor::current()->group()->can("add_ticket");
            $this->display("progress/milestone",
                           array("milestone" => $milestone,
                                 "users" => ($can_create ? User::find() : array()),
                                 "milestones" => ($can_create ? Milestone::find() : array())),
                           $milestone->name);
        }

        public function revision() {
            if (!isset($_GET['id']))
                exit; # TODO

            $revision = new Revision($_GET['id']);

            if ($revision->no_results)
                exit; # TODO

            $this->display("progress/revision/view",
                           array("revision" => $revision),
                           __("Revision", "progress"));
        }

        public function search() {
            fallback($_GET['query'], "");
            $config = Config::current();

            if ($config->clean_urls and
                substr_count($_SERVER['REQUEST_URI'], "?") and
                !substr_count($_SERVER['REQUEST_URI'], "%2F")) # Searches with / and clean URLs = server 404
                redirect("search/".urlencode($_GET['query'])."/");

            if (empty($_GET['query']))
                return Flash::warning(__("Please enter a search term."));
        }

        public function add_revision() {
            if (!Visitor::current()->group->can("add_revision"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add revisions.", "progress"));

            $ticket = new Ticket($_POST['ticket_id'], array("filter" => false));
            $old = clone $ticket;

            $ticket->update($_POST['title'], null, $_POST['state'], $_POST['milestone_id'], $_POST['owner_id']);

            $changes = array();
            foreach ($ticket as $name => $val)
                if ($name != "updated_at" and $old->$name != $val) {
                    $from = $old->$name;

                    if (in_array($name, array("milestone_id", "owner_id", "user_id"))) {
                        $model = ($name == "owner_id" ? "user" : substr($name, 0, -3));

                        $fromobj = new $model($from);
                        $obj     = new $model($val);

                        if ($name == "milestone_id") {
                            $from = $fromobj->name;
                            $val  = $obj->name;
                        } else {
                            $from = oneof($fromobj->full_name, $fromobj->login);
                            $val  = oneof($obj->full_name, $obj->login);
                        }

                        $name = substr($name, 0, -3);
                    }

                    $changes[$name] = array("from" => $from,
                                            "to" => $val);
                }

            if (empty($changes)) {
                $ticket->update(null, null, null, null, null, null, null, $old->updated_at);

                if (empty($_POST['body']))
                    Flash::warning(__("Please enter a message.", "progress"), $ticket->url());
            }

            $revision = Revision::add($_POST['body'], $changes, $_POST['ticket_id']);

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "revision", $revision->id);
                }

            Flash::notice(__("Revision added.", "progress"), $revision->url(true));
        }

        public function add_ticket() {
            if (!Visitor::current()->group->can("add_ticket"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to create tickets.", "progress"));

            $ticket = Ticket::add($_POST['title'], $_POST['description'], "new", $_POST['milestone_id'], $_POST['owner_id']);

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "ticket", $ticket->id);
                }

            Flash::notice(__("Ticket added.", "progress"), $ticket->url());
        }

        public function edit_revision() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No revision ID specified.", "progress"));

            $revision = new Revision($_GET['id'], array("filter" => false));
            if ($revision->no_results)
                error(__("Error"), __("Invalid revision ID specified.", "progress"));

            if (!$revision->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this revision.", "progress"));

            $this->display("progress/revision/edit",
                           array("revision" => $revision),
                           __("Edit Revision", "progress"));
        }

        public function edit_ticket() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No ticket ID specified.", "progress"));

            $ticket = new Ticket($_GET['id'], array("filter" => false));
            if ($ticket->no_results)
                error(__("Error"), __("Invalid ticket ID specified.", "progress"));

            if (!$ticket->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this ticket.", "progress"));

            $this->display("progress/ticket/edit",
                           array("ticket" => $ticket),
                           _f("Edit &#8220;%s&#8221;", array(fix($ticket->title)), "progress"));
        }

        public function update_revision() {
            if (!isset($_POST['revision_id']))
                error(__("Error"), __("No revision ID specified.", "progress"));

            $revision = new Revision($_POST['revision_id']);
            if ($revision->no_results)
                error(__("Error"), __("Invalid revision ID specified.", "progress"));

            if (!$revision->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this revision.", "progress"));

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "revision", $revision->id);
                }

            $revision->update($_POST['body']);

            Flash::notice(__("Revision updated.", "progress"), $revision->url(true));
        }

        public function update_ticket() {
            if (!isset($_POST['ticket_id']))
                error(__("Error"), __("No ticket ID specified.", "progress"));

            $ticket = new Ticket($_POST['ticket_id']);
            if ($ticket->no_results)
                error(__("Error"), __("Invalid ticket ID specified.", "progress"));

            if (!$ticket->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this ticket.", "progress"));

            $files = array();
            if (!empty($_FILES['attachment']))
                foreach ($_FILES['attachment'] as $key => $val)
                    foreach ($val as $file => $attr)
                        $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "ticket", $ticket->id);
                }

            $ticket->update($_POST['title'], $_POST['description']);

            Flash::notice(__("Ticket updated.", "progress"), $ticket->url());
        }

        public function delete_revision() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No revision ID specified.", "progress"));

            $revision = new Revision($_GET['id']);
            if ($revision->no_results)
                error(__("Error"), __("Invalid revision ID specified.", "progress"));

            if (!$revision->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this revision.", "progress"));

            $this->display("progress/revision/delete",
                           array("revision" => $revision),
                           __("Delete Revision", "progress"));
        }

        public function delete_ticket() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No ticket ID specified.", "progress"));

            $ticket = new Ticket($_GET['id']);
            if ($ticket->no_results)
                error(__("Error"), __("Invalid ticket ID specified.", "progress"));

            if (!$ticket->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this ticket.", "progress"));

            $this->display("progress/ticket/delete",
                           array("ticket" => $ticket),
                           _f("Delete &#8220;%s&#8221;", array(fix($ticket->title)), "progress"));
        }

        public function destroy_revision() {
            if (!isset($_POST['revision_id']))
                error(__("Error"), __("No revision ID specified.", "progress"));

            $revision = new Revision($_POST['revision_id']);
            if ($revision->no_results)
                error(__("Error"), __("Invalid revision ID specified.", "progress"));

            if (!$revision->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this revision.", "progress"));

            if (empty($revision->changes))
                Revision::delete($revision->id);
            else
                $revision->update(""); # If changes were made, just clear the body instead of altering history.

            Flash::notice(__("Revision deleted.", "progress"), $revision->ticket->url());
        }

        public function destroy_ticket() {
            if (!isset($_POST['ticket_id']))
                error(__("Error"), __("No ticket ID specified.", "progress"));

            $ticket = new Ticket($_POST['ticket_id']);
            if ($ticket->no_results)
                error(__("Error"), __("Invalid ticket ID specified.", "progress"));

            if (!$ticket->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this ticket.", "progress"));

            Ticket::delete($ticket->id);

            Flash::notice(__("Ticket deleted.", "progress"), $ticket->milestone->url());
        }

        /**
         * Function: resort
         * Queue a failpage in the event that none of the routes are successful.
         */
        public function resort($file, $context, $title = null) {
            $this->fallback = array($file, $context, $title);
            return false;
        }

        /**
         * Function: display
         * Display the page.
         *
         * If "posts" is in the context and the visitor requested a feed, they will be served.
         *
         * Parameters:
         *     $file - The theme file to display.
         *     $context - The context for the file.
         *     $title - The title for the page.
         */
        public function display($file, $context = array(), $title = "") {
            if (is_array($file))
                for ($i = 0; $i < count($file); $i++) {
                    $check = ($file[$i][0] == "/" or preg_match("/[a-zA-Z]:\\\/", $file[$i])) ?
                                 $file[$i] :
                                 THEME_DIR."/".$file[$i] ;

                    if (file_exists($check.".twig") or ($i + 1) == count($file))
                        return $this->display($file[$i], $context, $title);
                }

            $this->displayed = true;

            $route = Route::current();
            $trigger = Trigger::current();

            # Serve feeds.
            if ($this->feed) {
                if ($trigger->exists($route->action."_feed"))
                    return $trigger->call($route->action."_feed", $context);

                if (isset($context["posts"]))
                    return $this->feed($context["posts"]);
            }

            $this->context = array_merge($context, $this->context);

            $visitor = Visitor::current();
            $config = Config::current();

            $this->context["theme"]        = Theme::current();
            $this->context["flash"]        = Flash::current();
            $this->context["trigger"]      = $trigger;
            $this->context["modules"]      = Modules::$instances;
            $this->context["feathers"]     = Feathers::$instances;
            $this->context["title"]        = $title;
            $this->context["site"]         = $config;
            $this->context["visitor"]      = $visitor;
            $this->context["route"]        = Route::current();
            $this->context["hide_admin"]   = isset($_COOKIE["hide_admin"]);
            $this->context["version"]      = CHYRP_VERSION;
            $this->context["now"]          = time();
            $this->context["debug"]        = DEBUG;
            $this->context["POST"]         = $_POST;
            $this->context["GET"]          = $_GET;
            $this->context["sql_queries"] =& SQL::current()->queries;

            $this->context["visitor"]->logged_in = logged_in();

            $this->context["enabled_modules"] = array();
            foreach ($config->enabled_modules as $module)
                $this->context["enabled_modules"][$module] = true;

            $context["enabled_feathers"] = array();
            foreach ($config->enabled_feathers as $feather)
                $this->context["enabled_feathers"][$feather] = true;

            $this->context["sql_debug"] =& SQL::current()->debug;

            $trigger->filter($this->context, array("progress_context", "progress_context_".str_replace("/", "_", $file)));

            $file = ($file[0] == "/" or preg_match("/[a-zA-Z]:\\\/", $file)) ? $file : THEME_DIR."/".$file ;
            if (!file_exists($file.".twig"))
                error(__("Template Missing"), _f("Couldn't load template: <code>%s</code>", array($file.".twig")));

            try {
                return $this->twig->getTemplate($file.".twig")->display($this->context);
            } catch (Exception $e) {
                $prettify = preg_replace("/([^:]+): (.+)/", "\\1: <code>\\2</code>", $e->getMessage());
                $trace = debug_backtrace();
                $twig = array("file" => $e->filename, "line" => $e->lineno);
                array_unshift($trace, $twig);
                error(__("Error"), $prettify, $trace);
            }
        }

        /**
         * Function: current
         * Returns a singleton reference to the current class.
         */
        public static function & current() {
            static $instance = null;
            return $instance = (empty($instance)) ? new self() : $instance ;
        }
    }

