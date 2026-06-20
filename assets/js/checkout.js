(function () {
    if (typeof window.Checkout === 'undefined' || typeof window.wcrmpgsCheckoutConfig === 'undefined') {
        return;
    }

    if (!window.wcrmpgsCheckoutConfig.sessionId) {
        return;
    }

    var sessionId = window.wcrmpgsCheckoutConfig.sessionId;
    var retryUrl = window.wcrmpgsCheckoutConfig.retryUrl || '';

    window.wcrmpgsCancelCallback = function () {
        if (retryUrl) {
            window.location.replace(retryUrl);
        }
    };

    window.wcrmpgsErrorCallback = function () {
        if (retryUrl) {
            window.location.replace(retryUrl);
        }
    };

    window.Checkout.configure({
        session: {
            id: sessionId,
        },
    });

    window.Checkout.showPaymentPage();
})();