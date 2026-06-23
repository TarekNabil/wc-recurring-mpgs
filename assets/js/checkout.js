(function () {
    var sendClientLog = function (config, level, message, context) {
        if (!config || !config.logEndpoint || !config.logNonce) {
            return;
        }

        var payload = new FormData();
        payload.append('action', 'wcrmpgs_client_log');
        payload.append('nonce', config.logNonce);
        payload.append('level', level || 'error');
        payload.append('message', message || 'unknown');
        payload.append('context', JSON.stringify(context || {}));
        payload.append('url', window.location.href);
        payload.append('userAgent', window.navigator.userAgent || '');

        if (typeof navigator.sendBeacon === 'function') {
            navigator.sendBeacon(config.logEndpoint, payload);
            return;
        }

        if (typeof window.fetch === 'function') {
            window.fetch(config.logEndpoint, {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                keepalive: true,
            }).catch(function () {
                return null;
            });
        }
    };

    if (typeof window.wcrmpgsCheckoutConfig === 'undefined') {
        if (window.console && typeof window.console.error === 'function') {
            window.console.error('WCRMPGS checkout config is missing on pay page');
        }
        return;
    }

    var config = window.wcrmpgsCheckoutConfig;

    sendClientLog(config, 'info', 'checkout_js_bootstrap', {
        hasSessionId: !!config.sessionId,
    });

    if (!config.sessionId) {
        if (window.console && typeof window.console.error === 'function') {
            window.console.error('WCRMPGS session id is missing on pay page');
        }
        sendClientLog(config, 'error', 'session_id_missing_on_pay_page');
        return;
    }

    var sessionId = config.sessionId;
    var retryUrl = config.retryUrl || '';
    var currentUrl = window.location.href;

    var addFlagToUrl = function (url, key, value) {
        try {
            var parsed = new URL(url, window.location.origin);
            parsed.searchParams.set(key, value);
            return parsed.toString();
        } catch (e) {
            return url;
        }
    };

    var isRetryPage = function () {
        try {
            var parsed = new URL(currentUrl, window.location.origin);
            return parsed.searchParams.get('wcrmpgs_retry') === '1';
        } catch (e) {
            return false;
        }
    };

    if (typeof window.Checkout === 'undefined') {
        if (window.console && typeof window.console.error === 'function') {
            window.console.error('WCRMPGS Checkout SDK is not available. Verify service_host and checkout SDK URL loading.');
        }

        sendClientLog(config, 'error', 'checkout_sdk_missing', {
            retryUrl: retryUrl,
        });

        if (retryUrl && !isRetryPage()) {
            window.location.replace(addFlagToUrl(retryUrl, 'wcrmpgs_sdk_error', '1'));
        }

        return;
    }

    window.wcrmpgsCancelCallback = function () {
        sendClientLog(config, 'warning', 'checkout_cancel_callback');
        if (retryUrl) {
            window.location.replace(addFlagToUrl(retryUrl, 'wcrmpgs_cancelled', '1'));
        }
    };

    window.wcrmpgsErrorCallback = function (error) {
        if (window.console && typeof window.console.error === 'function') {
            window.console.error('WCRMPGS hosted checkout error callback', error || null);
        }

        sendClientLog(config, 'error', 'checkout_error_callback', {
            error: error || null,
            retryUrl: retryUrl,
        });

        // Avoid redirect loops when the provider SDK keeps failing on the retry page.
        if (retryUrl && !isRetryPage()) {
            window.location.replace(addFlagToUrl(retryUrl, 'wcrmpgs_sdk_error', '1'));
        }
    };

    window.Checkout.configure({
        session: {
            id: sessionId,
        },
    });

    sendClientLog(config, 'info', 'checkout_sdk_configured', {
        sessionId: sessionId,
    });

    window.Checkout.showPaymentPage();
    sendClientLog(config, 'info', 'checkout_show_payment_page_called', {
        sessionId: sessionId,
    });
})();