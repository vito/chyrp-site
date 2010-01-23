<?php
    /**
     * Class: Topic
     * The Topic model.
     *
     * See Also:
     *     <Model>
     */
    class Topic extends Model {
        public $belongs_to = array("forum", "user");
        public $has_many   = array("messages",
                                   "attachments" => array("where" => array("entity_type" => "topic",
                                                                           "entity_id" => "(id)")));
        public $has_one = array(
            "latest_message" => array(
                "model" => "message",
                "where" => array("topic_id" => "(id)"),
                "order" => array("created_at DESC", "id DESC"),
                "limit" => 1
            )
        );

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($topic_id, $options = array()) {
            if (!isset($topic_id) and empty($options)) return;

            $options["left_join"][] = array("table" => "messages",
                                            "where" => "topic_id = topics.id");

            $options["select"][] = "topics.*";
            $options["select"][] = "COUNT(messages.id) AS message_count";
            $options["select"][] = "MAX(messages.created_at) AS last_message";
            $options["select"][] = "MAX(messages.updated_at) AS last_update";

            fallback($options["order"], array("COALESCE(MAX(messages.created_at), topics.created_at) DESC", "id DESC"));

            $options["group"][] = "id";

            parent::grab($this, $topic_id, $options);

            if ($this->no_results)
                return false;

            $this->last_activity = max(strtotime($this->last_message), strtotime($this->last_update));

            $this->filtered = !isset($options["filter"]) or $options["filter"];

            $trigger = Trigger::current();

            if ($this->filtered) {
                if (!$this->user->group->can("code_in_topics")) {
                    $this->title = strip_tags($this->title);
                    $this->description = strip_tags($this->description);
                }

                $trigger->filter($this->title, array("markup_title", "markup_topic_title"), $this);
                $trigger->filter($this->description, array("markup_text", "markup_topic_text"), $this);
            }

            $trigger->filter($this, "topic");
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            $options["left_join"][] = array("table" => "messages",
                                            "where" => "topic_id = topics.id");

            $options["select"][] = "topics.*";
            $options["select"][] = "COUNT(messages.id) AS message_count";
            $options["select"][] = "MAX(messages.created_at) AS last_message";
            $options["select"][] = "MAX(messages.updated_at) AS last_update";

            fallback($options["order"], array("COALESCE(MAX(messages.created_at), topics.created_at) DESC", "id DESC"));

            $options["group"][] = "id";

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
                            $user_id    = null,
                            $created_at = null,
                            $updated_at = "0000-00-00 00:00:00") {
            $sql = SQL::current();
            $visitor = Visitor::current();
            $trigger = Trigger::current();

            $sql->insert("topics",
                         array("title"       => $title,
                               "description" => $description,
                               "clean"       => sanitize($title),
                               "url"         => self::check_url(sanitize($title)),
                               "forum_id"    => $forum_id,
                               "user_id"     => oneof($user_id, $visitor->id),
                               "created_at"  => oneof($created_at, datetime()),
                               "updated_at"  => $updated_at));

            $topic = new self($sql->latest());

            $trigger->call("add_topic", $topic);

			if (module_enabled("cacher"))
			    Modules::$instances["cacher"]->regenerate();

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
        public function update($title       = null,
                               $description = null,
                               $forum       = null,
                               $user        = null,
                               $created_at  = null,
                               $updated_at  = null) {
            if ($this->no_results)
                return false;

            $sql = SQL::current();
            $trigger = Trigger::current();

            $old = clone $this;

            foreach (array("title", "description", "forum_id", "user_id", "created_at", "updated_at") as $attr)
                if (substr($attr, -3) == "_id") {
                    $arg = ${substr($attr, 0, -3)};
                    $this->$attr = $$attr = oneof((($arg instanceof Model) ? $arg->id : $arg), $this->$attr);
                } elseif ($attr == "updated_at")
                    $this->$attr = $$attr = datetime();
                else
                    $this->$attr = $$attr = ($$attr === null ? $this->$attr : $$attr);

            $sql->update("topics",
                         array("id"          => $this->id),
                         array("title"       => $title,
                               "description" => $description,
                               "forum_id"    => $forum_id,
                               "user_id"     => $user_id,
                               "created_at"  => $created_at,
                               "updated_at"  => $updated_at));

			if (module_enabled("cacher"))
			    Modules::$instances["cacher"]->regenerate();

            $trigger->call("update_topic", $this, $old);
        }

        /**
         * Function: delete
         * Deletes the given topic. Calls the "delete_topic" trigger and passes the <Topic> as an argument.
         *
         * Parameters:
         *     $id - The topic to delete.
         */
        static function delete($id) {
            $topic = new self($id);

            foreach ($topic->message as $message)
                Message::delete($message->id);

            parent::destroy(get_class(), $id);

            foreach ($topic->attachments as $attachment)
                unlink(uploaded($attachment->path, false));

			if (module_enabled("cacher"))
			    Modules::$instances["cacher"]->regenerate();
        }

        /**
         * Function: deletable
         * Checks if the <User> can delete the topic.
         */
        public function deletable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("delete_topic")) or
                   ($user->group->can("delete_own_topic") and $this->user_id == $user->id);
        }

        /**
         * Function: editable
         * Checks if the <User> can edit the topic.
         */
        public function editable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("edit_topic")) or
                   ($user->group->can("edit_own_topic") and $this->user_id == $user->id);
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
         *
         * Parameters:
         *     $last_page - Link to the last page of the topic?
         */
        public function url() {
            if ($this->no_results)
                return false;

            return url("topic/".$this->url, DiscussController::current());
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
        public function edit_link($text = null, $before = null, $after = null, $classes = "") {
            if (!$this->editable())
                return false;

            fallback($text, __("Edit"));

            echo $before.'<a href="'.url("edit_topic/".$this->id, DiscussController::current()).'" title="Edit" class="'.($classes ? $classes." " : '').'topic_edit_link edit_link" id="topic_edit_'.$this->id.'">'.$text.'</a>'.$after;
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
        public function delete_link($text = null, $before = null, $after = null, $classes = "") {
            if (!$this->deletable())
                return false;

            fallback($text, __("Delete"));

            echo $before.'<a href="'.url("delete_topic/".$this->id, DiscussController::current()).'" title="Delete" class="topic_delete_link delete_link" id="'.($classes ? $classes." " : '').'topic_delete_'.$this->id.'">'.$text.'</a>'.$after;
        }

        /**
         * Function: view
         * Updates the view count of the topic.
         */
        public function view() {
            $this->view_count++;

            SQL::current()->update("topics",
                                   array("id" => $this->id),
                                   array("view_count" => $this->view_count));
        }
    }
