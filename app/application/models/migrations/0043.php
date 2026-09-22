<?php

$c = new Content();

$this->db->query("ALTER TABLE {$c->table} MODIFY filesize BIGINT NOT NULL");

$done = true;
