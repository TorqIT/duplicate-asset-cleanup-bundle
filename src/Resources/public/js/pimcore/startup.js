pimcore.registerNS("pimcore.plugin.TorqITDuplicateAssetCleanupBundle");

pimcore.plugin.TorqITDuplicateAssetCleanupBundle = Class.create(pimcore.plugin.admin, {
    getClassName: function () {
        return "pimcore.plugin.TorqITDuplicateAssetCleanupBundle";
    },

    initialize: function () {
        pimcore.plugin.broker.registerPlugin(this);
    },

    pimcoreReady: function (params, broker) {
        // alert("TorqITDuplicateAssetCleanupBundle ready!");
    }
});

var TorqITDuplicateAssetCleanupBundlePlugin = new pimcore.plugin.TorqITDuplicateAssetCleanupBundle();
