<?php

declare(strict_types=1);

/**
 * Spike B — Stripe Shared Payment Token (SPT), round-trip + refus au plafond.
 *
 * Question posée : le contrat de l'`Allowance` côté ACP (SPEC-NOTES.md §7 —
 * max_amount, expires_at) a-t-il un équivalent vérifiable côté PSP ? Avec un
 * vrai Stripe Shared Payment Token, la réponse est oui : Stripe applique
 * lui-même le plafond et l'expiration, côté serveur, indépendamment de ce
 * que le module PrestaShop fait ou ne fait pas.
 *
 * Spike isolé : PHP 8.3+, hors module (pas de PrestaShop chargé, pas de
 * classe ACP). `composer.json` de spikes/ est un projet Composer à part,
 * volontairement pas rattaché au composer.json du module.
 *
 * Sur le VRAI Shared Payment Token :
 * Stripe expose un test helper conçu exactement pour ce spike :
 * `POST /v1/test_helpers/shared_payment/granted_tokens`, qui simule la
 * réception d'un SPT accordé par un agent, avec un plafond et une
 * expiration de test (voir docs.stripe.com/agentic-commerce/concepts/
 * shared-payment-tokens, section « Commerçants » → « Tester la réception
 * d'un SPT »). Cet endpoint vit sous une version d'API preview
 * (`2026-04-22.preview`), donc hors des méthodes typées du SDK — on
 * l'appelle via `StripeClient::rawRequest()`, le mécanisme bas niveau prévu
 * par stripe-php pour les endpoints non encore reliés.
 *
 * Si ce test helper n'est PAS accessible sur la clé de test fournie (compte
 * pas enrôlé dans le preview, quelle que soit la raison), le script bascule
 * automatiquement — au runtime, pas par une branche de code jamais testée —
 * sur un FALLBACK : un `PaymentIntent` classique, avec le plafond vérifié
 * côté script AVANT l'appel Stripe. Ce n'est PAS un vrai SPT : le fallback
 * simule le contrat (montant plafonné, refus propre au-dessus), mais Stripe
 * lui-même n'a aucune notion de plafond sur un PaymentIntent classique — le
 * script se substitue à ce que Stripe ferait normalement. Chaque sortie du
 * mode fallback est labellisée comme telle, sans ambiguïté.
 *
 * RÉSULTAT OBSERVÉ (compte de test réel, 2026-08-17) — le vrai SPT est
 * disponible, le fallback ne s'est jamais déclenché :
 *   NOMINAL   → succeeded, PaymentIntent normal.
 *   OVER-CAP  → 400 InvalidRequestException, PAS de `code` court, message :
 *               "The requested amount is greater than the remaining amount
 *               capturable with this shared payment granted token."
 *   EXPIRED   → 400 InvalidRequestException, message :
 *               "The shared payment granted token cannot be used because it
 *               is already in a deactivated state." (pas littéralement
 *               "expired" — "deactivated", cohérent avec l'enum
 *               deactivated_reason de l'objet granted_token.)
 * Donc côté handler delegate_payment (Phase 3) : ne PAS chercher un `code`
 * Stripe stable du genre `amount_exceeds_cap` — inspecter le `message`, ou
 * re-vérifier le plafond soi-même avant l'appel (déjà la règle SPEC-NOTES
 * §7 de toute façon).
 *
 * Usage :
 *   STRIPE_SECRET_KEY=sk_test_... php spikes/spike-b-stripe-spt.php
 *
 * Aucune logique ACP, aucun PrestaShop, aucun endpoint. Spike de paiement
 * pur — round-trip Stripe et rien d'autre.
 */

require __DIR__ . '/vendor/autoload.php';

// ---------------------------------------------------------------------
// Constantes — montants en centimes, entiers, jamais de float.
// ---------------------------------------------------------------------

/** Plafond du token (Allowance.max_amount côté ACP). 50,00 €. */
const CAP_AMOUNT_CENTS = 5000;

/** Cas NOMINAL : sous le plafond. 30,00 €. */
const NOMINAL_AMOUNT_CENTS = 3000;

/** Cas OVER-CAP : au-dessus du plafond. 70,00 €. */
const OVER_CAP_AMOUNT_CENTS = 7000;

const CURRENCY = 'eur';

/** Version d'API preview exigée par l'endpoint test_helpers SPT. */
const SPT_API_VERSION = '2026-04-22.preview';

/** Carte de test Stripe à succès systématique — sert de moyen de paiement sous-jacent au SPT. */
const TEST_PAYMENT_METHOD = 'pm_card_visa';

/** Carte de test Stripe qui échoue toujours pour expiration — fallback uniquement, cf. runFallbackCases(). */
const EXPIRED_TEST_PAYMENT_METHOD = 'pm_card_chargeDeclinedExpiredCard';

/** Durée de vie normale d'un token de test (secondes). */
const TOKEN_TTL_SECONDS = 3600;

/** Durée de vie du token pour le cas EXPIRED : valide à la création, expiré à l'usage. */
const SHORT_LIVED_TTL_SECONDS = 2;

/** Attente avant de tenter de charger le token à courte durée de vie. */
const EXPIRY_WAIT_SECONDS = 3;

// ---------------------------------------------------------------------
// Bootstrap.
// ---------------------------------------------------------------------

function bootstrapStripeClient(): \Stripe\StripeClient
{
    $secretKey = getenv('STRIPE_SECRET_KEY');

    if ($secretKey === false || trim($secretKey) === '') {
        fwrite(STDERR, "STRIPE_SECRET_KEY absente de l'environnement.\n");
        fwrite(STDERR, "Usage : STRIPE_SECRET_KEY=sk_test_... php spikes/spike-b-stripe-spt.php\n");
        exit(1);
    }

    return new \Stripe\StripeClient($secretKey);
}

// ---------------------------------------------------------------------
// SPT réel — via le test helper, hors méthodes typées du SDK.
// ---------------------------------------------------------------------

/**
 * Simule la réception d'un SPT accordé par un agent : plafond et
 * expiration de test, moyen de paiement sous-jacent fixé sur une carte de
 * test Stripe.
 *
 * Passe par rawRequest() — pas de classe typée dans stripe-php pour ce
 * test helper au moment où ce spike est écrit. `deserialize()` transforme
 * le JSON brut renvoyé en \Stripe\StripeObject, pour accéder aux champs en
 * notation objet (->id, ->usage_limits->max_amount, etc.) plutôt qu'un
 * tableau associatif brut.
 *
 * @throws \Stripe\Exception\ApiErrorException si le test helper n'est pas
 *         accessible sur ce compte, ou pour toute autre erreur Stripe —
 *         propagée telle quelle, pas de catch ici.
 */
function createGrantedToken(\Stripe\StripeClient $stripe, int $maxAmountCents, int $ttlSeconds): \Stripe\StripeObject
{
    $response = $stripe->rawRequest(
        'post',
        '/v1/test_helpers/shared_payment/granted_tokens',
        [
            'payment_method' => TEST_PAYMENT_METHOD,
            'usage_limits' => [
                'currency' => CURRENCY,
                'max_amount' => $maxAmountCents,
                'expires_at' => time() + $ttlSeconds,
            ],
        ],
        ['stripe_version' => SPT_API_VERSION]
    );

    return $stripe->deserialize($response->body);
}

/**
 * Charge un SPT accordé : PaymentIntent classique, avec
 * `payment_method_data.shared_payment_granted_token` au lieu d'un
 * `payment_method` direct. C'est Stripe, pas ce script, qui vérifie que
 * $amountCents respecte le plafond et que le token n'est pas expiré — le
 * refus, s'il a lieu, vient du réseau, pas d'une condition PHP locale.
 *
 * @throws \Stripe\Exception\ApiErrorException en cas de refus (plafond
 *         dépassé, token expiré/révoqué/consommé, etc.) — propagée telle
 *         quelle, à charge de l'appelant de l'afficher brute.
 */
function chargeGrantedToken(\Stripe\StripeClient $stripe, string $tokenId, int $amountCents): \Stripe\PaymentIntent
{
    return $stripe->paymentIntents->create([
        'amount' => $amountCents,
        'currency' => CURRENCY,
        'payment_method_data' => [
            'shared_payment_granted_token' => $tokenId,
        ],
        'confirm' => true,
    ]);
}

// ---------------------------------------------------------------------
// Fallback — PaymentIntent classique, plafond vérifié côté script.
// PAS un vrai SPT. Voir le commentaire en tête de fichier.
// ---------------------------------------------------------------------

/**
 * Simule le contrat du SPT (plafond) sans le vrai SPT. Le refus au-dessus
 * du plafond est une garde locale, PAS une réponse Stripe : conforme à la
 * règle SPEC-NOTES.md §7 ("Never attempt the charge and let it fail at the
 * PSP" pour un dépassement d'allowance) — donc en over-cap, cette fonction
 * ne fait aucun appel réseau.
 *
 * @return \Stripe\PaymentIntent|null null = refus local (au-dessus du plafond).
 *
 * @throws \Stripe\Exception\ApiErrorException si Stripe refuse la charge
 *         pour une autre raison (carte de test conçue pour échouer, etc.)
 *         — propagée telle quelle.
 */
function chargeWithLocalCapEnforcement(
    \Stripe\StripeClient $stripe,
    int $amountCents,
    int $capCents,
    string $paymentMethod
): ?\Stripe\PaymentIntent {
    if ($amountCents > $capCents) {
        return null;
    }

    return $stripe->paymentIntents->create([
        'amount' => $amountCents,
        'currency' => CURRENCY,
        'payment_method' => $paymentMethod,
        'confirm' => true,
    ]);
}

// ---------------------------------------------------------------------
// Affichage.
// ---------------------------------------------------------------------

/**
 * Cas censés réussir (NOMINAL) : si Stripe refuse quand même, ce n'est pas
 * un cas de test, c'est une configuration cassée (clé, compte non
 * provisionné, etc.). Affiché proprement — brut, comme les autres erreurs —
 * plutôt que de laisser remonter un stack trace PHP non attrapé.
 */
function reportUnexpectedFailure(string $label, \Stripe\Exception\ApiErrorException $e): never
{
    fwrite(STDERR, "[{$label}] ÉCHEC INATTENDU — ce cas est censé réussir.\n\n");
    printRawError($label, $e);
    exit(1);
}

/**
 * Affiche la forme brute de l'erreur Stripe — type, code, message, tels
 * que Stripe les renvoie. Ne reformate rien, n'avale rien.
 */
function printRawError(string $label, \Stripe\Exception\ApiErrorException $e): void
{
    $error = $e->getError();

    printf("[%s] Erreur Stripe brute :\n", $label);
    printf("  exception   : %s\n", get_class($e));
    printf("  http_status : %s\n", (string) $e->getHttpStatus());
    printf("  type        : %s\n", $error->type ?? 'n/a');
    printf("  code        : %s\n", $error->code ?? 'n/a');
    printf("  message     : %s\n", $error->message ?? $e->getMessage());
    printf("  decline_code: %s\n", $error->decline_code ?? 'n/a');
    printf("  param       : %s\n", $error->param ?? 'n/a');
    printf("  request_id  : %s\n", (string) $e->getRequestId());
    echo "\n";
}

function printChargeResult(string $label, \Stripe\PaymentIntent $paymentIntent): void
{
    printf(
        "[%s] Charge réussie — id=%s statut=%s montant=%d %s\n\n",
        $label,
        $paymentIntent->id,
        $paymentIntent->status,
        $paymentIntent->amount,
        strtoupper(CURRENCY)
    );
}

// ---------------------------------------------------------------------
// Cas de test — mode SPT réel.
// ---------------------------------------------------------------------

function runRealSptCases(\Stripe\StripeClient $stripe): void
{
    echo "=== NOMINAL ===\n";
    try {
        $token = createGrantedToken($stripe, CAP_AMOUNT_CENTS, TOKEN_TTL_SECONDS);
        printf("Token accordé : %s (plafond %d %s)\n", $token->id, CAP_AMOUNT_CENTS, strtoupper(CURRENCY));
        $paymentIntent = chargeGrantedToken($stripe, $token->id, NOMINAL_AMOUNT_CENTS);
        printChargeResult('NOMINAL', $paymentIntent);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        reportUnexpectedFailure('NOMINAL', $e);
    }

    echo "=== OVER-CAP ===\n";
    $token = createGrantedToken($stripe, CAP_AMOUNT_CENTS, TOKEN_TTL_SECONDS);
    printf("Token accordé : %s (plafond %d %s)\n", $token->id, CAP_AMOUNT_CENTS, strtoupper(CURRENCY));
    printf("Tentative de charge à %d %s (au-dessus du plafond)...\n", OVER_CAP_AMOUNT_CENTS, strtoupper(CURRENCY));
    try {
        chargeGrantedToken($stripe, $token->id, OVER_CAP_AMOUNT_CENTS);
        fwrite(STDERR, "[OVER-CAP] Refus attendu — Stripe a accepté une charge au-dessus du plafond.\n\n");
    } catch (\Stripe\Exception\ApiErrorException $e) {
        printRawError('OVER-CAP', $e);
    }

    echo "=== EXPIRED ===\n";
    $token = createGrantedToken($stripe, CAP_AMOUNT_CENTS, SHORT_LIVED_TTL_SECONDS);
    printf("Token accordé : %s, expire dans %ds. Attente de %ds...\n", $token->id, SHORT_LIVED_TTL_SECONDS, EXPIRY_WAIT_SECONDS);
    sleep(EXPIRY_WAIT_SECONDS);
    try {
        chargeGrantedToken($stripe, $token->id, NOMINAL_AMOUNT_CENTS);
        fwrite(STDERR, "[EXPIRED] Refus attendu — Stripe a accepté une charge avec un token expiré.\n\n");
    } catch (\Stripe\Exception\ApiErrorException $e) {
        printRawError('EXPIRED', $e);
    }
}

// ---------------------------------------------------------------------
// Cas de test — mode fallback (pas de vrai SPT sur ce compte).
// ---------------------------------------------------------------------

function runFallbackCases(\Stripe\StripeClient $stripe): void
{
    echo "=== NOMINAL (fallback : PaymentIntent classique) ===\n";
    try {
        $paymentIntent = chargeWithLocalCapEnforcement($stripe, NOMINAL_AMOUNT_CENTS, CAP_AMOUNT_CENTS, TEST_PAYMENT_METHOD);
        if ($paymentIntent !== null) {
            printChargeResult('NOMINAL', $paymentIntent);
        }
    } catch (\Stripe\Exception\ApiErrorException $e) {
        reportUnexpectedFailure('NOMINAL', $e);
    }

    echo "=== OVER-CAP (refus local — aucun appel Stripe) ===\n";
    $result = chargeWithLocalCapEnforcement($stripe, OVER_CAP_AMOUNT_CENTS, CAP_AMOUNT_CENTS, TEST_PAYMENT_METHOD);
    if ($result === null) {
        printf(
            "[OVER-CAP] Refusé avant tout appel réseau : %d %s > plafond %d %s.\n" .
            "  Ce n'est pas un rejet Stripe — pas de vrai SPT ici pour l'appliquer côté serveur.\n" .
            "  Règle SPEC-NOTES.md §7 : \"Never attempt the charge and let it fail at the PSP.\"\n\n",
            OVER_CAP_AMOUNT_CENTS,
            strtoupper(CURRENCY),
            CAP_AMOUNT_CENTS,
            strtoupper(CURRENCY)
        );
    }

    echo "=== EXPIRED (fallback : carte de test 'expired card', pas une expiration de SPT) ===\n";
    try {
        chargeWithLocalCapEnforcement($stripe, NOMINAL_AMOUNT_CENTS, CAP_AMOUNT_CENTS, EXPIRED_TEST_PAYMENT_METHOD);
        fwrite(STDERR, "[EXPIRED] Refus attendu — la carte de test 'expired card' n'a pas échoué.\n\n");
    } catch (\Stripe\Exception\ApiErrorException $e) {
        printRawError('EXPIRED', $e);
    }
}

// ---------------------------------------------------------------------
// Main.
// ---------------------------------------------------------------------

function main(): void
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "Ce script est un CLI, pas un endpoint HTTP.\n");
        exit(1);
    }

    $stripe = bootstrapStripeClient();

    echo "--- Sondage : test_helpers/shared_payment/granted_tokens disponible sur ce compte ? ---\n\n";

    try {
        $probe = createGrantedToken($stripe, CAP_AMOUNT_CENTS, TOKEN_TTL_SECONDS);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        echo "Indisponible sur cette clé — voir l'erreur brute :\n\n";
        printRawError('SPT-INDISPONIBLE', $e);
        echo "Bascule en mode FALLBACK. CE N'EST PAS UN VRAI SPT — voir le commentaire en tête de fichier.\n\n";
        runFallbackCases($stripe);

        return;
    }

    printf("Disponible. Token de sondage %s créé (plafond %d %s).\n\n", $probe->id, CAP_AMOUNT_CENTS, strtoupper(CURRENCY));
    runRealSptCases($stripe);
}

main();
