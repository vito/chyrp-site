<?php
    /**
     * Class: Version
     * The Version model.
     *
     * See Also:
     *     <Model>
     */
    class Version extends Model {
        public $belongs_to = array("extension");
        public $has_many = array(
            "notes",
            "attachments" => array(
                "where" => array(
                    "entity_type" => "version",
                    "entity_id" => "(id)"
                )
            )
        );

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($version_id, $options = array()) {
            if (!isset($version_id) and empty($options)) return;

            $options["left_join"][] = array("table" => "notes",
                                            "where" => "version_id = versions.id");

            $options["select"][] = "versions.*";
            $options["select"][] = "COUNT(notes.id) AS note_count";
            $options["select"][] = "MAX(notes.created_at) AS last_note";

            $options["group"][] = "id";

            parent::grab($this, $version_id, $options);

            if ($this->no_results)
                return false;

            $this->compatible = (array) YAML::load($this->compatible);
            $this->tags = (!empty($this->tags) ? YAML::load($this->tags) : array());
            $this->linked_tags = self::link_tags($this->tags);

            $this->filtered = !isset($options["filter"]) or $options["filter"];

            $trigger = Trigger::current();

            if ($this->filtered) {
                if (!$this->extension->user->group->can("code_in_extensions"))
                    $this->description = strip_tags($this->description);

                $trigger->filter($this->description, array("markup_text", "markup_version_text"), $this);
            }

            $trigger->filter($this, "version");
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            $options["left_join"][] = array("table" => "notes",
                                            "where" => "version_id = versions.id");

            $options["select"][] = "versions.*";
            $options["select"][] = "COUNT(notes.id) AS note_count";
            $options["select"][] = "MAX(notes.created_at) AS last_note";

            $options["group"][] = "id";

            return parent::search(get_class(), $options, $options_for_object);
        }

        /**
         * Function: add
         * Adds a version to the database.
         *
         * Calls the add_version trigger with the inserted version.
         *
         * Parameters:
         *     $title - The title of the new version.
         *     $description - The description of the new version.
         *
         * Returns:
         *     $version - The newly created version.
         *
         * See Also:
         *     <update>
         */
        static function add($number,
                            $description = "",
                            $compatible  = array(),
                            $tags        = array(),
                            $filename    = "",
                            $image       = "",
                            $loves       = 0,
                            $downloads   = 0,
                            $extension   = null,
                            $created_at  = null,
                            $updated_at  = "0000-00-00 00:00:00") {
            $extension_id = ($extension instanceof Extension) ? $extension->id : $extension ;

            $sql = SQL::current();
            $visitor = Visitor::current();
            $trigger = Trigger::current();

            $tags = array_map("strip_tags", $tags);
            $compatible = array_map("strip_tags", $compatible);

            $tags = array_combine($tags, array_map("sanitize", $tags));

            $sql->insert("versions",
                         array("number"       => $number,
                               "description"  => $description,
                               "compatible"   => YAML::dump($compatible),
                               "tags"         => YAML::dump($tags),
                               "filename"     => $filename,
                               "image"        => $image,
                               "loves"        => $loves,
                               "downloads"    => $downloads,
                               "extension_id" => $extension_id,
                               "created_at"   => oneof($created_at, datetime()),
                               "updated_at"   => $updated_at));

            $version = new self($sql->latest());

            $trigger->call("add_version", $version);

            return $version;
        }

        /**
         * Function: update
         * Updates the version.
         *
         * Parameters:
         *     $title - The new title.
         *     $description - The new description.
         */
        public function update($number      = null,
                               $description = null,
                               $compatible  = null,
                               $tags        = null,
                               $filename    = null,
                               $image       = null,
                               $loves       = null,
                               $downloads   = null,
                               $extension   = null,
                               $created_at  = null,
                               $updated_at  = null) {
            if ($this->no_results)
                return false;

            $sql = SQL::current();
            $trigger = Trigger::current();

            $old = clone $this;

            foreach (array("number", "description", "compatible", "tags", "filename", "image", "loves", "downloads", "extension_id", "created_at", "updated_at") as $attr)
                if (substr($attr, -3) == "_id") {
                    $arg = ${substr($attr, 0, -3)};
                    $this->$attr = $$attr = oneof((($arg instanceof Model) ? $arg->id : $arg), $this->$attr);
                } elseif ($attr == "updated_at") {
                    if ($$attr === null)
                        $this->$attr = $$attr = datetime();
                    elseif ($$attr === false)
                        $this->$attr = $$attr = $this->$attr;
                    else
                        $this->$attr = $$attr;
                } else
                    $this->$attr = $$attr = ($$attr !== null ? $$attr : $this->$attr);

            $sql->update("versions",
                         array("id"           => $this->id),
                         array("number"       => $number,
                               "description"  => $description,
                               "compatible"   => (is_array($compatible) ? YAML::dump($compatible) : $compatible),
                               "tags"         => (is_array($tags) ? YAML::dump($tags) : $tags),
                               "filename"     => $filename,
                               "image"        => $image,
                               "loves"        => $loves,
                               "downloads"    => $downloads,
                               "extension_id" => $extension_id,
                               "created_at"   => $created_at,
                               "updated_at"   => $updated_at));

            $trigger->call("update_version", $this, $old);
        }

        /**
         * Function: delete
         * Deletes the given version, including its notes. Calls the "delete_version" trigger and passes the <Version> as an argument.
         *
         * Parameters:
         *     $id - The version to delete.
         */
        static function delete($id) {
            $version = new self($id);

            foreach ($version->notes as $note)
                Note::delete($note->id);

            parent::destroy(get_class(), $id);
        }

        /**
         * Function: deletable
         * Checks if the <User> can delete the version.
         */
        public function deletable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("delete_version")) or
                   ($user->group->can("delete_own_version") and $this->user_id == $user->id);
        }

        /**
         * Function: editable
         * Checks if the <User> can edit the version.
         */
        public function editable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("edit_version")) or
                   ($user->group->can("edit_own_version") and $this->user_id == $user->id);
        }

        /**
         * Function: exists
         * Checks if a version exists.
         *
         * Parameters:
         *     $version_id - The version ID to check
         *
         * Returns:
         *     true - If a version with that ID is in the database.
         */
        static function exists($version_id) {
            return SQL::current()->count("versions", array("id" => $version_id)) == 1;
        }

        /**
         * Function: check_url
         * Checks if a given clean URL is already being used as another version's URL.
         *
         * Parameters:
         *     $clean - The clean URL to check.
         *
         * Returns:
         *     $url - The unique version of the passed clean URL. If it's not used, it's the same as $clean. If it is, a number is appended.
         */
        static function check_url($clean) {
            $count = SQL::current()->count("versions", array("clean" => $clean));
            return (!$count or empty($clean)) ? $clean : $clean."-".($count + 1) ;
        }

        /**
         * Function: url
         * Returns a version's URL.
         *
         * Parameters:
         *     $last_page - Link to the last page of the version?
         */
        public function url() {
            if ($this->no_results)
                return false;

            $config = Config::current();

            return url("view/".$this->extension->url."/".$this->id);
        }

        /**
         * Function: edit_link
         * Outputs an edit link for the version, if the <User.can> edit_version.
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

            echo $before.'<a href="'.Config::current()->chyrp_url.'/extend/?action=edit_version&amp;id='.$this->id.'" title="Edit" class="'.($classes ? $classes." " : '').'version_edit_link edit_link" id="version_edit_'.$this->id.'">'.$text.'</a>'.$after;
        }

        /**
         * Function: delete_link
         * Outputs a delete link for the version, if the <User.can> delete_version.
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

            echo $before.'<a href="'.Config::current()->chyrp_url.'/extend/?action=delete_version&amp;id='.$this->id.'" title="Delete" class="version_delete_link delete_link" id="'.($classes ? $classes." " : '').'version_delete_'.$this->id.'">'.$text.'</a>'.$after;
        }

        private static function link_tags($tags) {
            $linked = array();
            foreach ($tags as $tag => $clean)
                $linked[] = '<a class="colorize" href="'.url("tag/".urlencode($clean)).'" rel="tag">'.$tag.'</a>';

            return $linked;
        }

        public function loved() {
            return (bool) SQL::current()->count("loves", array("version_id" => $this->id, "user_id" => Visitor::current()->id));
        }
    }
