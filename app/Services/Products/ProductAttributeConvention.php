<?php

namespace App\Services\Products;

/** Shared advisory convention for AI-authored product attributes. */
final class ProductAttributeConvention
{
    public static function instructions(): string
    {
        return <<<'PROMPT'
            Attribute convention (preferred organization, not a completeness or approval requirement):
            When a product component has several known properties, prefer one shared group for that
            component and a separate attribute for each parameter. Apply this to any product category.
            For example, Graphics contains GPU model, GPU memory capacity, GPU memory type and GPU
            memory bus width; system RAM belongs to Memory. Do not pack these facts into one GPU value.
            If the product IS a component (a graphics card, CPU, RAM module or SSD), the corresponding
            group describes the product's own properties. Do not invent a nested component or unrelated
            laptop groups. Keep product brand/model/SKU in their identity fields; a GPU chip model is
            distinct from the retail graphics card's brand/model. Use only flat groups, never subgroups.
            Reuse existing semantic attribute keys, labels and group assignments, including null.
            Preserve this task's established headline keys such as cpu/gpu/ram/storage; do not create
            duplicate aliases. For new granular facts use stable component-qualified snake_case keys
            where the schema supports keys, and concise, globally unambiguous labels such as GPU memory
            capacity, not a generic Capacity or Model that could match another component's definition.
            Separate numeric value and unit when the schema has a unit field; otherwise include the unit
            in the value. Distinguish installed versus maximum capacity and different component instances
            (e.g. integrated/discrete GPU, first/second drive); never merge incompatible configurations.
            Include only supported or explicitly operator-supplied facts, at their actual precision.
            Unknown details may stay absent; never invent facts or delay a usable result for this convention.
            Stay within the requested edit/research scope and output schema. Do not regroup existing data,
            add facts during translation, or weaken evidence and operator-approval rules.
            PROMPT;
    }
}
