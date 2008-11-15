<?php
    /**
     * Class: Revision
     * The Revision model.
     *
     * See Also:
     *     <Model>
     */
    class Revision extends Model {
        public $belongs_to = array("user", "ticket");

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($revision_id, $options = array()) {
            if (!isset($revision_id) and empty($options)) return;
            parent::grab($this, $revision_id, $options);

            $options["order"] = "created_at ASC, id ASC";

            if ($this->no_results)
                return false;

            $this->changes = YAML::load($this->changes);

            $this->filtered = !isset($options["filter"]) or $options["filter"];

            $trigger = Trigger::current();

            if ($this->filtered) {
                if (!$this->user->group->can("code_in_revisions"))
                    $this->body = strip_tags($this->body);

                $trigger->filter($this->body, array("markup_text", "markup_revision_text"), $this);
            }

            $trigger->filter($this, "revision");
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            $options["order"] = "created_at ASC, id ASC";
            return parent::search(get_class(), $options, $options_for_object);
        }

        /**
         * Function: add
         * Adds a revision to the database.
         *
         * Calls the add_revision trigger with the inserted revision.
         *
         * Parameters:
         *     $body - The bodyitle of the new revision.
         *     $changes - key => val array of changes made to the ticket.
         *     $ticket - The ticket the revision belongs to.
         *     $user - The revision's creator.
         *     $created_at - Creation timestamp.
         *     $updated_at - Updated timestamp.
         *
         * Returns:
         *     The newly created revision.
         *
         * See Also:
         *     <update>
         */
        static function add($body,
                            $changes,
                            $ticket,
                            $user       = null,
                            $created_at = null,
                            $updated_at = "0000-00-00 00:00:00") { 
            $ticket_id = ($ticket instanceof Ticket) ? $ticket->id : $ticket ;
            $user_id = ($user instanceof User) ? $user->id : $user ;

            $sql = SQL::current();
            $visitor = Visitor::current();
            $sql->insert("revisions",
                         array("body" => $body,
                               "changes" => YAML::dump($changes),
                               "ticket_id" => $ticket_id,
                               "user_id" => fallback($user_id, $visitor->id),
                               "created_at" => fallback($created_at, datetime()),
                               "updated_at" => $updated_at));

            $revision = new self($sql->latest());

            Trigger::current()->call("add_revision", $revision);

            return $revision;
        }

        /**
         * Function: update
         * Updates the revision.
         *
         * Parameters:
         *     $title - The new title.
         *     $description - The new description.
         */
        public function update($body       = null,
                               $changes    = null,
                               $ticket     = null,
                               $user       = null,
                               $created_at = null,
                               $updated_at = null) {
            if ($this->no_results)
                return false;

            $old = clone $this;

            foreach (array("body", "changes", "ticket_id", "user_id", "created_at", "updated_at") as $attr)
                if (substr($attr, -3) == "_id") {
                    $arg = ${substr($attr, 0, -3)};
                    $this->$attr = $$attr = oneof((($arg instanceof Model) ? $arg->id : $arg), $this->$attr);
                } elseif ($attr == "updated_at")
                    $this->$attr = $$attr = datetime();
                else
                    $this->$attr = fallback($$attr, $this->$attr);

            $sql = SQL::current();
            $sql->update("revisions",
                         array("id"         => $this->id),
                         array("body"       => $body,
                               "changes"    => YAML::dump($changes),
                               "ticket_id"  => $ticket_id,
                               "user_id"    => $user_id,
                               "created_at" => $created_at,
                               "updated_at" => $updated_at));

            Trigger::current()->call("update_revision", $this, $old);
        }

        /**
         * Function: delete
         * Deletes the given revision. Calls the "delete_revision" trigger and passes the <Revision> as an argument.
         *
         * Parameters:
         *     $id - The revision to delete.
         */
        static function delete($id) {
            parent::destroy(get_class(), $id);
        }

        /**
         * Function: deletable
         * Checks if the <User> can delete the revision.
         */
        public function deletable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("delete_revision")) or
                   ($user->group->can("delete_own_revision") and $this->user_id == $user->id);
        }

        /**
         * Function: editable
         * Checks if the <User> can edit the revision.
         */
        public function editable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("edit_revision")) or
                   ($user->group->can("edit_own_revision") and $this->user_id == $user->id);
        }

        /**
         * Function: exists
         * Checks if a revision exists.
         *
         * Parameters:
         *     $revision_id - The revision ID to check
         *
         * Returns:
         *     true - If a revision with that ID is in the database.
         */
        static function exists($revision_id) {
            return SQL::current()->count("revisions", array("id" => $revision_id)) == 1;
        }

        /**
         * Function: check_url
         * Checks if a given clean URL is already being used as another revision's URL.
         *
         * Parameters:
         *     $clean - The clean URL to check.
         *
         * Returns:
         *     $url - The unique version of the passed clean URL. If it's not used, it's the same as $clean. If it is, a number is appended.
         */
        static function check_url($clean) {
            $count = SQL::current()->count("revisions", array("clean" => $clean));
            return (!$count or empty($clean)) ? $clean : $clean."-".($count + 1) ;
        }

        /**
         * Function: url
         * Returns a revision's URL.
         *
         * Parameters:
         *     $ticket - Link to the post in the ticket?
         */
        public function url($ticket = true) {
            if ($this->no_results)
                return false;

            $config = Config::current();

            if ($ticket) {
                $offset = 1;
                foreach ($this->ticket->revisions as $revision)
                    if ($revision->id != $this->id)
                        $offset++;
                    else
                        break;

                $page = ceil($offset / 25); # TODO: per-page config
                return ($config->clean_urls) ?
                           url("ticket/".$this->ticket->url."/page/".$page)."#revision_".$this->id :
                           $config->url."/progress/?action=ticket&amp;url=".urlencode($this->ticket->url)
                               ."&amp;page=".$page."#revision_".$this->id ;
            }

            return ($config->clean_urls) ?
                       url("revision/".$this->id) :
                       $config->url."/progress/?action=revision&amp;id=".$this->id ;
        }

        /**
         * Function: edit_link
         * Outputs an edit link for the revision, if the <User.can> edit_revision.
         *
         * Parameters:
         *     $text - The text to show for the link.
         *     $before - If the link can be shown, show this before it.
         *     $after - If the link can be shown, show this after it.
         *     $classes - Extra CSS classes for the link, space-delimited.
         */
        public function edit_link($text = null, $before = null, $after = null, $classes = "") {
            if (!$this->editable())
                return false;

            fallback($text, __("Edit"));

            echo $before.'<a href="'.Config::current()->chyrp_url.'/progress/?action=edit_revision&amp;id='.$this->id.'" title="Edit" class="'.($classes ? $classes." " : '').'revision_edit_link edit_link" id="revision_edit_'.$this->id.'">'.$text.'</a>'.$after;
        }

        /**
         * Function: delete_link
         * Outputs a delete link for the revision, if the <User.can> delete_revision.
         *
         * Parameters:
         *     $text - The text to show for the link.
         *     $before - If the link can be shown, show this before it.
         *     $after - If the link can be shown, show this after it.
         *     $classes - Extra CSS classes for the link, space-delimited.
         */
        public function delete_link($text = null, $before = null, $after = null, $classes = "") {
            if (!$this->deletable())
                return false;

            fallback($text, __("Delete"));

            echo $before.'<a href="'.Config::current()->chyrp_url.'/progress/?action=delete_revision&amp;id='.$this->id.'" title="Delete" class="'.($classes ? $classes." " : '').'revision_delete_link delete_link" id="revision_delete_'.$this->id.'">'.$text.'</a>'.$after;
        }
    }
