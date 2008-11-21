<?php
    /**
     * Class: Milestone
     * The Milestone model.
     *
     * See Also:
     *     <Model>
     */
    class Milestone extends Model {
        public $has_many = "tickets";

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($milestone_id, $options = array()) {
            if (!isset($milestone_id) and empty($options)) return;

            $options["left_join"][] = array("table" => "tickets",
                                            "where" => "milestone_id = milestones.id");

            $options["select"][] = "milestones.*";
            $options["select"][] = "COUNT(tickets.id) AS ticket_count";

            $options["group"][] = "id";

            parent::grab($this, $milestone_id, $options);

            if ($this->no_results)
                return false;

            $this->filtered = !isset($options["filter"]) or $options["filter"];

            if ($this->ticket_count)
                $this->percentage = (100 - ($this->open_tickets() / $this->ticket_count * 100));
            else
                $this->percentage = 0;

            $trigger = Trigger::current();

            if ($this->filtered) {
                $trigger->filter($this->name, array("markup_title", "markup_milestone_name"), $this);
                $trigger->filter($this->description, array("markup_text", "markup_milestone_text"), $this);
            }

            $trigger->filter($this, "milestone");
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            $options["left_join"][] = array("table" => "tickets",
                                            "where" => "milestone_id = milestones.id");

            $options["select"][] = "milestones.*";
            $options["select"][] = "COUNT(tickets.id) AS ticket_count";

            $options["group"][] = "id";

            return parent::search(get_class(), $options, $options_for_object);
        }

        /**
         * Function: add
         * Adds a milestone to the database.
         *
         * Calls the add_milestone trigger with the inserted milestone.
         *
         * Parameters:
         *     $name - The title of the new milestone.
         *     $description - The description of the new milestone.
         *
         * Returns:
         *     $milestone - The newly created milestone.
         *
         * See Also:
         *     <update>
         */
        static function add($name, $description, $due = "0000-00-00 00:00:00") {
            $sql = SQL::current();
            $sql->insert("milestones",
                         array("name" => $name,
                               "description" => $description,
                               "due" => $due));

            $milestone = new self($sql->latest());

            Trigger::current()->call("add_milestone", $milestone);

            return $milestone;
        }

        /**
         * Function: update
         * Updates the milestone.
         *
         * Parameters:
         *     $name - The new name.
         *     $description - The new description.
         */
        public function update($name = null, $description = null, $due = null) {
            if ($this->no_results)
                return false;

            $old = clone $this;

            $this->name        = ($name === null ? $this->name : $name);
            $this->description = ($description === null ? $this->description : $description);
            $this->due         = ($due === null ? $this->due : $due);

            $sql = SQL::current();
            $sql->update("milestones",
                         array("id"          => $this->id),
                         array("name"        => $this->name,
                               "description" => $this->description,
                               "due"         => $this->due));

            Trigger::current()->call("update_milestone", $this, $old);
        }

        /**
         * Function: delete
         * Deletes the given milestone. Calls the "delete_milestone" trigger and passes the <Milestone> as an argument.
         *
         * Parameters:
         *     $id - The milestone to delete.
         */
        static function delete($id) {
            parent::destroy(get_class(), $id);
        }

        /**
         * Function: exists
         * Checks if a milestone exists.
         *
         * Parameters:
         *     $milestone_id - The milestone ID to check
         *
         * Returns:
         *     true - If a milestone with that ID is in the database.
         */
        static function exists($milestone_id) {
            return SQL::current()->count("milestones", array("id" => $milestone_id)) == 1;
        }

        /**
         * Function: check_url
         * Checks if a given clean URL is already being used as another milestone's URL.
         *
         * Parameters:
         *     $clean - The clean URL to check.
         *
         * Returns:
         *     $url - The unique version of the passed clean URL. If it's not used, it's the same as $clean. If it is, a number is appended.
         */
        static function check_url($clean) {
            $count = SQL::current()->count("milestones", array("clean" => $clean));
            return (!$count or empty($clean)) ? $clean : $clean."-".($count + 1) ;
        }

        /**
         * Function: url
         * Returns a milestone's URL.
         */
        public function url() {
            if ($this->no_results)
                return false;

            $config = Config::current();
            if (!$config->clean_urls)
                return $config->url."/progress/?action=milestone&amp;id=".$this->id;

            return url("milestone/".$this->url);
        }

        /**
         * Function: last_activity
         * Returns the latest message/updated message timestamp.
         */
        public function last_activity() {
            $last_activity = 0;

            foreach ($this->tickets as $ticket) {
                $timestamp = max($ticket->last_activity, strtotime($ticket->updated_at), strtotime($ticket->created_at));
                if ($timestamp > $last_activity)
                    $last_activity = $timestamp;
            }

            return $last_activity;
        }

        /**
         * Function: open_tickets
         * Returns the total number of open tickets in the milestone.
         */
        public function open_tickets() {
            return SQL::current()->count("tickets",
                                         array("milestone_id" => $this->id,
                                               "state" => array("new", "open")));
        }
    }
