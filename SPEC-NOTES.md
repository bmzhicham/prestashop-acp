
/
PSP-agnostic ACP (Agentic Commerce Protocol) merchant module for PrestaShop.
PSP-agnostic ACP (Agentic Commerce Protocol) merchant module for PrestaShop.
Project: PSP-agnostic ACP (Agentic Commerce Protocol) merchant module for PrestaShop.
Stack: PrestaShop 9, PHP 8.3, Symfony, Composer. First payment adapter is Stripe
Shared Payment Token; a HiPa adapter is stubbed for later.

About me: ~10 years in e-commerce and payments, currently at a PSP building CMS
payment modules. Deep on PrestaShop internals, payment flows, 3DS/SCA. Assume that
knowledge — don't explain what a cart rule or a soft decline is.

How to work with me:
- Always tell me which roadmap phase a suggestion belongs to. If I'm asking about
something from a later phase, say so before answering.
- Push back on scope creep. If I drift toward UCP, AP2, subscriptions, multishop,
or multi-currency, tell me it's out of v1 scope and why.
- Push back if I'm studying instead of shipping. Reading is capped at Phase 1.
- Conform to the pinned ACP spec version in project knowledge. If my proposed
response shape doesn't match the OpenAPI file, say so and quote the field.
- Any pricing or totals discussion must account for the delegated token's max
amount and expiry. Flag it if I forget.
- Code in PHP unless I ask otherwise. Follow PSR-12. Type hints everywhere.

Do NOT:
- Suggest building a UI. There is no UI.
- Suggest switching frameworks, CMSs, or languages.
- Give me generic PrestaShop tutorial content.
- Pad answers with caveats about consulting a lawyer on PSD2 — flag the regulatory
question and move on.








Récents
PrestaShop 9 for ACP integration learning
maintenant
Spécifications techniques pour module de paiement CMS
il y a 4 heures
Stratégie de carrière en AI engineering appliqué
il y a 16 heures
The three ACP specs
il y a 18 heures
Instructions
Projet. Module marchand ACP (Agentic Commerce Protocol) pour PrestaShop, agnostique du PSP. PrestaShop 9, PHP 8.3, Symfony, Composer. Premier adaptateur : Stripe Shared Payment Token. Version de spec figée : 2026-04-17. Elle ne bouge pas sans décision explicite de ma part. Ignore main et spec/unreleased/. Moi. ~10 ans en e-commerce et paiement, actuellement chez un PSP à construire des modules de paiement CMS. Solide sur les internals PrestaShop, les flux de paiement, 3DS/SCA. Pars du principe que je connais tout ça — ne m'explique pas ce qu'est une règle panier ou un soft decline. Où j'en suis : Phase 2, Spike A (panier headless). ← ligne à tenir à jour Méthode de travail - Dis-moi toujours à quelle phase de la roadmap appartient une suggestion. Si ma question relève d'une phase ultérieure, signale-le avant de répondre. - SPEC-NOTES.md est la référence. N'en recopie pas le contenu : cite le nom de champ exact et renvoie à la section. Si ce que je propose ne correspond pas au fichier OpenAPI, dis-le et cite le champ. - Toute discussion de prix ou de totaux doit tenir compte de Allowance.max_amount et Allowance.expires_at. Signale-le si je l'oublie. - Repousse le scope creep. Si je dérive vers UCP, AP2, x402, les abonnements, le multiboutique, le multidevise, l'API cart, les remises ou les extensions, dis-moi que c'est hors v1 et pourquoi. - Repousse aussi si j'étudie au lieu de livrer. La lecture est plafonnée à la Phase 1. - Si tu n'es pas sûr d'un champ de la spec, va le vérifier dans le dépôt plutôt que de le reconstituer de mémoire. - Code en PHP sauf demande contraire. PSR-12. Type hints partout. À ne pas faire - Proposer une UI. Il n'y a pas d'UI. - Proposer de changer de framework, de CMS ou de langage. - Du contenu tutoriel PrestaShop générique. - Ajouter des réserves du type « consulte un juriste sur la DSP2 ». Signale la question réglementaire et passe à la suite. Réponds-moi toujours en français et en français simple comme ami

Mémoire
Vous uniquement
Purpose & context BMZ is an experienced PrestaShop developer (~10 years in e-commerce and payments, currently working at a PSP building CMS payment modules) building a PrestaShop 9 merchant module implementing the Agentic Commerce Protocol (ACP), spec version 2026-04-17. The module enables AI agents to purchase on behalf of buyers. BMZ is solid on PrestaShop internals, payment flows, and 3DS/SCA. Tech stack: PrestaShop 9, PHP 8.3, Symfony, Composer. Two PSP adapters: Stripe Shared Payment Token (v1, functional). Module technical name: acpcheckout (avoiding the ps prefix reserved for official PS modules). Route prefix: /acp/v1. Repo: prestashop-acp (public GitHub, MIT license). Key spec facts (2026-04-17): Ships six OpenAPI files, seven JSON Schemas, one OpenRPC file. Five checkout endpoints; shipping and payment are parameters of updateCheckoutSession, not separate endpoints; cancelCheckoutSession must not be omitted. PaymentData uses handlerid and instrument (not flat token/provider). capabilities negotiation is required, not optional. Totals in minor units integer format. Feed is a push model (merchant POSTs to agent endpoints), not pull/cron. The Allowance object has six required fields. Two error shapes: Error vs MessageError. Scope: BMZ is the sole developer. v1 scope excludes pickup point support (FulfillmentOptionPickup) due to no headless API for carrier module widgets and a US-centric spec gap in pickuptype values (no French relay point equivalent). --- Current state BMZ is in Phase 2, Spike A (T1): building a headless PrestaShop Cart and Context without a browser session or cookie. The success criterion is that the computed total matches the front-office total for the same cart — not merely that the script runs. A CLI spike script (spike-a-headless-cart.php) has been produced. AcpSession replaces the cookie by storing shop ID, language, currency, customer ID, and cart ID; checkoutsessionid is the agent's only session handle between calls. Phase 0 is complete: GitHub repo created, DECISIONS.md written with six entries (PS version bounds, spec version, module name, license, route prefix, v1 scope), SPEC-NOTES.md integrated with exact field names from the real spec, feed correctly characterized as push model, README.md created. --- On the horizon Complete Spike A (headless Context bootstrap: mounting shop/language/currency/customer manually — identified as the genuinely risky work) Before Phase 3: finalize AcpSession schema, particularly implications of the timeout/orphan debit problem (case E9) Document known spec gaps in README: attemptacknowledged 3DS issue, US-centric pickuptype gap for French relay points --- Key learnings & principles Several common external resources about ACP contain inaccuracies (outdated "three specs" framing, wrong endpoint structure, feed mischaracterized as cron). Claude should push back on external recommendations against the pinned spec. The "three specs" framing from the September 2025 ACP launch is outdated; the 2026-04-17 spec is the single authoritative version. Legacy front controller URL patterns (/module/modulename/controller) contradict the PS 9 architectural rationale; modern routing should be used. optionid in fulfillment is a free string, so PrestaShop's native getDeliveryOptionList() key format (e.g., "2,") can be used directly. --- Approach & patterns BMZ communicates in short, informal French with spelling approximations; prefers direct, concise responses without restating the question. Preference for terse, directive instructions — minimal explanation, maximum actionability. Claude should anchor every explanation to the current phase and task, actively redirect away from future phases, and flag architectural decisions that must be locked before the next phase. Claude should cite SPEC-NOTES.md by field name rather than reproduce its content. Claude should flag scope creep, push back on studying instead of shipping, and provide technical pushback when proposals don't match the spec. Phase labels should appear on every suggestion. --- Tools & resources ACP spec version 2026-04-17 (six OpenAPI files, seven JSON Schemas, one OpenRPC file) — pinned authoritative reference SPEC-NOTES.md in repo — field-level reference document DECISIONS.md — records architectural decisions with six entries spike-a-headless-cart.php — CLI spike script for headless cart work Stripe Shared Payment Token API; API (stubbed) GitHub (prestashop-acp, public, MIT)

Dernière mise à jour il y a 2 heures

Contexte
1 % de la capacité du projet utilisée

SPEC-NOTES.md
358 lignes

md



acp-prestashop-roadmap.md
219 lignes

md


Programmé
Configurez des tâches récurrentes pour ce projet.

SPEC-NOTES.md


# SPEC-NOTES.md
 
Working notes for the PrestaShop ACP module. Not a summary of the spec — a
checklist to build and test against.
 
**Pinned version: `2026-04-17`.** Do not track `main`. Do not read
`spec/unreleased/`. Bump deliberately, with a changelog diff, never by accident.
 
Source: `github.com/agentic-commerce-protocol/agentic-commerce-protocol`,
Apache 2.0. Previous version `2026-01-30` is deprecated.
 
---
 
## 1. What is actually in the pinned version
 
`spec/2026-04-17/` ships six OpenAPI files, seven JSON Schemas, and one OpenRPC
file. The "three specs" framing from the September 2025 launch is dead.
 
| File | Build it? | Phase |
|---|---|---|
| `openapi.agentic_checkout.yaml` | **Yes — the core** | 4 |
| `openapi.delegate_payment.yaml` | **Yes — consume only** | 3 |
| `openapi.agentic_checkout_webhook.yaml` | **Yes** | 5 |
| `openapi.feed.yaml` | Yes, last | 5 |
| `openapi.delegate_authentication.yaml` | Read it. Do not build it in v1. | 6 (README) |
| `openapi.cart.yaml` | **No. Out of scope.** | — |
| `openrpc.agentic_checkout.json` | Test harness only | 7 |
 
`openrpc.agentic_checkout.json` is the MCP binding. It is how the fake agent in
Phase 7 talks to the module. It is not a second implementation.
 
---
 
## 2. Required HTTP headers
 
From `components.parameters` in `openapi.agentic_checkout.yaml`.
 
| Header | Required | Note |
|---|---|---|
| `Authorization` | **yes** | agent auth |
| `Content-Type` | **yes** | |
| `Idempotency-Key` | **yes** | on every call, not just mutating ones |
| `API-Version` | **yes** | `2026-04-17` |
| `Accept-Language` | no | maps to PS language + locale |
| `User-Agent` | no | log it — mandate evidence |
| `Request-Id` | no | log it — mandate evidence |
| `Signature` | no | |
| `Timestamp` | no | pair with `Signature` for replay protection |
 
**Version mismatch handling.** If `API-Version` is missing or unsupported,
reject and SHOULD return a `supported_versions` array on the `Error` object.
Well-known codes: `unsupported_api_version`, `missing_api_version`.
 
```json
{
  "type": "invalid_request",
  "code": "unsupported_api_version",
  "message": "...",
  "supported_versions": ["2026-04-17"]
}
```
 
---
 
## 3. The five endpoints — Phase 4 checklist
 
| # | Method + path | operationId | Done | Tested |
|---|---|---|---|---|
| 1 | `POST /checkout_sessions` | `createCheckoutSession` | [ ] | [ ] |
| 2 | `GET /checkout_sessions/{checkout_session_id}` | `getCheckoutSession` | [ ] | [ ] |
| 3 | `POST /checkout_sessions/{checkout_session_id}` | `updateCheckoutSession` | [ ] | [ ] |
| 4 | `POST /checkout_sessions/{checkout_session_id}/complete` | `completeCheckoutSession` | [ ] | [ ] |
| 5 | `POST /checkout_sessions/{checkout_session_id}/cancel` | `cancelCheckoutSession` | [ ] | [ ] |
 
**Update is a `POST`, not a `PATCH`.** Easy to get wrong when scaffolding
Symfony routes.
 
Webhook (Phase 5): `POST /agentic_checkout/webhooks/order_events`,
operationId `postOrderEvents`. You call this on the agent. The agent does not
call you.
 
---
 
## 4. `CheckoutSession` — required fields
 
`CheckoutSessionBase.required`:
 
```
id, status, currency, line_items, totals,
fulfillment_options, messages, links, capabilities
```
 
`capabilities` is required. Capability negotiation is not optional in this
version — that resolves the open question from the earlier read.
 
Notable optional fields worth deciding on early: `expires_at`, `quote_id`,
`quote_expires_at`, `continue_url`, `authentication_metadata`, `order`,
`fulfillment_groups`, `presentment_currency`, `exchange_rate`.
 
`quote_expires_at` + the token's `expires_at` are two different clocks. Decide
which one wins and write it down. (See §7.)
 
### Status enum — 11 values, not 4
 
```
incomplete
not_ready_for_payment
requires_escalation
authentication_required
ready_for_payment
pending_approval
complete_in_progress
completed
canceled
in_progress
expired
```
 
Map each one to a PrestaShop order state or explicitly to "no PS order exists
yet". `complete_in_progress` and `expired` are the ones that will bite:
`complete_in_progress` needs a lock so a retried complete does not double-charge.
 
**TODO:** state transition table. Which transitions are legal. Fill in from the
RFC, not from guessing.
 
---
 
## 5. Totals
 
`Total.required`: `type`, `display_text`, `amount`.
 
`Total.type` enum:
 
```
items_base_amount, items_discount, subtotal, discount,
fulfillment, tax, fee, gift_wrap, tip, store_credit,
total, amount_refunded
```
 
`amount` is an **integer in minor units**. So is `Allowance.max_amount`. There
are no floats anywhere in the pricing path. PrestaShop hands you floats.
Convert once, at the boundary, in one place, and unit-test the rounding.
 
v1 emits: `items_base_amount`, `subtotal`, `fulfillment`, `tax`, `total`.
Ignore `gift_wrap`, `tip`, `store_credit`, `fee`.
 
`TaxBreakdownItem` exists. French multi-rate carts (20% goods + reduced-rate
line) should populate it rather than collapsing to a single tax total.
 
---
 
## 6. Errors — two different shapes
 
**Do not confuse these.** This is the most common implementation mistake.
 
### `Error` — HTTP-level, 4xx/5xx body
 
Required: `type`, `code`, `message`. Optional: `param`, `supported_versions`.
 
`type` enum — only three values:
 
```
invalid_request | processing_error | service_unavailable
```
 
### `MessageError` — inside a `200` response, in `messages[]`
 
The session is still valid. Something is wrong with it. Required: `type`,
`code`, `content_type`, `content`.
 
`code` enum:
 
```
missing, invalid, out_of_stock, payment_declined, requires_sign_in,
requires_3ds, low_stock, quantity_exceeded, coupon_invalid,
coupon_expired, minimum_not_met, maximum_exceeded, region_restricted,
age_verification_required, approval_required, unsupported, not_found,
conflict, rate_limited, expired, intervention_required
```
 
`severity`: `info | low | medium | high | critical`
`resolution`: `recoverable | requires_buyer_input | requires_buyer_review`
`content_type`: `plain | markdown`
 
**Rule of thumb:** malformed request → HTTP `Error`. Valid request, unhappy
cart → `200` + `MessageError` in `messages[]`.
 
An over-cap total is **not** an HTTP error. It is a `200` with a
`MessageError`. Getting this wrong makes the agent retry instead of re-quoting.
 
---
 
## 7. Delegated payment — the token ceiling
 
`schema.delegate_payment.json`. Endpoint `POST /agentic_commerce/delegate_payment`,
operationId `delegatePayment`. **The agent calls the PSP. You never call this.**
You receive the resulting token.
 
### `Allowance` — all six fields required
 
```
reason              "one_time"          // only permitted value
max_amount          integer             // MINOR UNITS
currency            string              // ^[a-z]{3}$ lowercase ISO-4217
checkout_session_id string
merchant_id         string
expires_at          string              // ISO 8601
```
 
### Hard rules for the complete endpoint
 
1. Re-price the cart server-side. Never trust the agent's total.
2. `recalculated_total <= allowance.max_amount`. Integer compare, minor units.
3. `now < allowance.expires_at`. Check before calling the PSP, not after.
4. `allowance.currency` must equal the session currency, lowercase.
5. `allowance.checkout_session_id` must equal this session's `id`.
6. `allowance.merchant_id` must equal the configured merchant id.
Fail any of 2–6 → decline cleanly with a `MessageError`. Never attempt the
charge and let it fail at the PSP.
 
**The gap that will bite:** recalculated French VAT plus the selected carrier
cost can exceed what the agent quoted. That is a ceiling breach, not a bug.
Decline and re-quote.
 
`risk_signals` no longer has `minItems: 1`. An empty array is valid. Do not
reject on it.
 
### Interface impact — Phase 3
 
`PaymentData` in the checkout spec has **no flat `token` / `provider` field**.
It has:
 
```
handler_id            string
instrument            object
billing_address       Address
purchase_order_number string   // B2B, ignore
payment_terms         enum     // B2B, ignore
due_date              string   // B2B, ignore
approval_required     boolean  // B2B, ignore
```
 
So the handler interface must be:
 
```php
public function resolveToken(
    string $handlerId,
    array $instrument,
    Money $amount
): TokenContext;
```
 
`handler_id` is the adapter selector. That is how Stripe and HiPa get chosen
at runtime instead of by config. Better than the original design.
 
**TODO:** confirm where `handler_id` values are registered and whether they are
free-form or from a registry. Check `rfc.payment_handlers.md`.
 
---
 
## 8. 3DS / SCA — read, do not build (Phase 6)
 
`openapi.delegate_authentication.yaml`, three endpoints:
 
```
POST /delegate_authentication                                createAuthenticationSession
POST /delegate_authentication/{authentication_session_id}/authenticate   authenticateSession
GET  /delegate_authentication/{authentication_session_id}    getAuthenticationSession
```
 
`AuthenticationMetadata` required: `acquirer_details`, `directory_server`
(`visa | mastercard | american_express`). Plus `flow_preference`.
 
`AuthenticationResult.outcome`:
 
```
authenticated, attempt_acknowledged, denied, rejected, abandoned,
canceled, informational, not_supported, internal_error, processing_error
```
 
Tied into checkout via session status `authentication_required` and
`MessageError` code `requires_3ds`.
 
**This is the README section.** The spec now has an SCA answer. The open
question is no longer "is there one" but "does it satisfy PSD2 RTS". Things to
work out and write up:
 
- [ ] `attempt_acknowledged` — does that carry liability shift in the EU, or is
      it a US-shaped assumption baked into the spec?
- [ ] Challenge flow with no browser. Where does the buyer actually authenticate?
- [ ] Soft decline → re-auth loop. Does the allowance survive it, or do you need
      a fresh token?
- [ ] `expires_at` vs. a challenge that takes 90 seconds. Does the ceiling
      outlive the challenge?
- [ ] SCA exemptions (TRA, low value). Who claims them — merchant or PSP?
- [ ] Mandate evidence. What are you logging that would survive a dispute?
Not building the auth endpoints in v1. Documenting them.
 
---
 
## 9. Feed — push, not pull (Phase 5)
 
The feed is agent-hosted. Merchants push to it.
 
```
POST  /feeds                    create feed metadata
GET   /feeds/{id}               retrieve metadata
GET   /feeds/{id}/products      current agent-hosted product set
PATCH /feeds/{id}/products      partial upsert by Product.id
```
 
File ingestion: `metadata.json` (`FeedMetadata`) + `products.jsonl` (one
`Product` per line). **File ingestion is a full replacement** of the product
set. Partial updates only via `PATCH`.
 
So: full replace on a nightly cron, `PATCH` on `actionProductSave` /
`actionUpdateQuantity`. Promotions are deferred in the spec — do not build them.
 
---
 
## 10. Out of scope for v1
 
Present in the schemas. Will be tempting. Ignore.
 
`openapi.cart.yaml` · discounts, coupons, `AppliedDiscount`, `RejectedDiscount`
· `ExtensionDeclaration` · `SplitPayment` · `GiftWrap` · `LoyaltyInfo` ·
`MarketplaceSellerDetails` · `TaxExemption` · B2B payment terms ·
`AffiliateAttribution` · `presentment_currency` / `exchange_rate` (multi-currency)
· `MarketingConsent` · seller-backed payment handler · the auth endpoints (§8)
 
Also out: UCP, AP2, x402, subscriptions, digital goods, returns.
 
---
 
## 11. Open questions
 
- [ ] Legal state transition table for the 11 statuses
- [ ] Where PS cart rules land: `items_discount` or `discount`?
- [ ] Specific prices and customer groups with no logged-in customer — which
      group does an agent cart get?
- [ ] `capabilities` — minimum viable object to declare
- [ ] `handler_id` registry: free-form or enumerated?
- [ ] Idempotency: replay window, and what to return on a key collision with a
      different body
- [ ] Stock: reserve at `ready_for_payment` or not at all? Decide, document.
- [ ] `quote_expires_at` vs `allowance.expires_at` — which one governs?
- [ ] Webhook signing: is `Signature` + `Timestamp` specified, or merchant's choice?
---
 
## Changelog of this file
 
| Date | Change |
|---|---|
| — | Initial notes against `2026-04-17` |
 
