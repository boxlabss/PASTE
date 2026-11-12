//
// Paste 3 <old repo: https://github.com/boxlabss/PASTE>  new: https://github.com/boxlabss/PASTE
// demo: https://paste.boxlabs.uk/
// https://phpaste.sourceforge.io/  -  https://sourceforge.net/projects/phpaste/
//
// Licensed under GNU General Public License, version 3 or later.
// See LICENCE for details.

if (typeof jQuery === 'undefined') {
    console.error('jQuery failed to load from CDN. Please check your network or ad blocker.');
} else {
    console.log('jQuery loaded successfully.');
}

$(document).ready(function() {
    console.log('Document ready. Binding form handlers.');

    // Handle database configuration form submission
    $('#db-form').on('submit', function(e) {
        e.preventDefault();
        $('#install').hide();
        $('#configure').hide();
        $('#pre_load').show();
        $('#alertfailed').hide();
        $('#admin-alertfailed').hide(); // Explicitly hide admin error

        $.ajax({
            url: 'configure.php',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                console.log('configure.php response:', response);
                $('#pre_load').hide();
                if (response.status === 'success') {
                    $('#configure').show();
                    $('#logpanel').show();
                    $('#log').html(response.message);
                } else {
                    $('#install').show();
                    $('#alertfailed').show();
                    $('#error-details').text(response.message || 'Unknown error occurred.');
                }
            },
            error: function(xhr, status, error) {
                console.error('configure.php AJAX error:', status, error, xhr.responseText);
                $('#pre_load').hide();
                $('#install').show();
                $('#alertfailed').show();
                $('#error-details').text('Configuration failed: ' + (xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : status + ' - ' + error));
            }
        });
    });

    // Show the upload options only when uploads are being turned on
    $('#img_enabled').on('change', function() {
        $('.img-opt').prop('hidden', $(this).val() !== 'yes');
    }).trigger('change');

    // Handle admin configuration form submission
    $('#admin-form').on('submit', function(e) {
        e.preventDefault();
        var formData = $(this).serialize() + '&' + $('#db-form .img-setting').serialize();
        console.log('Admin form submitted.');
        $('#configure').hide();
        $('#pre_load').show();
        $('#alertfailed').hide();
        $('#admin-alertfailed').hide();

        $.ajax({
            url: 'install.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            timeout: 300000, // 5 minute timeout
            success: function(response) {
                console.log('install.php response:', response);
                $('#pre_load').hide();
                $('#logpanel').show();
                if (response.status === 'success') {
                    $('#log').html('<div class="alert alert-success">' + response.message + '</div>');
                } else {
                    $('#log').html('<div class="alert alert-danger">' + (response.message || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('install.php AJAX error:', status, error, xhr.responseText);
                $('#pre_load').hide();
                $('#configure').show();
                $('#admin-alertfailed').show();
                var msg = 'Installation failed: ';
                if (status === 'timeout') {
                    msg += 'Request timed out. The installation may still be running - check your error logs.';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg += xhr.responseJSON.message;
                } else if (xhr.responseText) {
                    msg += xhr.responseText.substring(0, 500);
                } else {
                    msg += status + ' - ' + error;
                }
                $('#admin-error-details').text(msg);
            }
        });
    });

    // Upgrade: sign in as an admin, then upgrade in place or reinstall
    function upgradeError(msg) {
        $('#upgrade-alert').html(msg || 'Something went wrong.').prop('hidden', false);
    }
    function showReinstall() {
        $('#upgrade-card').prop('hidden', true);
        $('#db-card').prop('hidden', false);
    }
    $('#upgrade-signin').on('submit', function(e) {
        e.preventDefault();
        $('#upgrade-alert').prop('hidden', true);
        $.ajax({ url: 'upgrade.php', method: 'POST', data: $(this).serialize() + '&action=signin', dataType: 'json' })
            .done(function(response) {
                if (response.status !== 'success') {
                    upgradeError(response.message);
                    return;
                }
                $('#upgrade-signin').prop('hidden', true);
                if ($('#upgrade-form').data('upgradable') === 1) {
                    $('#upgrade-form').prop('hidden', false);
                } else {
                    showReinstall();
                }
            })
            .fail(function(xhr) {
                upgradeError(xhr.responseJSON && xhr.responseJSON.message);
            });
    });
    $('#show-reinstall').on('click', function(e) {
        e.preventDefault();
        showReinstall();
    });
    $('#up_img').on('change', function() {
        $('.up-img-opt').prop('hidden', $(this).val() !== 'yes');
    }).trigger('change');
    $('#upgrade-form').on('submit', function(e) {
        e.preventDefault();
        $('#upgrade-alert').prop('hidden', true);
        $('#install').hide();
        $('#pre_load .card-title').text('Upgrading Paste. Please wait...');
        $('#pre_load').show();
        $.ajax({ url: 'upgrade.php', method: 'POST', data: $(this).serialize() + '&action=upgrade', dataType: 'json', timeout: 300000 })
            .done(function(response) {
                $('#pre_load').hide();
                if (response.status === 'success') {
                    $('#logpanel').show();
                    $('#log').html('<div class="alert alert-success">' + response.message + '</div>');
                } else {
                    $('#install').show();
                    upgradeError(response.message);
                }
            })
            .fail(function(xhr, status) {
                $('#pre_load').hide();
                $('#install').show();
                upgradeError(status === 'timeout'
                    ? 'The upgrade timed out. It may still be running; check the error log, or run php install/upgrade.php from a shell.'
                    : (xhr.responseJSON && xhr.responseJSON.message) || ('Upgrade failed: ' + status));
            });
    });

    // Warn about OAuth without HTTPS
    $('#enablegoog').on('change', function() {
        if ($(this).val() === 'yes') {
            if (window.location.protocol !== 'https:') {
                alert('Warning: Enabling OAuth without HTTPS is insecure and may cause issues with OAuth providers. Consider enabling SSL/TLS.');
            }
        }
    });
});
