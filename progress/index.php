<?php
    if (version_compare(PHP_VERSION, "5.1.3", "<"))
        exit("Chyrp requires PHP 5.1.3 or greater.");

    require_once "../includes/common.php";

    # Prepare the controller.
    $progress = ProgressController::current();

    # Parse the route.
    $route = Route::current($progress);

    # Execute the appropriate Controller responder.
    $route->init();

    # If the route failed or nothing was displayed, check for:
    #     1. Module-provided pages.
    #     2. Feather-provided pages.
    #     3. Theme-provided pages.
    if (!$route->success) {
        $displayed = false;

        if (!$displayed and $theme->file_exists("pages/".$route->action))
            $progress->display("pages/progress/".$route->action);
        elseif (!$displayed)
            show_404();
    }

    $trigger->call("end", $route);

    ob_end_flush();

