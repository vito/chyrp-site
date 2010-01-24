<?php
    foreach (glob("demo[123456789]*") as $demo) {
        preg_match("/^demo([0-9]+)$/", $demo, $number);

        if (empty($number[1]) or (time() - file_get_contents("demo".$number[1]."/CREATED_AT")) < 1800)
            continue;

        shell_exec("rm -r /srv/http/chyrp.net/site/demos/demo".$number[1]);
        @unlink("/srv/db/demos/demo".$number[1].".db");
    }




