<?php
    /**
     * Class: Ticket
     * The Ticket model.
     *
     * See Also:
     *     <Model>
     */
    class Ticket extends Model {
        public $belongs_to = array("milestone", "user", "owner" => array("model" => "user"));
        public $has_many   = array("revisions",
                                   "attachments" => array("where" => array("entity_type" => "ticket",
                                                                           "entity_id" => "(id)")));

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($ticket_id, $options = array()) {
            if (!isset($ticket_id) and empty($options)) return;

            $options["left_join"][] = array("table" => "revisions",
                                            "where" => "ticket_id = tickets.id");

            $options["select"][] = "tickets.*";
            $options["select"][] = "COUNT(revisions.id) AS revision_count";
            $options["select"][] = "MAX(revisions.created_at) AS last_revision";
            $options["select"][] = "MAX(revisions.updated_at) AS last_update";

            $options["group"][] = "id";

            parent::grab($this, $ticket_id, $options);

            if ($this->no_results)
                return false;

            $this->last_activity = max(strtotime($this->last_revision), strtotime($this->last_update), strtotime($this->created_at), strtotime($this->updated_at));

            $this->done = in_array($this->state, array("resolved", "invalid", "declined"));

            $this->filtered = !isset($options["filter"]) or $options["filter"];

            $trigger = Trigger::current();

            if ($this->filtered) {
                $trigger->filter($this->title, array("markup_title", "markup_ticket_title"), $this);
                $trigger->filter($this->description, array("markup_text", "markup_ticket_text"), $this);
            }

            $trigger->filter($this, "ticket");
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            $options["left_join"][] = array("table" => "revisions",
                                            "where" => "ticket_id = tickets.id");

            $options["select"][] = "tickets.*";
            $options["select"][] = "COUNT(revisions.id) AS revision_count";
            $options["select"][] = "MAX(revisions.created_at) AS last_revision";
            $options["select"][] = "MAX(revisions.updated_at) AS last_update";

            if (!isset($options["done"]) or !$options["done"])
                $options["where"]["state"] = array("new", "open", "on-hold");

            $options["group"][] = "id";

            return parent::search(get_class(), $options, $options_for_object);
        }

        /**
         * Function: add
         * Adds a ticket to the database.
         *
         * Calls the add_ticket trigger with the inserted ticket.
         *
         * Parameters:
         *     $title - The title of the new ticket.
         *     $description - The description of the new ticket.
         *
         * Returns:
         *     $ticket - The newly created ticket.
         *
         * See Also:
         *     <update>
         */
        static function add($title,
                            $description,
                            $state      = "new",
                            $attachment = "",
                            $milestone  = 0,
                            $owner      = 0,
                            $user       = null,
                            $created_at = null,
                            $updated_at = "0000-00-00 00:00:00") {
            $milestone_id = ($milestone instanceof Milestone) ? $milestone->id : $milestone ;
            $owner_id = ($owner instanceof User) ? $owner->id : $owner ;
            $user_id = ($user instanceof User) ? $user->id : $user ;

            $sql = SQL::current();
            $visitor = Visitor::current();
            $trigger = Trigger::current();

            $sql->insert("tickets",
                         array("title"        => $title,
                               "description"  => $description,
                               "state"        => $state,
                               "clean"        => sanitize($title),
                               "url"          => self::check_url(sanitize($title)),
                               "attachment"   => $attachment,
                               "milestone_id" => $milestone_id,
                               "owner_id"     => $owner_id,
                               "user_id"      => oneof($user_id, $visitor->id),
                               "created_at"   => oneof($created_at, datetime()),
                               "updated_at"   => $updated_at));

            $ticket = new self($sql->latest());

            $trigger->call("add_ticket", $ticket);

            return $ticket;
        }

        /**
         * Function: update
         * Updates the ticket.
         *
         * Parameters:
         *     $title - The new title.
         *     $description - The new description.
         */
        public function update($title       = null,
                               $description = null,
                               $state       = null,
                               $attachment  = null,
                               $milestone   = null,
                               $owner       = null,
                               $user        = null,
                               $created_at  = null,
                               $updated_at  = null) {
            if ($this->no_results)
                return false;

            $sql = SQL::current();
            $trigger = Trigger::current();

            $old = clone $this;

            foreach (array("title", "description", "state", "attachment", "milestone_id", "owner_id", "user_id", "created_at", "updated_at") as $attr)
                if (substr($attr, -3) == "_id") {
                    $arg = ${substr($attr, 0, -3)};
                    $this->$attr = $$attr = oneof((($arg instanceof Model) ? $arg->id : $arg), $this->$attr);
                } elseif ($attr == "updated_at" and $$attr === null)
                    $this->$attr = $$attr = datetime();
                else
                    $this->$attr = $$attr = ($$attr !== null ? $$attr : $this->$attr);

            $sql->update("tickets",
                         array("id"           => $this->id),
                         array("title"        => $title,
                               "description"  => $description,
                               "state"        => $state,
                               "attachment"   => $attachment,
                               "milestone_id" => $milestone_id,
                               "owner_id"     => $owner_id,
                               "user_id"      => $user_id,
                               "created_at"   => $created_at,
                               "updated_at"   => $updated_at));

            $trigger->call("update_ticket", $this, $old);
        }

        /**
         * Function: delete
         * Deletes the given ticket, including its revisions and attachment. Calls the "delete_ticket" trigger and passes the <Ticket> as an argument.
         *
         * Parameters:
         *     $id - The ticket to delete.
         */
        static function delete($id) {
            $ticket = new self($id);

            foreach ($ticket->revisions as $revision)
                Revision::delete($revision->id);

            parent::destroy(get_class(), $id);

            if ($ticket->attachment)
                unlink(uploaded($ticket->attachment, false));
        }

        /**
         * Function: deletable
         * Checks if the <User> can delete the ticket.
         */
        public function deletable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("delete_ticket")) or
                   ($user->group->can("delete_own_ticket") and $this->user_id == $user->id);
        }

        /**
         * Function: editable
         * Checks if the <User> can edit the ticket.
         */
        public function editable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("edit_ticket")) or
                   ($user->group->can("edit_own_ticket") and $this->user_id == $user->id);
        }

        /**
         * Function: exists
         * Checks if a ticket exists.
         *
         * Parameters:
         *     $ticket_id - The ticket ID to check
         *
         * Returns:
         *     true - If a ticket with that ID is in the database.
         */
        static function exists($ticket_id) {
            return SQL::current()->count("tickets", array("id" => $ticket_id)) == 1;
        }

        /**
         * Function: check_url
         * Checks if a given clean URL is already being used as another ticket's URL.
         *
         * Parameters:
         *     $clean - The clean URL to check.
         *
         * Returns:
         *     $url - The unique version of the passed clean URL. If it's not used, it's the same as $clean. If it is, a number is appended.
         */
        static function check_url($clean) {
            $count = SQL::current()->count("tickets", array("clean" => $clean));
            return (!$count or empty($clean)) ? $clean : $clean."-".($count + 1) ;
        }

        /**
         * Function: url
         * Returns a ticket's URL.
         *
         * Parameters:
         *     $last_page - Link to the last page of the ticket?
         */
        public function url() {
            if ($this->no_results)
                return false;

            $config = Config::current();

            return ($config->clean_urls) ?
                       url("ticket/".$this->url) :
                       $config->url."/progress/?action=ticket&amp;url=".urlencode($this->url) ;
        }

        /**
         * Function: edit_link
         * Outputs an edit link for the ticket, if the <User.can> edit_ticket.
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

            echo $before.'<a href="'.Config::current()->chyrp_url.'/progress/?action=edit_ticket&amp;id='.$this->id.'" title="Edit" class="'.($classes ? $classes." " : '').'ticket_edit_link edit_link" id="ticket_edit_'.$this->id.'">'.$text.'</a>'.$after;
        }

        /**
         * Function: delete_link
         * Outputs a delete link for the ticket, if the <User.can> delete_ticket.
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

            echo $before.'<a href="'.Config::current()->chyrp_url.'/progress/?action=delete_ticket&amp;id='.$this->id.'" title="Delete" class="ticket_delete_link delete_link" id="'.($classes ? $classes." " : '').'ticket_delete_'.$this->id.'">'.$text.'</a>'.$after;
        }
    }
