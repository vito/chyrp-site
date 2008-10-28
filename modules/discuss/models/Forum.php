<?php
    /**
     * Class: Forum
     * The Forum model.
     *
     * See Also:
     *     <Model>
     */
    class Forum extends Model {
        public $has_many = "topics";

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($forum_id, $options = array()) {
            if (!isset($forum_id) and empty($options)) return;

            $options["left_join"][] = array("table" => "topics",
                                            "where" => "forum_id = forums.id");

            $options["select"][] = "forums.*";
            $options["select"][] = "COUNT(topics.id) AS topic_count";

            $options["group"][] = "id";

            parent::grab($this, $forum_id, $options);

            if ($this->no_results)
                return false;

            $this->filtered = !isset($options["filter"]) or $options["filter"];

            $trigger = Trigger::current();

            if ($this->filtered) {
                $trigger->filter($this->name, array("markup_title", "markup_topic_title"), $this);
                $trigger->filter($this->description, array("markup_text", "markup_topic_text"), $this);
            }

            $trigger->filter($this, "forum");
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            $options["left_join"][] = array("table" => "topics",
                                            "where" => "forum_id = forums.id");

            $options["select"][] = "forums.*";
            $options["select"][] = "COUNT(topics.id) AS topic_count";

            $options["group"][] = "id";

            return parent::search(get_class(), $options, $options_for_object);
        }

        /**
         * Function: add
         * Adds a forum to the database.
         *
         * Calls the add_forum trigger with the inserted forum.
         *
         * Parameters:
         *     $name - The title of the new forum.
         *     $description - The description of the new forum.
         *
         * Returns:
         *     $forum - The newly created forum.
         *
         * See Also:
         *     <update>
         */
        static function add($name, $description) {
            $sql = SQL::current();
            $sql->insert("forums",
                         array("name" => $name,
                               "description" => $description));

            $forum = new self($sql->latest());

            Trigger::current()->call("add_forum", $forum);

            return $forum;
        }

        /**
         * Function: update
         * Updates the forum.
         *
         * Parameters:
         *     $name - The new name.
         *     $description - The new description.
         */
        public function update($name, $description) {
            if ($this->no_results)
                return false;

            $old = clone $this;

            $this->name        = $name;
            $this->description = $description;

            $sql = SQL::current();
            $sql->update("forums",
                         array("id"          => $this->id),
                         array("name"        => $name,
                               "description" => $description));

            Trigger::current()->call("update_forum", $this, $old);
        }

        /**
         * Function: delete
         * Deletes the given forum. Calls the "delete_forum" trigger and passes the <Forum> as an argument.
         *
         * Parameters:
         *     $id - The forum to delete.
         */
        static function delete($id) {
            parent::destroy(get_class(), $id);
        }

        /**
         * Function: exists
         * Checks if a forum exists.
         *
         * Parameters:
         *     $forum_id - The forum ID to check
         *
         * Returns:
         *     true - If a forum with that ID is in the database.
         */
        static function exists($forum_id) {
            return SQL::current()->count("forums", array("id" => $forum_id)) == 1;
        }

        /**
         * Function: check_url
         * Checks if a given clean URL is already being used as another forum's URL.
         *
         * Parameters:
         *     $clean - The clean URL to check.
         *
         * Returns:
         *     $url - The unique version of the passed clean URL. If it's not used, it's the same as $clean. If it is, a number is appended.
         */
        static function check_url($clean) {
            $count = SQL::current()->count("forums", array("clean" => $clean));
            return (!$count or empty($clean)) ? $clean : $clean."-".($count + 1) ;
        }

        /**
         * Function: url
         * Returns a forum's URL.
         */
        public function url() {
            if ($this->no_results)
                return false;

            $config = Config::current();
            if (!$config->clean_urls)
                return $config->url."/discuss/?action=forum&amp;url=".urlencode($this->url);

            return url("forum/".$this->url);
        }

        /**
         * Function: last_activity
         * Returns the latest message/updated message timestamp.
         */
        public function last_activity() {
            $last_activity = 0;

            foreach ($this->topics as $topic) {
                $timestamp = max($topic->last_activity, strtotime($topic->updated_at), strtotime($topic->created_at));
                if ($timestamp > $last_activity)
                    $last_activity = $timestamp;
            }

            return $last_activity;
        }

        /**
         * Function: message_count
         * Returns the total messages of every topic in the forum.
         *
         * Parameters:
         *     $inclusive - Count topics as messages?
         */
        public function message_count($inclusive = false) {
            $messages = 0;

            foreach ($this->topics as $topic)
                $messages += ($inclusive ? $topic->message_count + 1 : $topic->message_count);

            return $messages;
        }
    }