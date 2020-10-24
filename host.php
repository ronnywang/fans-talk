<?php

// Make sure composer dependencies have been installed
require __DIR__ . '/vendor/autoload.php';
include(__DIR__ . '/init.php');
date_default_timezone_set('Asia/Taipei');

class FanTalk implements Ratchet\MessageComponentInterface {
    protected $players;
    protected $player_seq = 0;

    public function __construct() {
        $this->players = new StdClass;
    }

    public function getQuestions()
    {
        $questions = new StdClass;
        $questions->{'2020092401'} = '你支不支持死刑';
        $questions->{'2020092402'} = '你贊不贊成無條件基本收入';
        $questions->{'2020102401'} = '你贊不贊成嚴格禁止餵食動物';
        return $questions;
    }

    public function log($message)
    {
        error_log(date('Y-m-d H:i:s') . ' ' . json_encode($message, JSON_UNESCAPED_UNICODE));
    }

    public function onOpen(Ratchet\ConnectionInterface $conn) {
        $this->player_seq ++;

        $conn->player_id = $this->player_seq;
        $conn->status = 'answering';
        $this->players->{$conn->player_id} = $conn;
        $conn->rejected = new StdClass;
        $conn->send(json_encode(array(
            'type' => 'welcome',
            'people_count' => count(get_object_vars($this->players)),
            'questions' => $this->getQuestions(),
        )));

        $this->log(array('t' => 'open', 'id' => $conn->player_id));
    }

    public function onMessage(Ratchet\ConnectionInterface $from, $msg) {
        if (!$obj = json_decode($msg)) {
            $from->send(json_encode(array('type' => 'error', 'message' => 'invalid JSON')));
            return;
        }

        if ($from->status == 'answering' or $from->status == 'pairing') {
            if ($obj->type == 'answer') {
                $this->log(array('t' => 'answer', 'player_id' => $from->player_id, 'answers' => $obj->answers));

                $from->answers = $obj->answers;
                $from->answered_at = microtime(true);
                $from->pairing_at = null;
                $from->accept = false;
                $from->status = 'pairing';
                $this->pair();

                $from->send(json_encode(array('type' => 'logined', 'id' => $from->player_id)));
                return;
            }
        } else if ($from->status == 'requesting') {
            if ($obj->type == 'accept') {
                $this->log(array('t' => 'accept', 'player_id' => $from->player_id));
                $from->accept = true;
                $target_player = $this->players->{$from->pair};
                if (!$target_player->accept) {
                    $target_player->send(json_encode(array(
                        'type' => 'matched',
                        'answers' => $target_player->answers,
                        'accepted' => true,
                    )));
                } else {
                    $player = $from;
                    foreach (array($player, $target_player) as $p) {
                        $p->status = 'chating';
                        $p->send(json_encode(array(
                            'type' => "start",
                        )));
                    }
                }
            } else if ($obj->type == 'reject') {
                $player = $from;
                $target_player = $this->players->{$from->pair};
                $player->rejected->{$target_player->player_id} = true;
                $target_player->rejected->{$player->player_id} = true;
                foreach (array($player, $target_player) as $p) {
                    $p->status = 'pairing';
                    $p->send(json_encode(array(
                        'type' => "cancelled",
                    )));
                }
                $this->pair();
            }
        } else if ($from->status == 'chating') {
            if ($obj->type == 'talk') {
                $this->log(array('t' => 'talk', 'player_id' => $from->player_id, 'message' => $obj->message));
                $target_player = $this->players->{$from->pair};
                $target_player->send(json_encode(array(
                    'type' => 'talk',
                    'message' => $obj->message,
                )));
            } else if ($obj->type == 'end') {
                $player = $from;
                $target_player = $this->players->{$from->pair};
                $player->rejected->{$target_player->player_id} = true;
                $target_player->rejected->{$player->player_id} = true;
                foreach (array($player, $target_player) as $p) {
                    $p->status = 'pairing';
                    $p->send(json_encode(array(
                        'type' => "cancelled",
                    )));
                }
                $this->pair();
            }
        } else {
            $from->send(json_encode(array('type' => 'error', 'message' => 'invalid status')));
            return;
        }

        $from->send(json_encode(array('type' => 'error', 'message' => 'invalid command')));
        return;
    }

    public function pair()
    {
        $questions = $this->getQuestions();

        foreach ($this->players as $player_id => $player) {
            if ($player->status != 'pairing') {
                continue;
            }
            foreach ($this->players as $target_player_id => $target_player) {
                if ($target_player_id == $player_id) {
                    continue;
                }
                if ($target_player->status != 'pairing') {
                    continue;
                }
                if (property_exists($target_player->rejected, $player_id)) {
                    continue;
                }
                if (property_exists($player->rejected, $target_player_id)) {
                    continue;
                }

                foreach ($questions as $question_id => $title) {
                    if ($player->answers->{$question_id} == -1 or $target_player->answers->{$question_id} == -1) {
                        continue;
                    }

                    if (abs($player->answers->{$question_id} - $target_player->answers->{$question_id}) >= 2) {
                        // paired
                        $player->status = 'requesting';
                        $target_player->status = 'requesting';
                        $player->pair = $target_player->player_id;
                        $target_player->pair = $player->player_id;
                        $player->accept = false;
                        $target_player->accept = false;
                        $player->send(json_encode(array(
                            'type' => 'matched',
                            'answers' => $target_player->answers,
                            'accept' => false,
                        )));
                        $target_player->send(json_encode(array(
                            'type' => 'matched',
                            'answers' => $player->answers,
                            'accept' => false,
                        )));

                        continue 3;
                    }
                }
            }

        }
    }

    public function onClose(Ratchet\ConnectionInterface $conn) {
        unset($this->players->{$conn->player_id});
    }

    public function onError(Ratchet\ConnectionInterface $conn, \Exception $e) {
        unset($this->players->{$conn->player_id});
        $conn->close();
    }

    public function cron()
    {
    }
}

// Run the server application through the WebSocket protocol on port 8080
$fantalk = new FanTalk;
$loop = React\EventLoop\Factory::create();
$loop->addPeriodicTimer(0.1, function() use ($fantalk) {
    $fantalk->cron();
});
error_log("Listen on port " . getenv('PORT'));
$app = new Ratchet\App('0.0.0.0', intval(getenv('PORT')), '127.0.0.1', $loop);
$app->route('/fantalk', $fantalk, array('*'));
$app->run();
