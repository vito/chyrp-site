<?php
    class DiscussController {
        # Array: $urls
        # An array of clean URL => dirty URL translations.
        public $urls = array(
            "|/forum/([^/]+)/|" => '/?action=forum&url=$1',
            "|/topic/([^/]+)/page/([0-9]+)/|" => '/?action=topic&url=$1&page=$2',
            "|/topic/([^/]+)/|" => '/?action=topic&url=$1',
            "|/edit_topic/([0-9]+)/|" => '/?action=edit_topic&id=$1',
            "|/delete_topic/([0-9]+)/|" => '/?action=delete_topic&id=$1',
            "|/edit_message/([0-9]+)/|" => '/?action=edit_message&id=$1',
            "|/delete_message/([0-9]+)/|" => '/?action=delete_message&id=$1'
        );

        # Boolean: $displayed
        # Has anything been displayed?
        public $displayed = false;

        # Array: $context
        # Context for displaying pages.
        public $context = array();

        # String: $base
        # The base path for this controller.
        public $base = "discuss";

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

            if (empty($route->arg[0]) and !isset($config->routes["discuss"]["/"]))
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

            # Viewing a forum
            if ($route->arg[0] == "forum") {
                $_GET['url'] = $route->arg[1];
                return $route->action = "forum";
            }

            # Viewing a topic
            if ($route->arg[0] == "topic") {
                $_GET['url'] = $route->arg[1];
                return $route->action = "topic";
            }

            if (in_array($route->arg[0], array("edit_topic", "delete_topic", "edit_message", "delete_message"))) {
                $_GET['id'] = $route->arg[1];
                return $route->action = $route->arg[0];
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
            $forums = Forum::find();

            $this->display("discuss/index",
                           array("forums" => $forums),
                           __("Index"));
        }

        public function topic() {
            if (!isset($_GET['url']))
                exit; # TODO

            $topic = new Topic(null, array("where" => array("url" => $_GET['url'])));

            if ($topic->no_results)
                exit; # TODO

            $this->display("discuss/topic/view",
                           array("topic" => $topic),
                           $topic->title);

            $topic->view();
        }

        public function forum() {
            if (!isset($_GET['url']))
                exit; # TODO

            $forum = new Forum(null, array("where" => array("url" => $_GET['url'])));

            if ($forum->no_results)
                exit; # TODO

            $this->display("discuss/forum",
                           array("forum" => $forum),
                           $forum->name);
        }

        public function message() {
            if (!isset($_GET['id']))
                exit; # TODO

            $message = new Message($_GET['id']);

            if ($message->no_results)
                exit; # TODO

            $this->display("discuss/message/view",
                           array("message" => $message),
                           __("Message", "discuss"));
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

            list($where, $params) = keywords($_GET['query'], "name LIKE :query OR url LIKE :query", "forums");

            $forums = Forum::find(
                array(
                    "placeholders" => true,
                    "where" => $where,
                    "params" => $params
                )
            );

            list($where, $params) = keywords($_GET['query'], "title LIKE :query OR description LIKE :query OR url LIKE :query", "topics");

            $topics = Topic::find(
                array(
                    "placeholders" => true,
                    "where" => $where,
                    "params" => $params
                )
            );

            list($where, $params) = keywords($_GET['query'], "body LIKE :query", "messages");

            $messages = Message::find(
                array(
                    "placeholders" => true,
                    "where" => $where,
                    "params" => $params
                )
            );

            $this->display(
                "discuss/search",
                array(
                    "forums" => new Paginator($forums, 25, "forums_page"),
                    "topics" => new Paginator($topics, 25, "topics_pave"),
                    "messages" => new Paginator($messages, 25, "messages_page"),
                    "search" => $_GET['query']
                ),
                fix(_f("Search results for \"%s\"", $_GET['query']))
            );
        }

        public function add_message() {
            if (!Visitor::current()->group->can("add_message"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add messages.", "discuss"));

            if (empty($_POST['topic_id']))
                error(__("Error"), __("Please enter a message."));

            $topic = new Topic($_POST['topic_id']);
            if (empty($_POST['body']))
                Flash::warning(__("Please enter a message.", "discuss"), $topic->url());

            $message = Message::add($_POST['body'], $_POST['topic_id']);

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "message", $message->id);
                }

            Flash::notice(__("Message added.", "discuss"), $message->url(true));
        }

        public function add_topic() {
            if (!Visitor::current()->group->can("add_topic"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to create topics.", "discuss"));

            $topic = Topic::add($_POST['title'], $_POST['description'], $_POST['forum_id']);

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "topic", $topic->id);
                }

            Flash::notice(__("Topic added.", "discuss"), $topic->url());
        }

        public function edit_message() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No message ID specified.", "discuss"));

            $message = new Message($_GET['id'], array("filter" => false));
            if ($message->no_results)
                error(__("Error"), __("Invalid message ID specified.", "discuss"));

            if (!$message->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this message.", "discuss"));

            $this->display("discuss/message/edit",
                           array("message" => $message),
                           __("Edit Message", "discuss"));
        }

        public function edit_topic() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No topic ID specified.", "discuss"));

            $topic = new Topic($_GET['id'], array("filter" => false));
            if ($topic->no_results)
                error(__("Error"), __("Invalid topic ID specified.", "discuss"));

            if (!$topic->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this topic.", "discuss"));

            $this->display("discuss/topic/edit",
                           array("topic" => $topic),
                           _f("Edit &#8220;%s&#8221;", array(fix($topic->title)), "discuss"));
        }

        public function update_message() {
            if (!isset($_POST['message_id']))
                error(__("Error"), __("No message ID specified.", "discuss"));

            $message = new Message($_POST['message_id']);
            if ($message->no_results)
                error(__("Error"), __("Invalid message ID specified.", "discuss"));

            if (!$message->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this message.", "discuss"));

            $message->update($_POST['body']);

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "message", $message->id);
                }

            Flash::notice(__("Message updated.", "discuss"), $message->url(true));
        }

        public function update_topic() {
            if (!isset($_POST['topic_id']))
                error(__("Error"), __("No topic ID specified.", "discuss"));

            $topic = new Topic($_POST['topic_id']);
            if ($topic->no_results)
                error(__("Error"), __("Invalid topic ID specified.", "discuss"));

            if (!$topic->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this topic.", "discuss"));

            $topic->update($_POST['title'], $_POST['description']);

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "topic", $topic->id);
                }

            Flash::notice(__("Topic updated.", "discuss"), $topic->url());
        }

        public function delete_message() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No message ID specified.", "discuss"));

            $message = new Message($_GET['id']);
            if ($message->no_results)
                error(__("Error"), __("Invalid message ID specified.", "discuss"));

            if (!$message->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this message.", "discuss"));

            $this->display("discuss/message/delete",
                           array("message" => $message),
                           __("Delete Message", "discuss"));
        }

        public function delete_topic() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No topic ID specified.", "discuss"));

            $topic = new Topic($_GET['id']);
            if ($topic->no_results)
                error(__("Error"), __("Invalid topic ID specified.", "discuss"));

            if (!$topic->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this topic.", "discuss"));

            $this->display("discuss/topic/delete",
                           array("topic" => $topic),
                           _f("Delete &#8220;%s&#8221;", array(fix($topic->title)), "discuss"));
        }

        public function destroy_message() {
            if (!isset($_POST['message_id']))
                error(__("Error"), __("No message ID specified.", "discuss"));

            $message = new Message($_POST['message_id']);
            if ($message->no_results)
                error(__("Error"), __("Invalid message ID specified.", "discuss"));

            if (!$message->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this message.", "discuss"));

            Message::delete($message->id);

            Flash::notice(__("Message deleted.", "discuss"), $message->topic->url());
        }

        public function destroy_topic() {
            if (!isset($_POST['topic_id']))
                error(__("Error"), __("No topic ID specified.", "discuss"));

            $topic = new Topic($_POST['topic_id']);
            if ($topic->no_results)
                error(__("Error"), __("Invalid topic ID specified.", "discuss"));

            if (!$topic->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this topic.", "discuss"));

            Topic::delete($topic->id);

            Flash::notice(__("Topic deleted.", "discuss"), $topic->forum->url());
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

            $trigger->filter($this->context, array("discuss_context", "discuss_context_".str_replace("/", "_", $file)));

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

