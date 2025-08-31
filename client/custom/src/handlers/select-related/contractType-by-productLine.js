// Custom handler for specific fields
// Add ' "selectHandler": "custom:handlers/select-related/contractType-by-productLine" ' into 'clientDefs -> {EntityType} -> relationshipPanels -> {link}'

define('custom:handlers/select-related/contractType-by-productLine', ['handlers/select-related'], Dep => {

    return class extends Dep {
        /**
         * @param {module:model.Class} model
         * @return {Promise<module:handlers/select-related~filters>}
         */
        getFilters(model) {
            let advanced = {};
            let nameHash = {};

            let productLineId = null;
            let productLineName = null;

            if (model.get('productLineId')) {
                productLineId = model.get('productLineId');
                productLineName = model.get('productLineName');
            }

            if (productLineId) {
                nameHash[productLineId] = productLineName;

                advanced.productLine = {
                    //attribute: 'productLineId',           // Use attribute instead of field if you use 'equals'
                    field: 'productLine',
                    type: 'linkedWith',                     // Different types possible - e.g. 'equals'
                    value: [productLineId],
                    data: {
                        nameHash: nameHash,                 // Needed for type 'linkedWith'
                        type: 'is',
                        nameValue: productLineName
                    },
                };
            }

            return Promise.resolve({
                advanced: advanced,
            });
        }
    }
});