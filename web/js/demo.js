function demo_websocket_listen(websocket_channel) {

    console.log('listening for demo build on channel "' + websocket_channel + '"');

    var primus = Primus.connect('http://' + document.location.hostname + ':8090/', {
        privatePattern: /(project|user)\.\d+/,
        auth_url: websocket_auth_url,
    });

    var lastTimestamp = 0;

    primus.on('open', function() {
        primus.subscribe(websocket_channel);
    });

    primus.on('data', function(message) {
        console.log(message);

        if (!message.data.build && message.event !== 'build.output.buffer') { //} || message.data.build.id !== current_build_id) {
            console.log('invalid message', message);
            return;
        }

        console.log('received event "' + message.event + '"');

        processMessage(message);
    });


    function processMessage(message) {
        if (message.event === 'build.output.buffer') {
            for (i in message.data) {
                processMessage(message.data[i]);
            }
        }

        if (message.data.progress) {
            progress = message.data.progress;
        } else {
            progress = 100;
        }

        $('#build-progress .bar').css('width', progress + '%');

        if (message.event === 'build.scheduled') {
            var content = Mustache.render($('#tpl-steps').text(), { 'steps': message.data.steps });
            $('#build-steps').html(content);

            var content = Mustache.render($('#tpl-meta').text(), { 'project': message.data.project.name });
            $('#build-meta').html(content);

            $('#form-build').hide();

            $('#steps li').tooltip();

            return;
        }

        if (message.event === 'build.started') {
            $('#steps li.pending:first')
                .removeClass('pending')
                .addClass('running')
                .find('i')
                    .removeClass()
                    .addClass('icon-refresh icon-spin');
            return;
        }

        if (message.event === 'build.finished') {
            if (message.data.build.status_label === 'failed') {
                $('#build-meta').html(Mustache.render($('#tpl-failed').text()));
                $('#build-steps').remove();
            } else {
                $('#steps li.running')
                    .removeClass('running')
                    .addClass('done')
                    .find('i')
                        .removeClass()
                        .addClass('icon-ok');

                var url = Mustache.render($('#tpl-url').text(), { 'url': message.data.build.url });
                $('#build-url').html(url);                
            }
            
            return;
        }

        if (message.event !== 'build.step') {
            return;
        }

        console.log('received step "' + message.data.announce.step + '"');


        $('#steps li.running')
            .not('#' + message.data.announce.step)
            .removeClass('running')
            .prevAll('li')
                .removeClass()
            .addBack()
                .addClass('done')
                .find('i')
                    .removeClass()
                    .addClass('icon-ok');

        $('#steps li#' + message.data.announce.step)
            .removeClass('pending')
            .addClass('running')
                .find('i')
                    .removeClass()
                    .addClass('icon-refresh icon-spin');
    }
}