<?php
    if (version_compare(PHP_VERSION, "5.1.3", "<"))
        exit("Chyrp requires PHP 5.1.3 or greater.");

    require_once "../includes/common.php";

    # Prepare the controller.
    $discuss = DiscussController::current();

    # Parse the route.
    $route = Route::current($discuss);

    if (module_enabled("cacher") and !empty(Modules::$instances["cacher"]->cacher->path)) {
        $cacher =& Modules::$instances["cacher"]->cacher;

        $cacher->caches = INCLUDES_DIR."/caches/discuss";
        $cacher->path = str_replace(INCLUDES_DIR."/caches",
                                    INCLUDES_DIR."/caches/discuss",
                                    $cacher->path);
        $cacher->file = $cacher->path."/".md5($cacher->url).".html";

        $cacher->remove_expired();
    }

    # Execute the appropriate Controller responder.
    $route->init();

    # If the route failed or nothing was displayed, check for:
    #     1. Module-provided pages.
    #     2. Feather-provided pages.
    #     3. Theme-provided pages.
    if (!$route->success) {
        $displayed = false;

        if (!$displayed and $theme->file_exists("pages/".$route->action))
            $discuss->display("pages/discuss/".$route->action);
        elseif (!$displayed)
            show_404();
    }

    $trigger->call("end", $route);

    ob_end_flush();

