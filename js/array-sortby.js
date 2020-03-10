// Array sorting functions
!function() {
    function _dynamicSort(property) {
        let sortOrder = 1;
        if (property[0] === "-") {
            sortOrder = -1;
            property = property.substr(1);
        }
        return function (a,b) {
            let result;
            if (isNaN(a[property]) || isNaN(b[property])) {
                result = (a[property] < b[property]) ? -1 : (a[property] > b[property]) ? 1 : 0;
            } else {
                result = a[property] - b[property];
            }
            return result * sortOrder;
        };
    }
    function _dynamicSortMultiple() {
        /*
         * save the arguments object as it will be overwritten
         * note that arguments object is an array-like object
         * consisting of the names of the properties to sort by
         */
        var props = arguments;
        return function (obj1, obj2) {
            let i = 0, result = 0, numberOfProperties = props.length;
            /* try getting a different result from 0 (equal)
             * as long as we have extra properties to compare
             */
            while(result === 0 && i < numberOfProperties) {
                result = _dynamicSort(props[i])(obj1, obj2);
                i++;
            }
            return result;
        };
    }
    Object.defineProperty(Array.prototype, "sortBy", {
        enumerable: false,
        writable: true,
        value: function() {
            return this.sort(_dynamicSortMultiple.apply(null, arguments));
        }
    });
    Object.defineProperty(Array.prototype, "shuffle", {
        enumerable: false,
        writable: true,
        value: function() {
            let i = this.length, j, temp;
            if ( i == 0 ) return this;
            while ( --i ) {
                j = Math.floor( Math.random() * ( i + 1 ) );
                temp = this[i];
                this[i] = this[j];
                this[j] = temp;
            }
            return this;
        }
    });
}();