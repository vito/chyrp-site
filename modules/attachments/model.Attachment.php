<?php
    class Attachment extends Model {
        public $belongs_to = array("entity" => array("model" => "(entity_type)",
                                                     "where" => array("id" => "(entity_id)")));

        public function __construct($attachment_id, $options = array()) {
            parent::grab($this, $attachment_id, $options);

            if ($this->no_results)
                return false;

            $this->info = pathinfo($this->path);
        }

        static function find($options = array(), $options_for_object = array()) {
            return parent::search(get_class(), $options, $options_for_object);
        }

        static function add($filename, $path, $entity_type, $entity_id) {
            $sql = SQL::current();
            $trigger = Trigger::current();

            $sql->insert("attachments",
                         array("filename" => $filename,
                               "path" => $path,
                               "entity_type" => $entity_type,
                               "entity_id" => $entity_id));

            $attachment = new self($sql->latest());

            $trigger->call("add_attachment", $attachment);

            return $attachment;
        }

        public function update($filename = null,
                               $path = null,
                               $entity_type = null,
                               $entity_id = null) {
            if ($this->no_results)
                return false;

            $sql = SQL::current();
            $trigger = Trigger::current();

            $old = clone $this;

            foreach (array("filename", "path", "entity_type", "entity_id") as $attr)
                if ($attr == "updated_at" and $updated_at === null)
                    $this->updated_at = $updated_at = datetime();
                else
                    $this->$attr = $$attr = ($$attr === null ? $this->$attr : $$attr);

            $sql->update("attachments",
                         array("id" => $this->id),
                         array("filename" => $filename,
                               "path" => $path,
                               "entity_type" => $entity_type,
                               "entity_id" => $entity_id));

            $trigger->call("update_attachment", $this, $old);
        }

        static function delete($attachment_id, $file = true) {
            if ($file) {
                $attachment = new self($attachment_id);
                @unlink(uploaded($attachment->path, true));
            }

            parent::destroy(get_class(), $attachment_id);
        }

        static function exists($attachment_id) {
            return SQL::current()->count("attachments", array("id" => $attachment_id)) == 1;
        }

        public function thumbnail($width = 20, $height = 20) {
            if (!in_array(strtolower($this->info["extension"]),
                          array("png", "jpg", "jpeg", "gif")))
                return;

            echo '<img src="'.Config::current()->chyrp_url.'/includes/thumb.php?file=../uploads/'.$this->path.'&amp;max_width='.$width.'&amp;max_height='.$height.'" class="thumbnail" alt="attachment" />';
        }

        public function deletable($user = null) {
            if ($this->no_results)
                return false;

            return $this->entity->editable($user);
        }

        public function delete_link($text = null, $before = null, $after = null, $classes = "") {
            if (!$this->deletable())
                return false;

            fallback($text, __("Delete"));

            $name = strtolower(get_class($this));
            echo $before.'<a href="'.url("delete_attachment/".$this->id, MainController::current()).'" title="Delete" class="'.($classes ? $classes." " : '').$name.'_delete_link delete_link" id="'.$name.'_delete_'.$this->id.'">'.$text.'</a>'.$after;
        }
    }

