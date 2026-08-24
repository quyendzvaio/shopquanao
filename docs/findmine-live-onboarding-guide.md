# FindMine live onboarding guide

This repository is code-complete for the provider boundary, but a real recommendation requires a FindMine tenant and a catalog recognized by that tenant.

## ACTION REQUIRED NOW

This vendor step must be performed manually by the repository operator. Codex cannot generate or infer tenant-assigned values.

1. Send `docs/findmine-onboarding-request.md` through the official [FindMine contact page](https://www.findmine.com/contact) or to `contact@findmine.com`.
2. Wait for the assigned real `FINDMINE_APP_ID` and confirmation that Complete the Look v3 is enabled.
3. Obtain the tenant's complete required `product_*` identifier list and product/variant/color semantics.
4. Complete the catalog onboarding method specified by FindMine; do not substitute a locally invented feed or API.
5. Obtain one known-good shop product/variant/color ↔ FindMine identifier mapping from the onboarded catalog.
6. Put the received values into a local production-like environment without committing tenant values that repository policy treats as private.
7. Resume at Task 4: verify the state is `CONFIGURED_NOT_VERIFIED`, import the mapping with `--dry-run`, and run the live inspector.

```text
FINDMINE_EXTERNAL_ONBOARDING_STATUS=AWAITING_VENDOR
```

## What must be obtained

`FINDMINE_APP_ID` identifies the FindMine application/tenant used by the integration. It is assigned during FindMine onboarding; it is not a password and must not be generated locally. The MCP server sends it as the `application` selector to the v3 API.

Request onboarding through the official [FindMine contact page](https://www.findmine.com/contact) or `contact@findmine.com`. Ask FindMine to:

1. provision an application/tenant for shopquanao;
2. explain whether the catalog will be supplied by SFTP feed, JSON/CSV/XML feed, or a retailer API pull;
3. confirm the exact product and color identifier fields for this tenant;
4. provide one known-good product/color test mapping;
5. confirm the tenant's Complete-the-Look v3 response shape and any required fields.

The public FindMine materials describe both feed-based and retailer-API onboarding, so the tenant-specific answer must come from the account team. Do not infer it from the generic MCP schema.

## Configure the application

Copy `.env.example` to `.env`, then set the assigned application ID and enable the provider only after the catalog is provisioned:

```text
FINDMINE_ENABLED=true
FINDMINE_APP_ID=<assigned tenant application ID>
FINDMINE_PRODUCT_IDENTIFIER_KEY=product_id
FINDMINE_COLOR_IDENTIFIER_KEY=product_color_id
```

If onboarding uses additional or different identifier names, include the complete verified object in the mapping import's `provider_identifiers_json` field, for example `{"product_id":"P123","product_color_id":"BLACK","product_pattern":"stripe"}`. Every key is validated as `product_*`; the production request forwards the exact stored set. The two environment keys remain compatibility defaults for tenants with the common product/color pair.

## Establish the first mapping

Select an active shop product with a real variant and canonical color. Confirm it is present in the FindMine tenant catalog, obtain the exact provider product/color identity from FindMine, and import it with `scripts/import_fashion_provider_mappings.php --dry-run` first, then repeat without `--dry-run` after review. Read it back through `FashionProviderMappingRepository`; never type a guessed ID into a smoke test.

The current shop catalog has no footwear products and contains products with unknown or ambiguous colors. Do not fabricate footwear or uncertain color mappings.

## Live verification order

Run the connectivity smoke first, then the known-good Complete-the-Look smoke, then the end-to-end FindMine → normalized requirements → parallel shop search smoke. A missing application ID, missing catalog, or missing mapping is a hard blocker; the application must remain usable with FindMine disabled.
