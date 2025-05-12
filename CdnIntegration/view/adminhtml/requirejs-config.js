var config = {
    map: {
        '*': {
            chartjs: 'MagoArab_CdnIntegration/js/chart.min'
        }
    },
    paths: {
        chartjs: 'MagoArab_CdnIntegration/js/chart.min'
    },
    shim: {
        'MagoArab_CdnIntegration/js/chart.min': {
            deps: ['jquery']
        }
    }
};