<style>
    .products-table-compact .fi-ta-text:not(.fi-inline),
    .products-table-compact .fi-ta-toggle,
    .products-table-compact .fi-ta-cell:has(.fi-ta-actions),
    .products-table-compact .fi-ta-selection-cell {
        padding-block: .5rem;
    }

    .products-table-compact .products-title-cell {
        width: 100%;
        min-width: 14rem;
        max-width: 0;
    }

    .products-table-compact .products-title-cell .fi-ta-text-item {
        display: block;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .products-table-compact .products-sku-cell .fi-ta-text-item {
        display: block;
        max-width: 9.5rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    @media (pointer: coarse) {
        .products-table-compact .fi-ta-row {
            height: 48px;
        }
    }
</style>
