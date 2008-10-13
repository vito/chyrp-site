<?php
    /**
     * Class: Topic
     * The Topic model.
     *
     * See Also:
     *     <Model>
     */
    class Topic extends Model {
        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($topic_id, $options = array()) {
            if (!isset($topic_id) and empty($options)) return;
            parent::grab($this, $topic_id, $options);

            if ($this->no_results)
                return false;
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            return parent::search(get_class(), $options, $options_for_object);
        }

        /**
         * Function: add
         * Adds a topic to the database.
         *
         * Calls the add_topic trigger with the inserted topic.
         *
         * Parameters:
         *     $title - The title of the new topic.
         *     $description - The description of the new topic.
         *
         * Returns:
         *     $topic - The newly created topic.
         *
         * See Also:
         *     <update>
         */
        static function add($title,
                            $description,
                            $forum_id,
                            $user_id = null,
                            $created_at = null,
                            $updated_at = "0000-00-00 00:00:00") {
            $sql = SQL::current();
            $visitor = Visitor::current();
            $sql->insert("topics",
                         array("title" => $title,
                               "description" => $description,
                               "clean" => sanitize($title),
                               "url" => self::check_url(sanitize($title)),
                               "forum_id" => $forum_id,
                               "user_id" => fallback($user_id, $visitor->id),
                               "created_at" => fallback($created_at, datetime()),
                               "updated_at" => $updated_at));

            $topic = new self($sql->latest());

            Trigger::current()->call("add_topic", $topic);

            return $topic;
        }

        /**
         * Function: update
         * Updates the topic.
         *
         * Parameters:
         *     $title - The new title.
         *     $description - The new description.
         */
        public function update($title = null,
                               $description = null,
                               $forum_id = null,
                               $user_id = null,
                               $created_at = null,
                               $updated_at = null) {
            if ($this->no_results)
                return false;

            $sql = SQL::current();
            $sql->update("topics",
                         array("id"          => $this->id),
                         array("title"       => fallback($title, $this->title),
                               "description" => fallback($description, $this->description),
                               "forum_id"    => fallback($forum_id, $this->forum_id),
                               "user_id"     => fallback($user_id, $this->user_id),
                               "created_at"  => fallback($created_at, $this->created_at),
                               "updated_at"  => fallback($updated_at, $this->updated_at)));

            foreach (array("title", "description", "forum_id", "user_id", "created_at", "updated_at") as $attr)
                $this->$attr = $$attr;

            $trigger = Trigger::current();
            $trigger->call("update_topic", $this);
        }

        /**
         * Function: delete
         * Deletes the given topic. Calls the "delete_topic" trigger and passes the <Topic> as an argument.
         *
         * Parameters:
         *     $id - The topic to delete.
         */
        static function delete($id) {
            parent::destroy(get_class(), $id);
        }

        /**
         * Function: deletable
         * Checks if the <User> can delete the topic.
         */
        public function deletable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group()->can("delete_topic")) or
                   ($user->group()->can("delete_own_topic") and $this->user_id == $user->id);
        }

        /**
         * Function: editable
         * Checks if the <User> can edit the topic.
         */
        public function editable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group()->can("edit_topic")) or
                   ($user->group()->can("edit_own_topic") and $this->user_id == $user->id);
        }

        /**
         * Function: exists
         * Checks if a topic exists.
         *
         * Parameters:
         *     $topic_id - The topic ID to check
         *
         * Returns:
         *     true - If a topic with that ID is in the database.
         */
        static function exists($topic_id) {
            return SQL::current()->count("topics", array("id" => $topic_id)) == 1;
        }

        /**
         * Function: check_url
         * Checks if a given clean URL is already being used as another topic's URL.
         *
         * Parameters:
         *     $clean - The clean URL to check.
         *
         * Returns:
         *     $url - The unique version of the passed clean URL. If it's not used, it's the same as $clean. If it is, a number is appended.
         */
        static function check_url($clean) {
            $count = SQL::current()->count("topics", array("clean" => $clean));
            return (!$count or empty($clean)) ? $clean : $clean."-".($count + 1) ;
        }

        /**
         * Function: url
         * Returns a topic's URL.
         */
        public function url() {
            if ($this->no_results)
                return false;

            $config = Config::current();
            if (!$config->clean_urls)
                return $config->url."/bbs/?action=topic&amp;url=".urlencode($this->url);

            return url("topic/".$this->url);
        }

        /**
         * Function: messages
         * Returns a topic's messages.
         */
        public function messages($per_page = false) {
            if ($this->no_results)
                return false;

            $cache =& $this->messages[$per_page];
            if (isset($cache))
                return $cache;

            return $per_page ?
                       $cache = new Paginator(Message::find(array("where" => array("topic_id" => $this->id),
                                                                  "order" => "created_at ASC, id ASC",
                                                                  "placeholders" => true)),
                                              $per_page) :
                       $cache = Message::find(array("where" => array("topic_id" => $this->id),
                                                    "order" => "created_at ASC, id ASC")) ;
        }

        /**
         * Function: forum
         * Returns a topic's forum.
         */
        public function forum() {
            if ($this->no_results)
                return false;

            return new Forum($this->forum_id);
        }

        /**
         * Function: user
         * Returns a topic's creator.
         */
        public function user() {
            if ($this->no_results)
                return false;

            return new User($this->user_id);
        }

        /**
         * Function: edit_link
         * Outputs an edit link for the topic, if the <User.can> edit_topic.
         *
         * Parameters:
         *     $text - The text to show for the link.
         *     $before - If the link can be shown, show this before it.
         *     $after - If the link can be shown, show this after it.
         */
        public function edit_link($text = null, $before = null, $after = null){
            if (!$this->editable())
                return false;

            fallback($text, __("Edit"));

            echo $before.'<a href="'.Config::current()->chyrp_url.'/bbs/?action=edit_topic&amp;id='.$this->id.'" title="Edit" class="topic_edit_link edit_link" id="topic_edit_'.$this->id.'">'.$text.'</a>'.$after;
        }

        /**
         * Function: delete_link
         * Outputs a delete link for the topic, if the <User.can> delete_topic.
         *
         * Parameters:
         *     $text - The text to show for the link.
         *     $before - If the link can be shown, show this before it.
         *     $after - If the link can be shown, show this after it.
         */
        public function delete_link($text = null, $before = null, $after = null){
            if (!$this->deletable())
                return false;

            fallback($text, __("Delete"));

            echo $before.'<a href="'.Config::current()->chyrp_url.'/bbs/?action=delete_topic&amp;id='.$this->id.'" title="Delete" class="topic_delete_link delete_link" id="topic_delete_'.$this->id.'">'.$text.'</a>'.$after;
        }
    }
