<?php
    /**
     * Class: Extension
     * The Extension model.
     *
     * See Also:
     *     <Model>
     */
    class Extension extends Model {
        public $belongs_to = array("type", "user");
        public $has_many   = array("versions");
        public $has_one    = array(
            "latest_version" => array(
                "model" => "version",
                "where" => array("extension_id" => "(id)"),
                "order" => array("created_at DESC", "id DESC"),
                "limit" => 1
            )
        );

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($extension_id, $options = array()) {
            if (!isset($extension_id) and empty($options)) return;

            $options["left_join"][] = array("table" => "versions",
                                            "where" => "extension_id = extensions.id");

            $options["select"][] = "extensions.*";
            $options["select"][] = "MAX(versions.created_at) AS last_update";

            $options["group"][] = "id";

            $options["order"] = array("__last_update", "extensions.id");

            parent::grab($this, $extension_id, $options);

            if ($this->no_results)
                return false;

            $this->textID = $this->url;

            $this->filtered = !isset($options["filter"]) or $options["filter"];

            $trigger = Trigger::current();

            if ($this->filtered) {
                if (!$this->user->group->can("code_in_extensions"))
                    $this->name = strip_tags($this->name);

                $trigger->filter($this->name, array("markup_title", "markup_extension_title"), $this);
            }

            $trigger->filter($this, "extension");
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            $options["left_join"][] = array("table" => "versions",
                                            "where" => "extension_id = extensions.id");

            $options["select"][] = "extensions.*";
            $options["select"][] = "MAX(versions.created_at) AS last_update";

            $options["group"][] = "id";

            $options["order"] = array("__last_update", "extensions.id");

            return parent::search(get_class(), $options, $options_for_object);
        }

        /**
         * Function: add
         * Adds a extension to the database.
         *
         * Calls the add_extension trigger with the inserted extension.
         *
         * Parameters:
         *     $title - The title of the new extension.
         *     $description - The description of the new extension.
         *
         * Returns:
         *     $extension - The newly created extension.
         *
         * See Also:
         *     <update>
         */
        static function add($name,
                            $clean = null,
                            $url = null,
                            $type = null,
                            $user = null) {
            $type_id = ($type instanceof Type) ? $type->id : $type ;
            $user_id = ($user instanceof User) ? $user->id : $user ;

            $sql = SQL::current();
            $visitor = Visitor::current();
            $trigger = Trigger::current();

            $clean = oneof($clean, sanitize($name));

            $sql->insert(
                "extensions",
                array(
                    "name"    => $name,
                    "clean"   => $clean,
                    "url"     => oneof($url, self::check_url($clean)),
                    "type_id" => $type_id,
                    "user_id" => oneof($user_id, $visitor->id)
                )
            );

            $extension = new self($sql->latest());

            $trigger->call("add_extension", $extension);

            return $extension;
        }

        /**
         * Function: update
         * Updates the extension.
         *
         * Parameters:
         *     $title - The new title.
         *     $description - The new description.
         */
        public function update($name = null,
                               $clean = null,
                               $url = null,
                               $type = null,
                               $user = null) {
            if ($this->no_results)
                return false;

            $sql = SQL::current();
            $trigger = Trigger::current();

            $old = clone $this;

            foreach (array("name", "clean", "url", "type_id", "user_id") as $attr)
                if (substr($attr, -3) == "_id") {
                    $arg = ${substr($attr, 0, -3)};
                    $this->$attr = $$attr = oneof((($arg instanceof Model) ? $arg->id : $arg), $this->$attr);
                } elseif ($attr == "updated_at" and $$attr === null)
                    $this->$attr = $$attr = datetime();
                else
                    $this->$attr = $$attr = ($$attr !== null ? $$attr : $this->$attr);

            $sql->update("extensions",
                         array("id"      => $this->id),
                         array("name"    => $name,
                               "clean"   => $clean,
                               "url"     => $url,
                               "type_id" => $type_id,
                               "user_id" => $user_id));

            $trigger->call("update_extension", $this, $old);
        }

        /**
         * Function: delete
         * Deletes the given extension, including its notes. Calls the "delete_extension" trigger and passes the <Extension> as an argument.
         *
         * Parameters:
         *     $id - The extension to delete.
         */
        static function delete($id) {
            $extension = new self($id);

            foreach ($extension->versions as $version)
                Version::delete($version->id);

            parent::destroy(get_class(), $id);
        }

        /**
         * Function: deletable
         * Checks if the <User> can delete the extension.
         */
        public function deletable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("delete_extension")) or
                   ($user->group->can("delete_own_extension") and $this->user_id == $user->id);
        }

        /**
         * Function: editable
         * Checks if the <User> can edit the extension.
         */
        public function editable($user = null) {
            if ($this->no_results)
                return false;

            fallback($user, Visitor::current());

            return ($user->group->can("edit_extension")) or
                   ($user->group->can("edit_own_extension") and $this->user_id == $user->id);
        }

        /**
         * Function: exists
         * Checks if a extension exists.
         *
         * Parameters:
         *     $extension_id - The extension ID to check
         *
         * Returns:
         *     true - If a extension with that ID is in the database.
         */
        static function exists($extension_id) {
            return SQL::current()->count("extensions", array("id" => $extension_id)) == 1;
        }

        /**
         * Function: check_url
         * Checks if a given clean URL is already being used as another extension's URL.
         *
         * Parameters:
         *     $clean - The clean URL to check.
         *
         * Returns:
         *     $url - The unique version of the passed clean URL. If it's not used, it's the same as $clean. If it is, a number is appended.
         */
        static function check_url($clean) {
            $count = SQL::current()->count("extensions", array("clean" => $clean));
            return (!$count or empty($clean)) ? $clean : $clean."-".($count + 1) ;
        }

        /**
         * Function: url
         * Returns a extension's URL.
         *
         * Parameters:
         *     $last_page - Link to the last page of the extension?
         */
        public function url() {
            if ($this->no_results)
                return false;

            $config = Config::current();

            return url("view/".$this->url, ExtendController::current());
        }
    }
