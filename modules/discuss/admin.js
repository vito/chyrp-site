$(function(){
    $("table#manage_forums").tableDnD({
        onDrop: function(table, dropee){
            var ids = [];
            $.each(table.tBodies[0].rows, function() {
                ids.push($(this).attr("id").replace("forum_", ""));
            });

            $.post("../includes/ajax.php", { action: "reorder_forums", order: ids.join(",") });

            $(dropee).removeClass("last");
            $(table).find("tr:last").addClass("last");
        }
    });
});
