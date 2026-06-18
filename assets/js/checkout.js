(function () {
    if (typeof window.Checkout === 'undefined' || typeof window.mpfwCheckoutConfig === 'undefined') {
        return;
    }

    if (!window.mpfwCheckoutConfig.sessionId) {
        return;
    }

    window.Checkout.configure({
        session: {
            id: window.mpfwCheckoutConfig.sessionId,
        },
    });

    window.Checkout.showPaymentPage();
})();