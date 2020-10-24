<?php

if (file_exists(__DIR__ . '/config.php')) {
    include(__DIR__ . '/config.php');
} elseif (file_exists('/srv/config/fantalk.php')) {
    include('/srv/config/fantalk.php');
}
