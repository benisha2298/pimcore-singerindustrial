pimcore.registerNS("pimcore.plugin.ExportTemplate");

pimcore.plugin.ExportTemplate = Class.create({
    initialize: function () {
        pimcore.event.on("postOpenObject", this.handleObjectLoad, this);
    },

    handleObjectLoad: function (object) {
        if (object.data.general.o_className === "ExportTemplate") {
            const inputFileField = Ext.getCmp("pimcore_object_relation_InputFile"); // Replace with the correct field ID
            const dropdownField = Ext.getCmp("pimcore_object_select_ExternalChannelFieldName"); // Replace with the correct field ID

            if (inputFileField && dropdownField) {
                inputFileField.on("add", function () {
                    dropdownField.getStore().reload(); // Reload options dynamically
                });
            }
        }
    }
});

new pimcore.plugin.ExportTemplate();
