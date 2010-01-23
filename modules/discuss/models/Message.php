<?php
    /**
     * Class: Message
     * The Message model.
     *
     * See Also:
     *     <Model>
     */
    class Message extends Model {
        public $belongs_to = array("user", "topic");
        public $has_many   = array("attachments" => array("where" => array("entity_type" => "message",
                                                                           "entity_id" => "(id)")));

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($message_id, $options = array()) {
            if (!isset($message_id) and empty($options)) return;

            fallback($options["order"], array("created_at ASC", "id ASC"));

            parent::grab($this, $message_id, $options);

            if ($this->no_results)
                return false;

            $this->filtered = !isset($options["filter"]) or $options["filter"];

            $trigger = Trigger::current();

            if ($this->filtered) {
                if (!$this->user->group->can("code_in_messages"))
                    $this->body = fix($this->body);

                $trigger->filter($this->body, array("markup_text", "markup_message_text"), $this);
            }

            $trigger->filter($this, "message");
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            fallback($options["order"], array("created_at ASC", "id ASC"));
            return parent::search(get_class(), $options, $options_for_object);
        }

        /**
         * Function: add
         * Adds a message to the database.
         *
         * Calls the add_message trigger with the inserted message.
         *
         * Parameters:
         *     $title - The title of the new message.
         *     $description - The description of the new message.
         *
         * Returns:
         *     $message - The newly created message.
         *
         * See Also:
         *     <update>
         */
        static function add($body, $topic_id, $user_id = null, $created_at = null, $updated_at = "0000-00-00 00:00:00") {
            $sql = SQL::current();
            $visitor = Visitor::current();
            $sql->insert("messages",
                         array("body" => $body,
                               "topic_id" => $topic_id,
                               "user_id" => fallback($user_id, $visitor->id),
                               "created_at" => fallback($created_at, datetime()),
                               "updated_at" => $updated_at));

            $message = new self($sql->latest());

            Trigger::current()->call("add_message", $message);

			if (module_enabled("cacher"))
			    Modules::$instances["cacher"]->regenerate();

            return $message;
        }

        /**
         * Function: update
         * Updates the message.
         *
         * Parameters:
         *     $title - The new title.
         *     $description - The new description.
         */
        public function update($body       = null,
                               $topic      = null,
                               $user       = null,
                               $created_at = null,
                               $updated_at = null) {
            if ($this->no_results)
                return false;

            $old = clone $this;

            foreach (array("body", "topic_id", "user_id", "created_at", "updated_at") as $attr)
                if (substr($attr, -3) == "_id") {
                    $arg = ${substr($attr, 0, -3)};
                    $this->$attr = $$attr = oneof((($arg instanceof Model) ? $arg->id : $arg), $this->$attr);
                } elseif ($attr == "updated_at")
                    $this->$attr = $$attr = datetime();
                else
                    $this->$attr = $$attr = ($$attr === null ? $this->$attr : $$attr);

            $sql = SQL::current();
            $sql->update("messages",
                         array("id"         => $this->id),
                         array("body"       => $body,
                               "topic_id"   => $topic_id,
                               "user_id"    => $user_id,
                               "created_at" => $created_at,
                               "updated_at" => $updated_at));

			if (module_enabled("cacher"))
			    Modules::$instances["cacher"]->regenerate();

            Trigger::current()->call("update_message", $this, $old);
        }

        /**
         * Function: delete
         * Deletes the given message. Calls the "delete_message" trigger and passes the <Message> as an argument.
         *
         * Parameters:
         *     $id - The message to delete.
         */
        static function delete($id) {
            $message = new self($id);

            parent::destroy(get_class(), $id);

            foreach ($message->attachments as $attachment)
                unlink(uploaded($attachment->path, false));

			if (module_enabled("cacher"))
			    Modules::$instances["cacher"]->regenerate();
        }

        /**
         * Function: deletable
         * Checks if the <User> can delete the message.
         */
        public function deletable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("delete_message")) or
                   ($user->group->can("delete_own_message") and $this->user_id == $user->id);
        }

        /**
         * Function: editable
         * Checks if the <User> can edit the message.
         */
        public function editable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("edit_message")) or
                   ($user->group->can("edit_own_message") and $this->user_id == $user->id);
        }

        /**
         * Function: exists
         * Checks if a message exists.
         *
         * Parameters:
         *     $message_id - The message ID to check
         *
         * Returns:
         *     true - If a message with that ID is in the database.
         */
        static function exists($message_id) {
            return SQL::current()->count("messages", array("id" => $message_id)) == 1;
        }

        /**
         * Function: check_url
         * Checks if a given clean URL is already being used as another message's URL.
         *
         * Parameters:
         *     $clean - The clean URL to check.
         *
         * Returns:
         *     $url - The unique version of the passed clean URL. If it's not used, it's the same as $clean. If it is, a number is appended.
         */
        static function check_url($clean) {
            $count = SQL::current()->count("messages", array("clean" => $clean));
            return (!$count or empty($clean)) ? $clean : $clean."-".($count + 1) ;
        }

        /**
         * Function: url
         * Returns a message's URL.
         *
         * Parameters:
         *     $topic - Link to the post in the topic?
         */
        public function url($topic = true) {
            if ($this->no_results)
                return false;

            if ($topic) {
                $offset = 1;
                foreach ($this->topic->messages as $message)
                    if ($message->id != $this->id)
                        $offset++;
                    else
                        break;

                $page = ceil($offset / 25); # TODO: per-page config
                return url("topic/".$this->topic->url."/page/".$page, DiscussController::current())."#message_".$this->id;
            }

            return url("message/".$this->id, DiscussController::current());
        }

        /**
         * Function: edit_link
         * Outputs an edit link for the message, if the <User.can> edit_message.
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

            echo $before.'<a href="'.url("edit_message/".$this->id, DiscussController::current()).'" title="Edit" class="'.($classes ? $classes." " : '').'message_edit_link edit_link" id="message_edit_'.$this->id.'">'.$text.'</a>'.$after;
        }

        /**
         * Function: delete_link
         * Outputs a delete link for the message, if the <User.can> delete_message.
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

            echo $before.'<a href="'.url("delete_message/".$this->id, DiscussController::current()).'" title="Delete" class="'.($classes ? $classes." " : '').'message_delete_link delete_link" id="message_delete_'.$this->id.'">'.$text.'</a>'.$after;
        }
    }
