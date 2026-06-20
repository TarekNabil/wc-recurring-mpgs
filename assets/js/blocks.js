( function () {
    if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp ) {
        return;
    }

    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = window.wc.wcSettings.getSetting;
    var createElement = window.wp.element.createElement;
    var decodeEntities = window.wp.htmlEntities.decodeEntities;

    var settings = getSetting( 'merchant_payments_data', {} );
    var title = decodeEntities( settings.title || 'WC Recurring MPGS Card' );
    var description = decodeEntities( settings.description || '' );

    var Content = function () {
        return createElement( 'div', {}, description );
    };

    registerPaymentMethod( {
        name: 'merchant_payments',
        label: title,
        ariaLabel: title,
        content: createElement( Content, {} ),
        edit: createElement( Content, {} ),
        canMakePayment: function () {
            return true;
        },
        supports: {
            features: settings.supports || []
        }
    } );
} )();
