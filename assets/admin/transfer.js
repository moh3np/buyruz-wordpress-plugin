// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
jQuery(document).ready(function($) {
    function loadOutbound() {
        $('#brz-transfer-status').text('در حال بروزرسانی...');
        $.post(brzTransferData.ajax_url, {
            action: 'brz_transfer_outbound_queue',
            nonce: brzTransferData.nonce
        }, function(res) {
            $('#brz-transfer-status').text('');
            if (res.success && res.data) {
                let html = '';
                if(res.data.length === 0) {
                    html = '<p>موردی در صندوق خروجی وجود ندارد.</p>';
                } else {
                    res.data.forEach(function(pkg) {
                        html += `<div class="brz-pkg-item" style="border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; display: flex; justify-content: space-between;">
                            <div>📦 ${pkg.operationType} (${pkg.packageId})</div>
                            <button class="button brz-copy-pkg" data-id="${pkg.packageId}">کپی کد</button>
                        </div>`;
                    });
                }
                $('#brz-outbound-queue').html(html);
            }
        });
    }

    $('#brz-refresh-transfer').on('click', loadOutbound);

    $('#brz-process-inbound').on('click', function() {
        const code = $('#brz-inbound-data').val().trim();
        if (!code) return;

        $('#brz-inbound-result').html('در حال پردازش <span class="spinner is-active" style="float:none;"></span>');
        
        $.post(brzTransferData.ajax_url, {
            action: 'brz_transfer_inbound',
            nonce: brzTransferData.nonce,
            code: code
        }, function(res) {
            if(res.success || res.packageId) {
                $('#brz-inbound-result').html('✅ بسته پردازش شد.');
                $('#brz-inbound-data').val('');
                loadOutbound(); // Reload outbound to show generated responses
            } else {
                $('#brz-inbound-result').html('❌ خطا: ' + (res.data || res.message || 'خطای نامشخص'));
            }
        });
    });

    $(document).on('click', '.brz-copy-pkg', function() {
        const btn = $(this);
        const id = btn.data('id');
        btn.text('در حال دریافت...');
        
        $.post(brzTransferData.ajax_url, {
            action: 'brz_transfer_get_code',
            nonce: brzTransferData.nonce,
            id: id
        }, function(res) {
            if(res.success && res.data) {
                navigator.clipboard.writeText(res.data).then(function() {
                    btn.text('کپی شد!');
                    // Mark as delivered automatically
                    $.post(brzTransferData.ajax_url, {
                        action: 'brz_transfer_mark_delivered',
                        nonce: brzTransferData.nonce,
                        id: id
                    }, function() {
                        setTimeout(loadOutbound, 1000);
                    });
                });
            } else {
                btn.text('خطا در دریافت');
            }
        });
    });

    $('.brz-generate-pkg').on('click', function() {
        const type = $(this).data('type');
        $(this).attr('disabled', true);
        $.post(brzTransferData.ajax_url, {
            action: 'brz_transfer_generate',
            nonce: brzTransferData.nonce,
            type: type
        }, function() {
            $('.brz-generate-pkg').removeAttr('disabled');
            loadOutbound();
        });
    });

    // Auto refresh every 30s
    setInterval(loadOutbound, 30000);
    loadOutbound();
});
