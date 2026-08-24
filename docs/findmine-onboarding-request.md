# FindMine onboarding request

**Subject:** FindMine Complete the Look / MCP integration onboarding

Hello FindMine team,

We are integrating FindMine Complete the Look into an ecommerce shopping assistant for shopquanao.

We are using the official FindMine MCP repository:

https://github.com/findmine/findmine-mcp

Pinned commit:

`28a15b86ac0a7b212336748005393f88bcbfdad1`

Our architecture uses FindMine for styling compatibility and Complete-the-Look recommendations only. Our own catalog and Product Search remain the source of sellable shop SKUs.

Could you provide exactly the following tenant-onboarding information:

1. the assigned `FINDMINE_APP_ID` / application identifier for this tenant;
2. confirmation that Complete the Look v3 is enabled for the tenant;
3. the complete tenant-specific `product_*` identifier schema expected by Complete the Look;
4. the catalog onboarding/feed method and required catalog fields;
5. product, variant, and color identity semantics, including identifier ownership;
6. one known-good shop product/variant/color to provider identifier mapping;
7. the expected production region and language values, if tenant-specific.

Please also confirm whether the tenant requires `product_id`, `product_color_id`, or another configured identifier key, and how catalog updates and deletions are handled.

FindMine will supply styling recommendations. shopquanao Product Search will remain the source of actual visible and sellable shop SKUs. We are not requesting user profiles, chat transcripts, payment data, order data, or other unnecessary customer-data integration.

Thank you.
