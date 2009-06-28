<?php
    define('JAVASCRIPT', true);
    require_once "../../../includes/common.php";
    error_reporting(0);
    header("Content-Type: application/x-javascript");
?>
<!-- --><script>
$(function(){
    $(".flash").
        append("<span class=\"sub\"><?php echo __("(click to hide)", "theme"); ?></span>").
        click(function(){
            $(this).fadeOut("fast");
        })
        .css("cursor", "pointer");

    if ($.browser.safari)
        $("input#search").attr({
            placeholder: "<?php echo __("Search...", "theme"); ?>"
        });

    if ($("#debug").size() && Cookie.get("hide_debug") != "true")
        $("#wrapper").css("padding-bottom", $("#debug").height());

    $("#debug .toggle").click(function(){
        if (Cookie.get("hide_debug") == "true") {
            Cookie.destroy("hide_debug");
            $("#debug h5:first span").remove();
            $("#debug").animate({ height: "33%" });
            $("#debug").css("overflow-y", "scroll");
        } else {
            Cookie.set("hide_debug", "true", 30);
            $("#debug").animate({ height: 17 });
            $("#debug").css("overflow-y", "hidden");
            $("#debug ul li").each(function(){
                $("<span class=\"sub\">&nbsp;&nbsp;|&nbsp;&nbsp;"+ $(this).html() +"</span>").appendTo("#debug h5:first");
            })
        }
    })

    $("input#slug").live("keyup", function(e){
        if (/^([a-zA-Z0-9\-\._:]*)$/.test($(this).val()))
            $(this).css("background", "")
        else
            $(this).css("background", "#ff2222")
    })

    $(".colorize").bind("mouseenter", function(){
        $(this).data("text", $(this).text());
		$(this).html(colorize($(this).text()));
	}).bind("mouseleave", function(){
		$(this).html($(this).data("text"));
	});

    $(".big a.toggle").click(function(){
        $(".big ul").slideToggle("fast");
        return false;
    });

    if (Cookie.get("hide_debug") == "true") {
        $("#debug").height(17);
        $("#debug").css("overflow-y", "hidden");
        $("#debug ul li").each(function(){
            $("<span class=\"sub\">&nbsp;&nbsp;|&nbsp;&nbsp;"+ $(this).html() +"</span>").appendTo("#debug h5:first");
        })
    }
})

var colors = ["#ff2e99", "#ff8a2d", "#ffe12a", "#caff2a", "#1fb5ff", "#5931ff", "#b848ff"]

function colorize(text) {
    var colorized = ""
    var bracket_color = ""
    for (i = 0; i < text.length; i++) {
        var index = Math.floor(Math.random()*5)
        if (text[i] == "(")
            bracket_color = colors[index]
    
        color = (bracket_color.length && (text[i] == "(" || text[i] == ")")) ? bracket_color : colors[index]
        colorized = colorized + '<span style="color: '+color+' !important">' + text.charAt(i) + '</span>'
    }
    return colorized
}

<!-- --></script>
