<?php
    class Gravatar extends Modules {
        public function user_gravatar_attr(&$attr, $user) {
            $attr = "http://gravatar.com/avatar/".md5($user->email);
        }
    }
