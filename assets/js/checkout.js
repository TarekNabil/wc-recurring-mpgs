(function () {
    if (typeof window.Checkout === 'undefined' || typeof window.wcrmpgsCheckoutConfig === 'undefined') {
        return;
    }

    if (!window.wcrmpgsCheckoutConfig.sessionId) {
        return;
    }

    window.Checkout.configure({
        session: {
            id: window.wcrmpgsCheckoutConfig.sessionId,
        },
    });

    window.Checkout.showPaymentPage();
})();