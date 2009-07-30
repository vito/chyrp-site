<?php
    /**
     * Class: Note
     * The Note model.
     *
     * See Also:
     *     <Model>
     */
    class Note extends Model {
        public $belongs_to = array("user", "version");
        public $has_many = array("attachments" => array("where" => array("entity_type" => "note",
                                                                         "entity_id" => "(id)")));

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($note_id, $options = array()) {
            if (!isset($note_id) and empty($options)) return;
            parent::grab($this, $note_id, $options);

            $options["order"] = "created_at ASC, id ASC";

            if ($this->no_results)
                return false;

            $this->filtered = !isset($options["filter"]) or $options["filter"];

            $trigger = Trigger::current();

            if ($this->filtered) {
                if (!$this->user->group->can("code_in_notes"))
                    $this->body = fix($this->body);

                $trigger->filter($this->body, array("markup_text", "markup_note_text"), $this);
            }

            $trigger->filter($this, "note");
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
         * Adds a note to the database.
         *
         * Calls the add_note trigger with the inserted note.
         *
         * Parameters:
         *     $body - The bodyitle of the new note.
         *     $version - The version the note belongs to.
         *     $user - The note's creator.
         *     $created_at - Creation timestamp.
         *     $updated_at - Updated timestamp.
         *
         * Returns:
         *     The newly created note.
         *
         * See Also:
         *     <update>
         */
        static function add($body,
                            $version,
                            $user       = null,
                            $created_at = null,
                            $updated_at = "0000-00-00 00:00:00") { 
            $version_id = ($version instanceof Version) ? $version->id : $version ;
            $user_id = ($user instanceof User) ? $user->id : $user ;

            $sql = SQL::current();
            $visitor = Visitor::current();
            $sql->insert("notes",
                         array("body"       => $body,
                               "version_id" => $version_id,
                               "user_id"    => fallback($user_id, $visitor->id),
                               "created_at" => fallback($created_at, datetime()),
                               "updated_at" => $updated_at));

            $note = new self($sql->latest());

            Trigger::current()->call("add_note", $note);

            return $note;
        }

        /**
         * Function: update
         * Updates the note.
         *
         * Parameters:
         *     $title - The new title.
         *     $description - The new description.
         */
        public function update($body       = null,
                               $version    = null,
                               $user       = null,
                               $created_at = null,
                               $updated_at = null) {
            if ($this->no_results)
                return false;

            $old = clone $this;

            foreach (array("body", "version_id", "user_id", "created_at", "updated_at") as $attr)
                if (substr($attr, -3) == "_id") {
                    $arg = ${substr($attr, 0, -3)};
                    $this->$attr = $$attr = oneof((($arg instanceof Model) ? $arg->id : $arg), $this->$attr);
                } elseif ($attr == "updated_at")
                    $this->$attr = $$attr = datetime();
                else
                    $this->$attr = $$attr = ($$attr === null ? $this->$attr : $$attr);

            $sql = SQL::current();
            $sql->update("notes",
                         array("id"         => $this->id),
                         array("body"       => $body,
                               "version_id" => $version_id,
                               "user_id"    => $user_id,
                               "created_at" => $created_at,
                               "updated_at" => $updated_at));

            Trigger::current()->call("update_note", $this, $old);
        }

        /**
         * Function: delete
         * Deletes the given note. Calls the "delete_note" trigger and passes the <Note> as an argument.
         *
         * Parameters:
         *     $id - The note to delete.
         */
        static function delete($id) {
            $note = new self($id);

            parent::destroy(get_class(), $id);

            foreach ($note->attachments as $attachment)
                unlink(uploaded($attachment->path, false));
        }

        /**
         * Function: deletable
         * Checks if the <User> can delete the note.
         */
        public function deletable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("delete_note")) or
                   ($user->group->can("delete_own_note") and $this->user_id == $user->id);
        }

        /**
         * Function: editable
         * Checks if the <User> can edit the note.
         */
        public function editable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("edit_note")) or
                   ($user->group->can("edit_own_note") and $this->user_id == $user->id);
        }

        /**
         * Function: exists
         * Checks if a note exists.
         *
         * Parameters:
         *     $note_id - The note ID to check
         *
         * Returns:
         *     true - If a note with that ID is in the database.
         */
        static function exists($note_id) {
            return SQL::current()->count("notes", array("id" => $note_id)) == 1;
        }

        /**
         * Function: url
         * Returns a note's URL.
         *
         * Parameters:
         *     $version - Link to the post in the version?
         */
        public function url($version = true) {
            if ($this->no_results)
                return false;

            $config = Config::current();

            if ($version) {
                $offset = 1;
                foreach ($this->version->notes as $note)
                    if ($note->id != $this->id)
                        $offset++;
                    else
                        break;

                $page = ceil($offset / 25); # TODO: per-page config
                return url("view/".$this->version->extension->url."/".$this->version->id."/page/".$page, ExtendController::current())."#note_".$this->id;
            }

            return url("note/".$this->id, ExtendController::current());
        }

        /**
         * Function: edit_link
         * Outputs an edit link for the note, if the <User.can> edit_note.
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

            echo $before.'<a href="'.url("edit_note/".$this->id, ExtendController::current()).'" title="Edit" class="'.($classes ? $classes." " : '').'note_edit_link edit_link" id="note_edit_'.$this->id.'">'.$text.'</a>'.$after;
        }

        /**
         * Function: delete_link
         * Outputs a delete link for the note, if the <User.can> delete_note.
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

            echo $before.'<a href="'.url("delete_note/".$this->id, ExtendController::current()).'" title="Delete" class="'.($classes ? $classes." " : '').'note_delete_link delete_link" id="note_delete_'.$this->id.'">'.$text.'</a>'.$after;
        }
    }
