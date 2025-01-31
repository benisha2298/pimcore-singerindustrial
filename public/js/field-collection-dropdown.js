pimcore.registerNS("pimcore.plugin.dynamicDropdownUpdater");

pimcore.plugin.dynamicDropdownUpdater = Class.create({
    initialize: function () {
        pimcore.event.on("postOpenObject", this.attachListeners, this);
    },

    attachListeners: function (object) {
        if (!object.data.general || object.data.general.o_className !== "ExportTemplate") {
            return;
        }

        const fieldCollections = object.fieldCollection.data; // Adjust if FieldCollections are structured differently

        fieldCollections.forEach(function (fieldCollection) {
            fieldCollection.items.each(function (item) {
                const inputFileField = item.getComponent("InputFile"); // Field key
                const dropdownField = item.getComponent("ExternalChannelFieldName"); // Dropdown key

                if (inputFileField && dropdownField) {
                    inputFileField.on("change", function (field, value) {
                        if (value) {
                            // Fetch headers from backend
                            Ext.Ajax.request({
                                url: "/admin/get-xlsx-headers",
                                method: "GET",
                                params: { assetId: value },
                                success: function (response) {
                                    const headers = Ext.decode(response.responseText);
                                    dropdownField.store.loadData(headers.map(header => [header, header]));
                                    dropdownField.setValue(null); // Clear current selection
                                },
                                failure: function () {
                                    console.error("Failed to fetch headers from the uploaded file.");
                                }
                            });
                        }
                    });
                }
            });
        });
    }
});

new pimcore.plugin.dynamicDropdownUpdater();
