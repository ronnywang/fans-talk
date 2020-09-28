/* global $, JitsiMeetJS */

var options = {
	hosts: {
		domain: 'meet.jit.si',
		muc: 'conference.meet.jit.si', // FIXME: use XEP-0030
		focus: 'focus.meet.jit.si',
	},
	bosh: 'wss://meet.jit.si/xmpp-websocket', // FIXME: use xep-0156 for that
	websocket: 'wss://meet.jit.si/xmpp-websocket',

	// The name of client node advertised in XEP-0115 'c' stanza
	clientNode: 'http://jitsi.org/jitsimeet'
};

const initOptions = {
    disableAudioLevels: true
};

var main_connection = null;
var main_room_id = 'fantalkmainroomvtaiwan';
var main_room = null;
var my_status = 'answering';
var inviting_user = null;
var room_id = null;
var pair_room_id = null;

var connect_main_jitsi = function(){
    JitsiMeetJS.setLogLevel(JitsiMeetJS.logLevels.ERROR);
    JitsiMeetJS.init(initOptions);
	options.bosh = 'wss://meet.jit.si/xmpp-websocket?room=' + main_room_id;
    main_connection = new JitsiMeetJS.JitsiConnection(null, null, options);

    var d = new Promise(function(resolve, reject){
        main_connection.addEventListener(
            JitsiMeetJS.events.connection.CONNECTION_ESTABLISHED,
            resolve
            );
        main_connection.addEventListener(
            JitsiMeetJS.events.connection.CONNECTION_FAILED,
            reject);
        main_connection.addEventListener(
            JitsiMeetJS.events.connection.CONNECTION_DISCONNECTED,
            function(){
            });

        main_connection.connect();
    }.bind(this));
    return d;
};

var join_main_room = function(){
    var confOptions = {
        openBridgeChannel: 'websocket',
        confID: '',
    };
    confOptions.confID = 'meet.jit.si' + '/' + main_room_id;
    main_room = main_connection.initJitsiConference(main_room_id, confOptions);
	updateMyStatus('answering');

    main_room.on(
        JitsiMeetJS.events.conference.CONFERENCE_JOINED,
        function(){
            main_room.on(JitsiMeetJS.events.conference.USER_JOINED, (id, user) => {
                update_main_user_list();
            });
            main_room.on(JitsiMeetJS.events.conference.DATA_CHANNEL_OPENED, () => {
                update_main_user_list();
            });
            main_room.on(JitsiMeetJS.events.conference.USER_LEFT, (id, user) => {
                update_main_user_list();
            });
            main_room.on(JitsiMeetJS.events.conference.PARTICIPANT_PROPERTY_CHANGED, (user, text, ts) => {
                if (['user-status', 'answers'].indexOf(text) >= 0) {
                    update_main_user_list();
                }
            });
            main_room.on(JitsiMeetJS.events.conference.ENDPOINT_MESSAGE_RECEIVED, (participant, message) => {
				if (message.type == 'invite-ask') {
					handle_invite(participant);
				} else if (message.type == 'accept-ask') {
                    handle_accept_ask(participant);
                } else if (message.type == 'reject-invite') {
					reject_invite(participant.getId());
				} else if (message.type == 'accept-invite') {
					handle_accept_invite(participant.getId(), message.room_id);
				}
            });
            main_room.on(JitsiMeetJS.events.conference.DISPLAY_NAME_CHANGED, (id, name) => {
                update_main_user_list();
            });
    });
    main_room.join();
};

var update_main_user_list = function(){
	$('#number').text(main_room.getParticipants().length + 1);
	if (my_status == 'pairing') {
		game_pair();
	}
};

var my_answers = {};

var update_question_list = function(questions){
	if (localStorage.getItem('answers')) {
		try {
			my_answers = JSON.parse(localStorage.getItem('answers'));
		} catch (e) { }
	}
    for (var id in questions) {
		var question_dom = $($('#tmpl-question').html());
		question_dom.data('id', id);
		$('.question-text', question_dom).text(questions[id][0]);
		if ('undefined' !== typeof(my_answers[id])) {
			ans = my_answers[id];
			if (ans === false) {
				ans = 'false';
			}
			$('button[data-ans="' + ans + '"]', question_dom).addClass('btn-primary');
		}
		$('#question-list').append(question_dom);
	}
	update_answers();
};

var update_answers = function(){
	for (var id in questions) {
		if ('undefined' === typeof(my_answers[id])) {
			// 有答案還未回答就不配對
			return;
		}
	}
	if (!main_room) {
		return;
	}
	if (my_status == 'answering' || my_status == 'pairing') {
		updateMyStatus('pairing');
        main_room.setLocalParticipantProperty('pairing_time', (new Date).getTime());
		main_room.setLocalParticipantProperty('answers', JSON.stringify(my_answers));
		game_pair();
	}
};

// 配對
var rejected = {};
var game_pair = function(){
	$('#status').text('配對中');
	if (!main_room) {
		return;
	}
	var matching_users = [];
	for (var user of main_room.getParticipants()) {
		var user_status = user.getProperty('user-status');
		if ('undefined' !== typeof(rejected[user.getId()])) {
			continue;
		}
		if (user_status != 'pairing') {
			continue;
		}
		try {
			var user_answers = JSON.parse(user.getProperty('answers'));
		} catch (e) {
			continue;
		}
		if ('object' !== typeof(user_answers)) {
			continue;
		}
		for (var id in questions) {
			if ('undefined' === typeof(user_answers) || user_answers[id] == 'false' || my_answers[id] == 'false') {
				continue;
			}
			if (Math.abs(user_answers[id] - my_answers[id]) >= 2) {
				matching_users.push(user);
			}
		}
	}
	if (matching_users.length == 0) {
		$('#status').text('未配對到，等待更多人加入');
		return;
	}

    matching_users = matching_users.sort(function(a, b) {
        return a.getProperty('pairing_time') - b.getProperty('pairing_time');
    });
	var matching_user = matching_users[0];
	
	$('#status').text('配對到，詢問意願中');
	updateMyStatus('asking');
    try {
        main_room.sendEndpointMessage(matching_user.getId(), {type: 'invite-ask'});
    } catch (e) {
        console.log('data is not open, retry');
        updateMyStatus('pairing');
        return;
    }
	inviting_user = matching_user.getId();
	pair_room_id = room_id = '';
};

var updateMyStatus = function(status){
	my_status = status;
	main_room.setLocalParticipantProperty('user-status', my_status);
};

var handle_invite = function(participant){
    if (my_status == 'pairing' || (my_status == 'asking' && inviting_user == participant.getId())) {
        prompt_invite(participant);
        main_room.sendEndpointMessage(participant.getId(), {type: 'accept-ask'});
        if (my_status == 'pairing') {
            updateMyStatus('asking');
            inviting_user = participant.getId();
			pair_room_id = room_id = '';
        }
    } else {
        main_room.sendEndpointMessage(participant.getId(), {type: 'reject-ask'});
    }
};

var handle_accept_ask = function(participant){
    if (my_status == 'asking' && inviting_user == participant.getId()) {
        prompt_invite(participant);
    }
};

var prompt_invite = function(participant){
    $('#status').text('請問您願意跟 ' + participant.getId()+ ' 配對嗎？');
	$('#prompt-invite').show();
	$('#prompt-invite #prompt-invite-answers').html('');
	var map = {'2': '非常贊成', '1': '贊成', '0': '中立', '-1': '反對', '-2': '非常反對', 'false': '沒興趣'};
	user_answers = JSON.parse(participant.getProperty('answers'));
	for (var id in questions) {
		$('#prompt-invite #prompt-invite-answers').append($('<p></p>').text(questions[id][0] + ': ' + map[user_answers[id]]));
	}
	$('#invite-status').text('等待回應中');
};

var handle_accept_invite = function(participantId, id) {
	if (participantId != inviting_user) {
		return;
	}
	pair_room_id = id;
	$('#invite-status').text('對方已同意，就等你了');

	if (room_id) {
		$('#invite-status').text('雙方都同意了');
		enter_chat_room();
	}
};

var accept_invite = function(participantId){
	if (participantId != inviting_user) {
		return;
	}
	room_id = Math.random(100, 999);
	main_room.sendEndpointMessage(inviting_user, {type: 'accept-invite', room_id: room_id});
	$('#invite-status').text('您已同意，就等對方了');
	if (pair_room_id) {
		handle_accept_invite(inviting_user, pair_room_id);
	}
};

var reject_invite = function(participantId){
	if (participantId != inviting_user) {
		return;
	}
	main_room.sendEndpointMessage(inviting_user, {type: 'reject-invite'});
	$('#prompt-invite').hide();
	updateMyStatus('pairing');
	rejected[inviting_user] = true;
	inviting_user = null;
	room_id = '';

	game_pair();
};

var chat_connection = null;
var chat_room_id = 'fantalkmainroomvtaiwan';
var chat_room = null;

var enter_chat_room = function(){
    updateMyStatus('talking');
	connect_chat_jitsi().then(function(ret){
		return join_chat_room();
	});
};

var connect_chat_jitsi = function(){
	chat_room_id = 'fantalkroomvtaiwan' + [main_room.myUserId(), inviting_user, room_id, pair_room_id].sort().join('');

	options.bosh = 'wss://meet.jit.si/xmpp-websocket?room=' + chat_room_id;
    chat_connection = new JitsiMeetJS.JitsiConnection(null, null, options);

    var d = new Promise(function(resolve, reject){
        chat_connection.addEventListener(
            JitsiMeetJS.events.connection.CONNECTION_ESTABLISHED,
            resolve
            );
        chat_connection.addEventListener(
            JitsiMeetJS.events.connection.CONNECTION_FAILED,
            reject);
        chat_connection.addEventListener(
            JitsiMeetJS.events.connection.CONNECTION_DISCONNECTED,
            function(){
            });

        chat_connection.connect();
    }.bind(this));
    return d;
};

var join_chat_room = function(){
    var confOptions = {
        openBridgeChannel: 'websocket',
        confID: '',
    };
    confOptions.confID = 'meet.jit.si' + '/' + chat_room_id;
    chat_room = chat_connection.initJitsiConference(chat_room_id, confOptions);

    chat_room.on(
        JitsiMeetJS.events.conference.CONFERENCE_JOINED,
        function(){
            chat_room.on(JitsiMeetJS.events.conference.USER_JOINED, (id, user) => {
            });
            chat_room.on(JitsiMeetJS.events.conference.USER_LEFT, (id, user) => {
                // TODO: except viewer
                close_chat();
            });
            chat_room.on(JitsiMeetJS.events.conference.MESSAGE_RECEIVED, (id, text, ts, nick, other) => {
                var nick;
                if (id == chat_room.myUserId()) {
                    nick = '我';
                } else {
                    nick = '對方';
                }
                add_chat_message(text, nick, ts);
            });
            $('#area-chat').show();
            $('#area-main').hide();
            $('#chat-area').html('');
        }
    );
    chat_room.join();
};

var close_chat = function(){
    chat_room.leave();
    chat_connection.disconnect();
    $('#area-chat').hide();
    $('#area-main').show();
    $('#prompt-invite').hide();
    updateMyStatus('pairing');
	$('#status').text('配對中');
};

