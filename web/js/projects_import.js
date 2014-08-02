(function($, window) {

    var tpl_nav_project = Mustache.compile($('#tpl-nav-projects').text());
    var tpl_nav_project_item = Mustache.compile($('#tpl-project-nav').text());
    var tpl_project_link = Mustache.compile($('#tpl-project-link').text());
    var tpl_project_button = Mustache.compile($('#tpl-project-button').text());

    $(function() {
        if (typeof(primus) === 'undefined') { return; }

        var tpl_import = Mustache.compile($('#tpl-import').text());

        function on(event, callback) {
            primus.on('data', function(data) {
                // console.log(data);
                if (data.event == event) {
                    callback(data.data);
                }
            });
        }

        on('import.start', function(data) {
            $('#candidate-' + data.project_slug + ' button').addClass('btn-success');
            $('#progress').html(tpl_import(data));
        });

        on('import.step', function(data) {
            $('#steps li.running')
                .removeClass('running')
                .addClass('done')
                .find('i')
                    .removeClass()
                    .addClass('fa fa-check');

            $('#steps li#' + data.step)
                .removeClass('pending')
                .addClass('running')
                    .find('i')
                        .removeClass()
                        .addClass('fa fa-refresh fa-spin');
        });

        on('import.finished', function(data) {
            $('#steps li.running')
                .removeClass('running')
                .addClass('done')
                .find('i')
                    .removeClass()
                    .addClass('fa fa-check');

            $('#organisations button.btn-import')
                .not('#candidate-' + data.project_slug + ' button')
                .not('.btn-success')
                .not('.btn-info')
                .attr('disabled', false);

            $('#candidate-' + data.project_slug + ' button.btn-import i').removeClass().addClass('fa fa-check');

            // globaly resubscribing will automatically subscribe to the newly created project
            primus.subscribe();

            var project_link = tpl_project_link({ url: data.project_url, name: data.project_full_name });
            var project_button = tpl_project_button({ url: data.project_url });

            $('#project-import-footer').append(project_button);

            if ($('#nav-projects').length == 0) {
                $('#sidebar').prepend(tpl_nav_project());
            }

            $('#nav-projects').append(tpl_nav_project_item({ link: project_link }));
        });
    });

    $('#organisations').on('click', '.candidate button.btn-join', function() {
        $(this).html('<i class="fa fa-refresh fa-spin"></i>').attr('disabled', 'disabled');

        $.ajax({
            url: $(this).data('join-url'),
            type: 'POST',
            dataType: 'json',
            context: $(this).parent().parent()
        }).fail(function(jqXHR, textStatus, errorThrown) {
            try {
                var message = JSON.parse(jqXHR.responseJSON).message;
            } catch (e) {
                var message = 'An unexpected error has occured (' + e.message + ')';
            }

            $('button', this).html('<i class="fa fa-times"></i> ' + message).addClass('btn-danger');
        }).then(function(data) {
            data = JSON.parse(data);

            if (data.status == 'ok') {
                $('button', this).addClass('btn-success').html('<i class="fa fa-check"></i>');

                var project_link = tpl_project_link({ url: data.project_url, name: data.project_full_name });

                if ($('#nav-projects').length == 0) {
                    $('#sidebar').prepend(tpl_nav_project());
                }

                $('#nav-projects').append(tpl_nav_project_item({ link: project_link }));
            }
        });
    });

    function doImport(button, force) {
        $(button).html('<i class="fa fa-refresh fa-spin"></i>').attr('disabled', 'disabled');
        $('#organisations button.btn-import').attr('disabled', 'disabled');

        var inputs = $(button).parent().find('input:hidden');
        var data = {};

        inputs.each(function(index, item) {
            data[item.name] = item.value;
        });

        var tpl_project_nav  = Mustache.compile($('#tpl-project-nav').text());
        var tpl_project_link = Mustache.compile($('#tpl-project-link').text());

        $.ajax({
            url: import_url + '?force=' + (force ? '1' : '0'),
            type: 'POST',
            dataType: 'json',
            data: data,
            context: $(button).parent().parent()
        }).then(function(data) {
            var data = JSON.parse(data);

            if (typeof(data.ask_scope) !== 'undefined') {
                $('#btn-import-force').data('target', data.autostart);

                var link = $('#ask_scope a#grant');

                link.attr('href', link.data('href')
                    .replace(encodeURI('%scope%'), data.ask_scope)
                    .replace(encodeURI('%autostart%'), data.autostart));

                $('#ask_scope').modal();
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            try {
                var message = jqXHR.responseJSON.message;
            } catch (e) {
                var message = 'An unexpected error has occured (' + e.message + ')';
            }

            $('button', button)
                .html('<i class="fa fa-times"></i> ' + message)
                .addClass('btn-danger');                

            $('#organisations button.btn-import')
                .not('.btn-danger')
                .attr('disabled', null);            
        });
    }

    $('#btn-import-force').on('click', function() {
        doImport($('.btn-import', '#candidate-' + $(this).data('target')), true);
        $('#ask_scope').modal('hide');
    });

    $('#organisations').on('click', '.candidate button.btn-import', function() {
        doImport(this, false);
    });
})(jQuery, window);