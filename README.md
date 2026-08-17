# prestashop-acp

A PSP-agnostic [Agentic Commerce Protocol](https://github.com/agentic-commerce-protocol/agentic-commerce-protocol) merchant module for PrestaShop 9, letting AI agents complete a purchase on behalf of a buyer.

> **Work in progress. Not production ready.**

## Spec version

Pinned to **`2026-04-17`**. `main` and `spec/unreleased/` are not tracked. Version bumps are deliberate and come with a changelog diff.

Requests must carry `API-Version: 2026-04-17`. A missing or unsupported value is rejected with an `Error` object carrying `supported_versions`.

## Requirements

| | |
|---|---|
| PrestaShop | 9.0.0+ |
| PHP | 8.3+ |
| Module technical name | `acpcheckout` |
| Route prefix | `/acp/v1` |

## Scope

**In v1**

- The five agentic checkout endpoints (create, get, update, complete, cancel)
- Order event webhooks, HMAC-signed
- Product feed push
- Delegated payment handler interface, with a Stripe Shared Payment Token adapter
- A HiPay adapter stub

**Not in v1**

No admin UI — the module exposes HTTP endpoints only. Also excluded: multistore, multi-currency, subscriptions, digital goods, discounts and coupons, the cart API, returns and post-purchase, and the delegated authentication endpoints (documented, not built).

See `SPEC-NOTES.md` §1 and §10 for the file-level breakdown.

## Documentation

- `SPEC-NOTES.md` — field-level build and test checklist against the pinned spec
- `DECISIONS.md` — architectural decisions and their rationale

## Licence

MIT
