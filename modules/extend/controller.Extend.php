<?php
    class ExtendController {
        # Array: $urls
        # An array of clean URL => dirty URL translations.
        public $urls = array(
            '|/view/([^/]+)/([0-9]+)/page/([0-9]+)/|' => '/?action=view&amp;url=$1&amp;version=$2&amp;page=$3',
            '|/view/([^/]+)/([0-9]+)/|' => '/?action=view&amp;url=$1&amp;version=$2',
            '|/view/([^/]+)/|' => '/?action=view&amp;url=$1',
            '|/note/([0-9]+)/|' => '/?action=note&amp;id=$1',
            '|/new_version/([^/]+)/|' => '/?action=new_version&url=$1'
        );

        # Boolean: $displayed
        # Has anything been displayed?
        public $displayed = false;

        # Array: $context
        # Context for displaying pages.
        public $context = array();

        # String: $base
        # The base path for this controller.
        public $base = "extend";

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

        public function index() {
            $this->display("extend/index",
                           array("types" => Type::find()),
                           __("Index"));
        }

        public function all() {
            $this->display(
                "extend/all",
                array(
                    "extensions" => Extension::find(array("placeholders" => true)),
                    "types" => Type::find()
                ),
                __("All Extensions")
            );
        }

        public function yours() {
            $this->display(
                "extend/yours",
                array(
                    "extensions" => Extension::find(
                        array(
                            "where" => array(
                                "user_id" => Visitor::current()->id
                            ),
                            "placeholders" => true
                        )
                    ),
                    "types" => Type::find()
                ),
                __("Your Extensions")
            );
        }

        public function view() {
            if (!isset($_GET['url']))
                exit; # TODO

            $extension = new Extension(null, array("where" => array("url" => $_GET['url'])));
            $version = (isset($_GET['version']) ? new Version($_GET['version']) : $extension->latest_version);

            if ($extension->no_results)
                exit; # TODO

            $this->display("extend/extension/view",
                           array("extension" => $extension,
                                 "extension_version" => $version),
                           $extension->name);
        }

        public function type() {
            if (!isset($_GET['url']))
                exit; # TODO

            $type = new Type(array("url" => $_GET['url']));

            if ($type->no_results)
                exit; # TODO

            $can_create = Visitor::current()->group()->can("add_extension");
            $this->display("extend/type",
                           array("type" => $type,
                                 "users" => ($can_create ? User::find() : array()),
                                 "types" => ($can_create ? Type::find() : array())),
                           $type->name);
        }

        public function download() {
            if (!isset($_GET['version']))
                exit; # TODO

            $version = new Version($_GET['version']);
            if ($version->no_results)
                exit; # TODO

            $version->update(null, null, null, null, null, null, null, $version->downloads + 1, null, null, false);

            header("Location: ".uploaded($version->filename));
        }

        public function love() {
            if (!Visitor::current()->group->can("love_extension"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to love extensions.", "extend"));

            if (!isset($_GET['version']))
                exit; # TODO

            $version = new Version($_GET['version']);
            if ($version->no_results)
                exit; # TODO

            $sql = SQL::current();

            $loved = $sql->count(
                "loves",
                array(
                    "version_id" => $version->id,
                    "user_id" => Visitor::current()->id
                )
            );
            if ($loved == 0) {
                $version->update(null, null, null, null, null, null, $version->loves + 1, null, null, null, false);
                $sql->insert(
                    "loves",
                    array(
                        "version_id" => $version->id,
                        "user_id" => Visitor::current()->id
                    )
                );

                Flash::notice(__("Extension loved.", "extend"), $version->url());
            } else {
                $version->update(null, null, null, null, null, null, $version->loves - 1, null, null, null, false);
                $sql->delete(
                    "loves",
                    array(
                        "version_id" => $version->id,
                        "user_id" => Visitor::current()->id
                    )
                );

                Flash::notice(__("Extension deloved.", "extend"), $version->url());
            }
        }

        public function new_extension() {
            if (!Visitor::current()->group->can("add_extension"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add extensions.", "extend"));

            if (!empty($_POST)) {
                if ($_FILES['extension']['error'] == 4)
                    Flash::warning(__("You forgot the extension file, silly!", "extend"));

                if (empty($_POST['name']))
                    Flash::warning(__("Please enter a name for the extension.", "extend"));

                if (empty($_POST['number']))
                    Flash::warning(__("Please enter a version number.", "extend"));

                if (empty($_POST['compatible']))
                    Flash::warning(__("Please list the Chyrp versions you know to be compatible with this extension.", "extend"));

                if (!Flash::exists())
                    return $this->add_extension();
            }

            $type = oneof(@$_GET['type'], "module");
            $type = new Type(array("url" => $type));
            $this->display("extend/new_extension", array("type" => $type), "Add ".$type->name);
        }

        public function new_version() {
            if (!Visitor::current()->group->can("edit_extension"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit extensions.", "extend"));

            $extension = new Extension(array("url" => $_GET['url']));
            if ($extension->no_results)
                exit; # TODO

            if (!empty($_POST)) {
                if ($_FILES['extension']['error'] == 4)
                    Flash::warning(__("You forgot the extension file, silly!", "extend"));

                if (empty($_POST['number']))
                    Flash::warning(__("Please enter a version number.", "extend"));

                if (empty($_POST['compatible']))
                    Flash::warning(__("Please list the Chyrp versions you know to be compatible with this extension.", "extend"));

                if (!Flash::exists())
                    return $this->add_version();
            }

            $this->display("extend/new_version", array("extension" => $extension), __("New Version", "extend"));
        }

        public function add_note() {
            if (!Visitor::current()->group->can("add_note"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add notes.", "extend"));

            $version = new Version($_POST['version_id']);

            if (empty($_POST['body']))
                Flash::warning(__("Please enter a message.", "extend"), $version->url());

            $note = Note::add($_POST['body'], $_POST['version_id']);

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "note", $note->id);
                }

            Flash::notice(__("Note added.", "extend"), $note->url(true));
        }

        public function add_extension() {
            if (!Visitor::current()->group->can("add_extension"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to create extensions.", "extend"));

            if ($_FILES['extension']['error'] == 4)
                Flash::warning(__("You forgot the extension file, silly!", "extend"), $_SESSION['redirect_to']);

            if (empty($_POST['name']))
                Flash::warning(__("Please enter a name.", "extend"), $_SESSION['redirect_to']);

            if (empty($_POST['number']))
                Flash::warning(__("Please enter a version number.", "extend"), $_SESSION['redirect_to']);

            if (empty($_POST['compatible']))
                Flash::warning(__("Please list the Chyrp versions you know to be compatible with this extension.", "extend"), $_SESSION['redirect_to']);

            $type = new Type($_POST['type_id']);

            $filename = upload($_FILES['extension'], "zip", "extension/".pluralize($type->url));
            $image = upload($_FILES['image'], null, "previews/".pluralize($type->url));

            $extension = Extension::add($_POST['name']);
            $version = Version::add(
                $_POST['number'],
                $_POST['description'],
                comma_sep($_POST['compatible']),
                comma_sep($_POST['tags']),
                $filename,
                $image,
                0,
                0,
                $extension,
                $type
            );

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "version", $version->id);
                }

            Flash::notice(__("Extension added.", "extend"), $extension->url());
        }

        public function add_version() {
            if (!Visitor::current()->group->can("edit_extension"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit extensions.", "extend"));

            if ($_FILES['extension']['error'] == 4)
                Flash::warning(__("You forgot the extension file, silly!", "extend"), $_SESSION['redirect_to']);

            if (empty($_POST['number']))
                Flash::warning(__("Please enter a version number.", "extend"), $_SESSION['redirect_to']);

            if (empty($_POST['compatible']))
                Flash::warning(__("Please list the Chyrp versions you know to be compatible with this extension.", "extend"), $_SESSION['redirect_to']);

            $extension = new Extension($_POST['extension_id']);

            $filename = upload($_FILES['extension'], "zip", "extension/".pluralize($extension->type->url));
            $image = upload($_FILES['image'], null, "previews/".pluralize($extension->type->url));

            $version = Version::add(
                $_POST['number'],
                $_POST['description'],
                comma_sep($_POST['compatible']),
                comma_sep($_POST['tags']),
                $filename,
                $image,
                0,
                0,
                $extension
            );

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "version", $version->id);
                }

            Flash::notice(__("Version added.", "extend"), $version->url());
        }

        public function edit_note() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No note ID specified.", "extend"));

            $note = new Note($_GET['id'], array("filter" => false));
            if ($note->no_results)
                error(__("Error"), __("Invalid note ID specified.", "extend"));

            if (!$note->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this note.", "extend"));

            $this->display("extend/note/edit",
                           array("note" => $note),
                           __("Edit Note", "extend"));
        }

        public function edit_extension() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No extension ID specified.", "extend"));

            $extension = new Extension($_GET['id'], array("filter" => false));
            if ($extension->no_results)
                error(__("Error"), __("Invalid extension ID specified.", "extend"));

            if (!$extension->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this extension.", "extend"));

            $this->display("extend/extension/edit",
                           array("extension" => $extension),
                           _f("Edit &#8220;%s&#8221;", array(fix($extension->title)), "extend"));
        }

        public function update_note() {
            if (!isset($_POST['note_id']))
                error(__("Error"), __("No note ID specified.", "extend"));

            $note = new Note($_POST['note_id']);
            if ($note->no_results)
                error(__("Error"), __("Invalid note ID specified.", "extend"));

            if (!$note->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this note.", "extend"));

            $files = array();
            foreach ($_FILES['attachment'] as $key => $val)
                foreach ($val as $file => $attr)
                    $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "note", $note->id);
                }

            $note->update($_POST['body']);

            Flash::notice(__("Note updated.", "extend"), $note->url(true));
        }

        public function update_extension() {
            if (!isset($_POST['extension_id']))
                error(__("Error"), __("No extension ID specified.", "extend"));

            $extension = new Extension($_POST['extension_id']);
            if ($extension->no_results)
                error(__("Error"), __("Invalid extension ID specified.", "extend"));

            if (!$extension->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this extension.", "extend"));

            $files = array();
            if (!empty($_FILES['attachment']))
                foreach ($_FILES['attachment'] as $key => $val)
                    foreach ($val as $file => $attr)
                        $files[$file][$key] = $attr;

            foreach ($files as $attachment)
                if ($attachment['error'] != 4) {
                    $path = upload($attachment, null, "attachments");
                    Attachment::add(basename($path), $path, "extension", $extension->id);
                }

            $extension->update($_POST['title'], $_POST['description']);

            Flash::notice(__("Extension updated.", "extend"), $extension->url());
        }

        public function delete_note() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No note ID specified.", "extend"));

            $note = new Note($_GET['id']);
            if ($note->no_results)
                error(__("Error"), __("Invalid note ID specified.", "extend"));

            if (!$note->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this note.", "extend"));

            $this->display("extend/note/delete",
                           array("note" => $note),
                           __("Delete Note", "extend"));
        }

        public function delete_extension() {
            if (!isset($_GET['id']))
                error(__("Error"), __("No extension ID specified.", "extend"));

            $extension = new Extension($_GET['id']);
            if ($extension->no_results)
                error(__("Error"), __("Invalid extension ID specified.", "extend"));

            if (!$extension->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this extension.", "extend"));

            $this->display("extend/extension/delete",
                           array("extension" => $extension),
                           _f("Delete &#8220;%s&#8221;", array(fix($extension->title)), "extend"));
        }

        public function destroy_note() {
            if (!isset($_POST['note_id']))
                error(__("Error"), __("No note ID specified.", "extend"));

            $note = new Note($_POST['note_id']);
            if ($note->no_results)
                error(__("Error"), __("Invalid note ID specified.", "extend"));

            if (!$note->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this note.", "extend"));

            if (empty($note->changes))
                Note::delete($note->id);
            else
                $note->update(""); # If changes were made, just clear the body instead of altering history.

            Flash::notice(__("Note deleted.", "extend"), $note->extension->url());
        }

        public function destroy_extension() {
            if (!isset($_POST['extension_id']))
                error(__("Error"), __("No extension ID specified.", "extend"));

            $extension = new Extension($_POST['extension_id']);
            if ($extension->no_results)
                error(__("Error"), __("Invalid extension ID specified.", "extend"));

            if (!$extension->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this extension.", "extend"));

            Extension::delete($extension->id);

            Flash::notice(__("Extension deleted.", "extend"), $extension->milestone->url());
        }

        public function parse($route) {
            $config = Config::current();

            if ($this->feed)
                $this->post_limit = $config->feed_items;
            else
                $this->post_limit = $config->posts_per_page;

            if (empty($route->arg[0]) and !isset($config->routes["/"])) # If they're just at /, don't bother with all this.
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

            # Viewing a type
            if ($route->arg[0] == "type") {
                $_GET['url'] = $route->arg[1];
                return $route->action = "type";
            }

            # Viewing an extension
            if ($route->arg[0] == "view") {
                $_GET['url'] = $route->arg[1];
                $_GET['version'] = @$route->arg[2];
                return $route->action = "view";
            }

            # Downloading an extension
            if ($route->arg[0] == "download") {
                $_GET['url'] = $route->arg[1];
                $_GET['version'] = @$route->arg[2];
                return $route->action = "download";
            }

            # Loving an extension
            if ($route->arg[0] == "love") {
                $_GET['url'] = $route->arg[1];
                $_GET['version'] = @$route->arg[2];
                return $route->action = "love";
            }

            # Adding an extension
            if ($route->arg[0] == "new_extension") {
                $_GET['type'] = oneof(@$route->arg[1], "module");
                return $route->action = "new_extension";
            }

            # Adding a new version of an extension
            if ($route->arg[0] == "new_version") {
                $_GET['url'] = $route->arg[1];
                return $route->action = "new_version";
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

            $trigger->filter($this->context, array("extend_context", "extend_context_".str_replace("/", "_", $file)));

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

