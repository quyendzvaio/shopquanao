ALTER TABLE fashion_provider_product_mapping
    ADD COLUMN IF NOT EXISTS provider_identifiers JSON NULL AFTER provider_color_id;
