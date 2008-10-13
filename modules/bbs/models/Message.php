<?php
    /**
     * Class: Message
     * The Message model.
     *
     * See Also:
     *     <Model>
     */
    class Message extends Model {
        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($message_id, $options = array()) {
            if (!isset($message_id) and empty($options)) return;
            parent::grab($this, $message_id, $options);

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
        public function update($body = null, $topic_id = null, $user_id = null, $created_at = null, $updated_at = null) {
            if ($this->no_results)
                return false;

            $sql = SQL::current();
            $sql->update("messages",
                         array("id" => $this->id),
                         array("body"       => fallback($body, $this->body),
                               "topic_id"   => fallback($topic_id, $this->topic_id),
                               "user_id"    => fallback($user_id, $this->user_id),
                               "created_at" => fallback($created_at, $this->created_at),
                               "updated_at" => fallback($updated_at, datetime())));

            $trigger = Trigger::current();
            $trigger->call("update_message", $this);
        }

        /**
         * Function: delete
         * Deletes the given message. Calls the "delete_message" trigger and passes the <Message> as an argument.
         *
         * Parameters:
         *     $id - The message to delete.
         */
        static function delete($id) {
            parent::destroy(get_class(), $id);
        }

        /**
         * Function: deletable
         * Checks if the <User> can delete the message.
         */
        public function deletable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group()->can("delete_message")) or
                   ($user->group()->can("delete_own_message") and $this->user_id == $user->id);
        }

        /**
         * Function: editable
         * Checks if the <User> can edit the message.
         */
        public function editable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group()->can("edit_message")) or
                   ($user->group()->can("edit_own_message") and $this->user_id == $user->id);
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
         */
        public function url() {
            if ($this->no_results)
                return false;

            $config = Config::current();
            if (!$config->clean_urls)
                return $config->url."/bbs/?action=message&amp;url=".urlencode($this->url);

            return url("message/".$this->url);
        }

        /**
         * Function: topic
         * Returns a message's topic.
         */
        public function topic() {
            if ($this->no_results)
                return false;

            return new Topic($this->topic_id);
        }

        /**
         * Function: user
         * Returns a message's creator.
         */
        public function user() {
            if ($this->no_results)
                return false;

            return new User($this->user_id);
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
        public function edit_link($text = null, $before = null, $after = null){
            if (!$this->editable())
                return false;

            fallback($text, __("Edit"));

            echo $before.'<a href="'.Config::current()->chyrp_url.'/bbs/?action=edit_message&amp;id='.$this->id.'" title="Edit" class="message_edit_link edit_link" id="message_edit_'.$this->id.'">'.$text.'</a>'.$after;
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
        public function delete_link($text = null, $before = null, $after = null){
            if (!$this->deletable())
                return false;

            fallback($text, __("Delete"));

            echo $before.'<a href="'.Config::current()->chyrp_url.'/bbs/?action=delete_message&amp;id='.$this->id.'" title="Delete" class="message_delete_link delete_link" id="message_delete_'.$this->id.'">'.$text.'</a>'.$after;
        }
    }
