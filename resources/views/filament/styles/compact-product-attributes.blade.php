<style>
    .product-attributes-compact > table {
        table-layout: fixed;
    }

    .product-attributes-compact > table > thead > tr > th:last-of-type {
        width: 2.75rem;
    }

    .product-attributes-compact .fi-fo-table-repeater-actions {
        justify-content: center;
        gap: 0;
        padding: .125rem .25rem;
    }

    .product-attributes-compact .fi-input,
    .product-attributes-compact .fi-select-input-btn {
        padding-block: .25rem;
        font-size: .8125rem;
        line-height: 1.25rem;
    }

    .product-attributes-compact .fi-select-input-btn,
    .product-attributes-compact .fi-select-input div[x-ref="select"] {
        min-height: 1.75rem;
    }

    .product-attributes-compact .fi-select-input-value-ctn,
    .product-attributes-compact .fi-select-input-value-label {
        min-width: 0;
    }

    .product-attributes-compact .fi-select-input-value-label {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    @container (width < 36rem) {
        .product-attributes-compact > table > tbody > tr {
            grid-template-columns: minmax(0, 1fr) 5rem 2.25rem;
            gap: .5rem;
            padding: .625rem;
        }

        .product-attributes-compact > table > tbody > tr > td {
            min-width: 0;
        }

        .product-attributes-compact > table > tbody > tr > td:nth-of-type(1) {
            grid-column: 1 / 3;
        }

        .product-attributes-compact > table > tbody > tr > td:nth-of-type(2) {
            grid-column: 1;
            grid-row: 2;
        }

        .product-attributes-compact > table > tbody > tr > td:nth-of-type(3) {
            grid-column: 2;
            grid-row: 2;
        }

        .product-attributes-compact > table > tbody > tr > td:last-of-type {
            grid-column: 3;
            grid-row: 1 / 3;
            align-self: center;
        }

        .product-attributes-compact .fi-fo-field {
            gap: .25rem;
        }

        .product-attributes-compact .fi-fo-field-label-content {
            font-size: .75rem;
        }

        .product-attributes-compact .fi-fo-table-repeater-actions {
            justify-content: center;
        }
    }

    @supports (-webkit-touch-callout: none) {
        .product-attributes-compact .fi-input,
        .product-attributes-compact .fi-select-input-btn {
            font-size: 1rem;
        }
    }
</style>
