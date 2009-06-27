<?php
    /**
     * Class: Type
     * The Type model.
     *
     * See Also:
     *     <Model>
     */
    class Type extends Model {
        public $has_many = "extensions";
        public $has_one = array(
            "latest_extension" => array(
                "model" => "extension",
                "where" => array("type_id" => "(id)"),
                "order" => array("created_at DESC", "id DESC"),
                "limit" => 1
            )
        );

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($type_id, $options = array()) {
            if (!isset($type_id) and empty($options)) return;

            $options["left_join"][] = array("table" => "extensions",
                                            "where" => "type_id = extensions.id");

            $options["select"][] = "types.*";
            $options["select"][] = "COUNT(extensions.id) AS extension_count";

            $options["group"][] = "id";

            parent::grab($this, $type_id, $options);

            if ($this->no_results)
                return false;

            $this->textID = $this->url; # Useful for Twig

            $this->filtered = !isset($options["filter"]) or $options["filter"];

            $trigger = Trigger::current();

            if ($this->filtered) {
                $trigger->filter($this->name, array("markup_title", "markup_type_name"), $this);
                $trigger->filter($this->description, array("markup_text", "markup_type_text"), $this);
            }

            $trigger->filter($this, "type");
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            $options["left_join"][] = array("table" => "extensions",
                                            "where" => "type_id = types.id");

            $options["select"][] = "types.*";
            $options["select"][] = "COUNT(extensions.id) AS extension_count";

            $options["group"][] = "id";

            return parent::search(get_class(), $options, $options_for_object);
        }

        /**
         * Function: add
         * Adds a type to the database.
         *
         * Calls the add_type trigger with the inserted type.
         *
         * Parameters:
         *     $name - The title of the new type.
         *     $description - The description of the new type.
         *
         * Returns:
         *     $type - The newly created type.
         *
         * See Also:
         *     <update>
         */
        static function add($name, $description, $color, $clean = null) {
            $sql = SQL::current();
            $sql->insert(
                "types",
                array(
                    "name" => $name,
                    "description" => $description,
                    "color" => $color,
                    "clean" => oneof($clean, sanitize($name))
                )
            );

            $type = new self($sql->latest());

            Trigger::current()->call("add_type", $type);

            return $type;
        }

        /**
         * Function: update
         * Updates the type.
         *
         * Parameters:
         *     $name - The new name.
         *     $description - The new description.
         */
        public function update($name = null, $description = null, $color = null, $clean = null) {
            if ($this->no_results)
                return false;

            $old = clone $this;

            $this->name        = ($name === null ? $this->name : $name);
            $this->description = ($description === null ? $this->description : $description);
            $this->color       = ($color === null ? $this->color : $color);
            $this->clean       = ($clean === null ? $this->clean : $clean);

            $sql = SQL::current();
            $sql->update(
                "type",
                array("id" => $this->id),
                array(
                    "name"        => $this->name,
                    "description" => $this->description,
                    "color"       => $this->color,
                    "clean"       => $this->clean
                )
            );

            Trigger::current()->call("update_type", $this, $old);
        }

        /**
         * Function: delete
         * Deletes the given type. Calls the "delete_type" trigger and passes the <Type> as an argument.
         *
         * Parameters:
         *     $id - The type to delete.
         */
        static function delete($id) {
            parent::destroy(get_class(), $id);
        }

        /**
         * Function: exists
         * Checks if a type exists.
         *
         * Parameters:
         *     $type_id - The type ID to check
         *
         * Returns:
         *     true - If a type with that ID is in the database.
         */
        static function exists($type_id) {
            return SQL::current()->count("types", array("id" => $type_id)) == 1;
        }

        /**
         * Function: check_url
         * Checks if a given clean URL is already being used as another type's URL.
         *
         * Parameters:
         *     $clean - The clean URL to check.
         *
         * Returns:
         *     $url - The unique version of the passed clean URL. If it's not used, it's the same as $clean. If it is, a number is appended.
         */
        static function check_url($clean) {
            $count = SQL::current()->count("types", array("clean" => $clean));
            return (!$count or empty($clean)) ? $clean : $clean."-".($count + 1) ;
        }

        /**
         * Function: url
         * Returns a type's URL.
         */
        public function url() {
            if ($this->no_results)
                return false;

            $config = Config::current();
            if (!$config->clean_urls)
                return $config->url."/extend/?action=type&amp;id=".$this->clean;

            return url("type/".$this->clean);
        }

        /**
         * Function: last_activity
         * Returns the latest message/updated message timestamp.
         */
        public function last_activity() {
            $last_activity = 0;

            foreach ($this->extensions as $extension) {
                $timestamp = max($extension->last_activity, strtotime($extension->updated_at), strtotime($extension->created_at));
                if ($timestamp > $last_activity)
                    $last_activity = $timestamp;
            }

            return $last_activity;
        }
    }
