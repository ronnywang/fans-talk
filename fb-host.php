<?php
include(__DIR__ .'/init.php');

if (array_key_exists('hub_mode', $_GET) and $_GET['hub_mode'] == 'subscribe' and $_GET['hub_verify_token'] == getenv('FB_TOKEN')) {
    echo $_GET['hub_challenge'];
    exit;
}

$body = file_get_contents('php://input');
$body = json_decode($body);
foreach ($body->entry as $entry) {
    foreach ($entry->messaging as $message) {
        if (!file_exists(__DIR__ . "/files/fb-{$message->sender->id}-write-seq")) {
            file_put_contents(__DIR__ . "/files/fb-{$message->sender->id}-write-seq", 0);
            $seq = 0;
        } else {
            $seq = intval(file_get_contents(__DIR__ . "/files/fb-{$message->sender->id}-write-seq"));
        }
        file_put_contents(__DIR__ . "/files/fb-{$message->sender->id}-{$seq}", json_encode($message));
        file_put_contents(__DIR__ . "/files/fb-{$message->sender->id}-write-seq", $seq + 1);
        file_put_contents(__DIR__ . "/files/fb.stats", microtime(true));
    }
}
