<?php

include(__DIR__ . "/init.php");
require __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('Asia/Taipei');

$var = new StdClass;
$var->users = new StdClass;
$var->user_connections = new StdClass;
$var->user_status = new StdClass;
$var->user_messages = new StdClass;
$var->questions = new StdClass;
$var->fb_stats = 0;

function replyFb($recipient, $message) {
	$access_token = getenv('PAGE_TOKEN');
    $curl = curl_init('https://graph.facebook.com/v2.6/me/messages?access_token=' . urlencode($access_token));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    $json = (array(
        'recipient' => array('id' => $recipient),
        'message' => $message,
    ));
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json));
    $ret = curl_exec($curl);
    curl_close($curl);
	// TODO: handle error
};

function initUserData($data) {
	if (!property_exists($data, 'answers')) {
		$data->answers = new StdClass;
	}
	return $data;
}

foreach (glob(__DIR__ . "/files/*-write-seq") as $f) {
    $id = str_replace('-write-seq', '', basename($f));
	$var->users->{$id} = new StdClass;
    if (file_exists(__DIR__ . "/files/{$id}.data")) {
        $var->users->{$id}->data = json_decode(file_get_contents(__DIR__ . "/files/{$id}.data"));
    } else {
        $var->users->{$id}->data = new StdClass;
    }
	$var->users->{$id}->data = initUserData($var->users->{$id}->data);
}

$loop = \React\EventLoop\Factory::create();
$reactConnector = new \React\Socket\Connector($loop, [
    'dns' => '8.8.8.8',
    'timeout' => 10
]);
$connector = new \Ratchet\Client\Connector($loop, $reactConnector);

$connector(getenv('WEBSOCKET_HOST'))->then(function($conn) use ($loop, $reactConnector, &$var){
    $handle_messages = null;
    $handle_messages = function($id, $messages) use ($var, $loop, $reactConnector, &$handle_messages) {
        if (!property_exists($var->user_status, $id)) {
			$var->user_status->{$id} = 'connecting';
            $var->user_messages->{$id} = $messages;
            $connector = new \Ratchet\Client\Connector($loop, $reactConnector);
            $connector(getenv('WEBSOCKET_HOST'))->then(function($worker_conn) use ($loop, $reactConnector, &$var, $id, &$handle_messages){
                $var->user_connections->{$id} = $worker_conn;
				$var->user_status->{$id} = 'answering';
                // TODO: send answers to server
                error_log("client {$id} connected");
                $handle_messages($id, $var->user_messages->{$id});

				$worker_conn->on('message', function($msg) use ($worker_conn, &$var, $id) {
					$obj = json_decode(strval($msg));
					if ($obj->type == 'welcome') {
                    } else if ($obj->type == 'logined') {
                    } else if ($obj->type == 'talk') {
						replyFb(explode('-', $id)[1], [
                            'text' => "對方說：「" . $obj->message . "」",
                        ]);
                    } else if ($obj->type == 'end') {
                        $var->users->{$id}->chating = false;
						replyFb(explode('-', $id)[1], [
                            'text' => "您與對方的對話已經結束，繼續媒合配對中，您可隨時按下取消",
						]);

                    } else if ($obj->type == 'start') {
                        $var->users->{$id}->chating = true;
						replyFb(explode('-', $id)[1], [
                            'text' => "您與對方的對話已經開始，若想要結束對話，隨時可以輸入「結束對話」四個字",
						]);
                    } else if ($obj->type == 'cancelled') {
						replyFb(explode('-', $id)[1], [
                            'text' => "對方取消了媒合，繼續配對中，若您不想繼續等待可隨時按下不再等待",
							'quick_replies' => [
                                ['content_type' => 'text', 'title' => '不再等待', 'payload' => "match&cancel"],
							]
						]);

					} else if ($obj->type == 'matched') {
						$terms = array();
						$map = array(2 => "非常贊成", 1 => "贊成", 0 => "中立", -1 => "反對", -2 => "非常反對", "false" => "沒興趣");
						foreach ($var->questions as $qid => $title) {
							$terms[] = sprintf("%s: %s(您: %s)", $title, $map[$obj->answers->{$qid}], $map[$var->users->{$id}->data->answers->{$qid}]);
						}
						$result = implode("\n", $terms);
						replyFb(explode('-', $id)[1], [
							'text' => "配對成功，配對到一人回答如下：\n{$result}\n，請問您要與他對談嗎？",
							'quick_replies' => [
								['content_type' => 'text', 'title' => '要', 'payload' => "hit&yes"],
								['content_type' => 'text', 'title' => '不要', 'payload' => "hit&no"],
							]
						]);
                        return;
					} else {
						error_log("{$id} 得到 " . strval($msg));
					}
					
				});
            });
            return;
        }
        if ($var->user_status->{$id} == 'connecting') {
            $var->user_messages->{$id} = array_merge($var->user_messages->{$id}, $messages);
            return;
        }

		if (!property_exists($var->users, $id)) {
			$var->users->{$id} = new StdClass;
			if (file_exists(__DIR__ . "/files/{$id}.data")) {
				$var->users->{$id}->data = json_decode(file_get_contents(__DIR__ . "/files/{$id}.data"));
			} else {
				$var->users->{$id}->data = new StdClass;
			}
            $var->users->{$id}->chating = false;
		}
        $var->users->{$id}->data = initUserData($var->users->{$id}->data);

        $replies = array();
        foreach ($messages as $message) {
			if (property_exists($message->message, 'quick_reply')) {
				$payload = $message->message->quick_reply->payload;
				if (strpos($payload, 'ans&') === 0) {
					list(, $qid, $ans) = explode('&', $payload);
					$var->users->{$id}->data->answers->{$qid} = $ans;
				} else if (strpos($payload, 'match&cancel') === 0) {
					replyFb(explode('-', $id)[1], [
						'text' => "已收到您的取消，若您之後隨時想繼續配對，可以對我隨便說句話"
					]);
					return;
				} else if (strpos($payload, 'hit&yes') === 0) {
					// 同意媒合
                    $var->user_connections->{$id}->send(json_encode([
						"type" => "accept",
                    ]));
                    return;
				} else if (strpos($payload, 'hit&no') === 0) {
					// 不同意媒合
                    $var->user_connections->{$id}->send(json_encode([
						"type" => "reject",
                    ]));
                    return;
				} else if (strpos($payload, 'talk&end') === 0) {
                    $var->user_connections->{$id}->send(json_encode([
						"type" => "end",
                    ]));
				} else if (strpos($payload, 'match&start') === 0) {
					$var->user_connections->{$id}->send(json_encode([
						"type" => "answer",
						"answers" => $var->users->{$id}->data->answers,
					]));
					replyFb(explode('-', $id)[1], [
						'text' => "回答完成等待配對中，若超過十分鐘未配對成功會詢問您是否要再等待，如果你不想等待可以隨時按下去消配對",
						'quick_replies' => [
							['content_type' => 'text', 'title' => '不再等待', 'payload' => "match&cancel"],
						]
					]);
					return;
				}
            } else {
                error_log("user send " . json_encode($message, JSON_UNESCAPED_UNICODE));
                error_log(json_encode($var->users->{$id}));
                if ($var->users->{$id}->chating) {
                    $text = $message->message->text;
                    if ($text == '結束對話') {
                        replyFb(explode('-', $id)[1], [
                            'text' => "您確定要結束對話嗎？",
                            'quick_replies' => [
                                ['content_type' => 'text', 'title' => '結束對話', 'payload' => "talk&end"],
                            ]
                        ]);
                    } else {
                        error_log("說 {$text}");
                        $var->user_connections->{$id}->send(json_encode([
                            "type" => "talk",
                            "message" => $text,
                        ]));
                    }
                    return;
                }
			}
        }
		
		// check if all questions are answered
		if ($var->user_status->{$id} == 'answering') {
			$all_answered = true;
			foreach ($var->questions as $qid => $title) {
				if (!property_exists($var->users->{$id}->data->answers, $qid)) {
					replyFb(explode('-', $id)[1], [
						'text' => "{$title}？",
						'quick_replies' => [
							['content_type' => 'text', 'title' => '非常支持', 'payload' => "ans&{$qid}&2"],
							['content_type' => 'text', 'title' => '支持', 'payload' => "ans&{$qid}&1"],
							['content_type' => 'text', 'title' => '中立', 'payload' => "ans&{$qid}&0"],
							['content_type' => 'text', 'title' => '反對', 'payload' => "ans&{$qid}&-1"],
							['content_type' => 'text', 'title' => '非常反對', 'payload' => "ans&{$qid}&-2"],
							['content_type' => 'text', 'title' => '沒興趣', 'payload' => "ans&{$qid}&false"],
						]
					]);
					$all_answered = false;
					break;
				}
			}
			if ($all_answered) {
				replyFb(explode('-', $id)[1], [
					'text' => "問題已回覆完畢，請問您要開始媒合了嗎？",
					'quick_replies' => [
						['content_type' => 'text', 'title' => '馬上開始', 'payload' => "match&start"],
						['content_type' => 'text', 'title' => '修但幾累', 'payload' => "match&stop"],
					]
				]);
			}
		}
		//replyFb(explode('-', $id)[1], ["text" => "收到了 " . json_encode($message->message)]);

    };

    $loop->addPeriodicTimer(0.1, function() use ($conn, &$prev_time, &$var, $handle_messages) {
        if (file_get_contents(__DIR__ . "/files/fb.stats") == $var->fb_stats) {
            return;
        }
		error_log("fb.stats changed");
        $prev_fb_stats = $var->fb_stats;
        $var->fb_stats = file_get_contents(__DIR__ . "/files/fb.stats");
        foreach (glob(__DIR__ . "/files/*-write-seq") as $f) {
            $id = str_replace('-write-seq', '', basename($f));
            $wrote_at = filemtime($f);
            if (file_exists(__DIR__ . "/files/{$id}-read-seq") and filemtime(__DIR__ . "/files/{$id}-read-seq") == $wrote_at) {
                continue;
            }
            $write_seq = intval(file_get_contents(__DIR__ . "/files/{$id}-write-seq"));
            if (file_exists(__DIR__ . "/files/{$id}-read-seq")) {
                $read_seq = intval(file_get_contents(__DIR__ . "/files/{$id}-read-seq"));
            } else {
                $read_seq = 0;
            }
            $messages = array();
            for ($seq = $read_seq; $seq < $write_seq; $seq ++) {
                $f = __DIR__ . "/files/{$id}-{$seq}";
                $messages[] = json_decode(file_get_contents($f));
				unlink($f);
            }
			file_put_contents(__DIR__ . "/files/{$id}-read-seq", $seq);
			touch(__DIR__ . "/files/{$id}-read-seq", $wrote_at);
            $handle_messages($id, $messages);
        }
    });

    $conn->on('message', function($msg) use ($conn, &$var) {
        $obj = json_decode(strval($msg));

        if ($obj->type == 'welcome') {
            $var->questions = $obj->questions;
        }
    });

    error_log(date('Y-m-d H:i:s') . " joined");
}, function(\Exception $e) use ($loop) {
    echo "Could not connect: {$e->getMessage()}\n";
    $loop->stop();

});

$loop->run();
