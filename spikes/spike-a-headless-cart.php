<?php

declare(strict_types=1);

/**
 * Spike A (Phase 2, T1) — panier PrestaShop headless.
 *
 * Question posée : peut-on construire un Cart PrestaShop en dehors du cycle
 * FrontController — sans cookie, sans session navigateur, sans Dispatcher —
 * et obtenir un total TTC identique à celui que le front-office calculerait
 * pour le même panier ?
 *
 * Ce script ne contient aucune logique ACP (pas de checkout_session, pas
 * d'endpoint). C'est un spike de panier, rien d'autre : la moitié du travail
 * réel du module consistera à remplacer "les constantes ci-dessous" par
 * "ce que l'agent envoie dans la requête", mais ce remplacement n'est pas
 * fait ici.
 *
 * Usage (depuis acp-docker/, conteneur PS9 démarré) :
 *   docker exec acp-checkout-shop-ps9 php modules/acpcheckout/spikes/spike-a-headless-cart.php
 *
 * Le vrai test n'est pas "le script tourne sans erreur". C'est : reproduire
 * le même panier (mêmes produits, mêmes déclinaisons, mêmes quantités, même
 * client, même adresse, même transporteur) en front-office, et comparer le
 * total TTC affiché en step de paiement à celui imprimé ici. Un écart d'un
 * centime est un bug de ce script, pas une approximation acceptable.
 */

// ---------------------------------------------------------------------
// Constantes d'environnement — à renseigner avant de lancer le script.
// Sans des ids réels tirés de ta base, ça échoue au premier updateQty().
// ---------------------------------------------------------------------

/**
 * Racine de l'installation PrestaShop (dossier contenant config/config.inc.php).
 *
 * Chemin *dans le conteneur* acp-checkout-shop-ps9, pas sur l'hôte : le
 * script doit tourner via `docker exec`, pas en PHP local. DB_SERVER
 * (valeur "database", le nom du service compose) n'est résolvable que
 * depuis le réseau docker — un PHP hôte ne le trouvera pas.
 */
const PS_ROOT_DIR = '/var/www/html';

/** Boutique / langue / devise / pays à monter dans le Context. */
const SHOP_ID = 1;
const LANG_ID = 1;
const CURRENCY_ID = 1;
const COUNTRY_ID = 8; // ex. France (iso_code 'fr') — ajuste au PS_COUNTRY_DEFAULT de ta boutique

/** Client existant en base. Le script ne crée ni n'authentifie personne. */
const CUSTOMER_ID = 3; // <-- À RENSEIGNER (id_customer réel du jeu de données démo)

/** Adresses existantes en base, rattachées à CUSTOMER_ID. */
const ADDRESS_DELIVERY_ID = 7; // <-- À RENSEIGNER
const ADDRESS_INVOICE_ID = 7; // <-- À RENSEIGNER

/**
 * Lignes à ajouter au panier — un produit distinct par entrée. Mets
 * id_product_attribute à 0 si le produit n'a pas de déclinaison.
 *
 * @var list<array{id_product: int, id_product_attribute: int, quantity: int}>
 */
const CART_ITEMS = [
    ['id_product' => 1, 'id_product_attribute' => 5, 'quantity' => 2], // <-- À RENSEIGNER
    ['id_product' => 6, 'id_product_attribute' => 0, 'quantity' => 1], // <-- À RENSEIGNER
];

/**
 * Fichier local qui mémorise l'id du dernier panier créé par CE script.
 * Sert uniquement à l'idempotence (voir cleanupPreviousRun()) — ce n'est
 * pas un fichier PrestaShop, il ne touche à rien d'autre que lui-même.
 */
const STATE_FILE = __DIR__ . '/.spike-a-headless-cart.state';

// ---------------------------------------------------------------------
// Étape 1 — Bootstrap PrestaShop.
// ---------------------------------------------------------------------

/**
 * Inclut config/config.inc.php : autoload des classes legacy, connexion DB,
 * Hook, Configuration — et un Context déjà partiellement peuplé (voir
 * mountHeadlessContext() pour le détail de ce qui l'est et de ce qui ne
 * l'est pas).
 */
function bootstrapPrestaShop(): void
{
    $configFile = rtrim(PS_ROOT_DIR, '/') . '/config/config.inc.php';

    if (!is_file($configFile)) {
        fwrite(STDERR, "Introuvable : {$configFile}\n");
        fwrite(STDERR, "Renseigne la constante PS_ROOT_DIR en haut du script.\n");
        exit(1);
    }

    require_once $configFile;

    if (!class_exists(Context::class)) {
        fwrite(STDERR, "config.inc.php inclus mais la classe Context n'existe pas — install cassée ?\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------
// Étape 2 — Montage manuel du Context.
// ---------------------------------------------------------------------

/**
 * Remplace tout ce que FrontController::init() ferait normalement à partir
 * du cookie et de la session. Chaque bloc ci-dessous documente ce que
 * config.inc.php a DÉJÀ fait tout seul à l'include, et pourquoi on
 * l'écrase quand même.
 */
function mountHeadlessContext(): Context
{
    $context = Context::getContext();

    // --- Boutique ---
    // config.inc.php a déjà appelé Shop::initialize() à l'include. En CLI,
    // cette méthode détecte Tools::isPHPCLI() et retombe sur
    // Configuration::get('PS_SHOP_DEFAULT') — donc $context->shop existe
    // déjà et est probablement correct sur une install mono-boutique.
    // On le fixe quand même explicitement sur SHOP_ID : c'est la seule
    // façon de ne pas dépendre de PS_SHOP_DEFAULT si jamais l'install
    // passe multi-boutique un jour. Shop::setContext() est ce qui pilote
    // réellement les jointures SQL multi-boutique (product_shop, etc.) —
    // réassigner $context->shop seul ne suffirait pas.
    $shop = new Shop(SHOP_ID);
    if (!Validate::isLoadedObject($shop)) {
        fwrite(STDERR, "Shop introuvable pour SHOP_ID=" . SHOP_ID . "\n");
        exit(1);
    }
    $context->shop = $shop;
    Shop::setContext(Shop::CONTEXT_SHOP, SHOP_ID);

    // --- Langue ---
    // config.inc.php retombe sur PS_LANG_DEFAULT faute de cookie->id_lang.
    // Même résultat probable qu'en front-office pour un visiteur non loggé,
    // mais on le fixe explicitement pour ne pas dépendre d'une config qui
    // peut changer sans que ce script s'en aperçoive.
    $language = new Language(LANG_ID);
    if (!Validate::isLoadedObject($language)) {
        fwrite(STDERR, "Language introuvable pour LANG_ID=" . LANG_ID . "\n");
        exit(1);
    }
    $context->language = $language;

    // --- Devise ---
    // ÉCART RÉEL, pas cosmétique : config.inc.php NE FIXE JAMAIS
    // $context->currency, dans aucun mode (front, back, CLI). C'est
    // normalement FrontController::init() qui le fait, en lisant le cookie
    // ou le paramètre GET ?id_currency=. Sans cette ligne,
    // Context::getContext()->currency est null et tout code de pricing qui
    // le déréférence (Cart::getOrderTotal en premier) plante.
    $currency = new Currency(CURRENCY_ID);
    if (!Validate::isLoadedObject($currency)) {
        fwrite(STDERR, "Currency introuvable pour CURRENCY_ID=" . CURRENCY_ID . "\n");
        exit(1);
    }
    $context->currency = $currency;

    // --- Pays ---
    // config.inc.php fixe déjà $context->country sur PS_COUNTRY_DEFAULT.
    // Ça ne pilote PAS le calcul de la taxe : Cart::getPackageShippingCost()
    // et le calcul de taxe lisent le pays de l'adresse de livraison du
    // panier (ADDRESS_DELIVERY_ID), pas Context::getContext()->country.
    // On le fixe quand même pour cohérence d'affichage / autres modules qui,
    // eux, lisent le Context plutôt que l'adresse.
    $country = new Country(COUNTRY_ID);
    if (!Validate::isLoadedObject($country)) {
        fwrite(STDERR, "Country introuvable pour COUNTRY_ID=" . COUNTRY_ID . "\n");
        exit(1);
    }
    $context->country = $country;

    // --- Client ---
    // ÉCART RÉEL : config.inc.php fixe déjà $context->customer — mais sur
    // un Customer() vide (id=0, id_default_group = PS_UNIDENTIFIED_GROUP),
    // faute de cookie->id_customer en CLI. Si on n'écrase pas cette ligne,
    // tout le pricing (tarifs par groupe, prix spécifiques client) est
    // calculé comme pour un visiteur anonyme, silencieusement — pas
    // d'erreur, juste un total faux.
    $customer = new Customer(CUSTOMER_ID);
    if (!Validate::isLoadedObject($customer)) {
        fwrite(STDERR, "Customer introuvable pour CUSTOMER_ID=" . CUSTOMER_ID . "\n");
        exit(1);
    }
    $context->customer = $customer;

    // Note sur Customer::isLogged() : cette méthode vérifie aussi
    // Context::getContext()->cookie->isSessionAlive(), qui sera toujours
    // false ici (le Cookie créé par config.inc.php est vide, sans session
    // PHP réelle derrière). $customer->logged = true ne change rien à ça.
    // Sans conséquence pour le calcul du total (SpecificPrice::
    // getSpecificPrice() prend id_customer en paramètre direct, pas
    // isLogged()), mais si un hook/module de ta boutique branche sur
    // isLogged(), il se comportera comme pour un invité ici.
    $context->customer->logged = true;

    return $context;
}

// ---------------------------------------------------------------------
// Idempotence — nettoie le panier fantôme du run précédent, et rien
// d'autre. Ne touche jamais customer / product / address.
// ---------------------------------------------------------------------

function cleanupPreviousRun(): void
{
    if (!is_file(STATE_FILE)) {
        return;
    }

    $previousCartId = (int) trim((string) file_get_contents(STATE_FILE));

    if ($previousCartId <= 0) {
        @unlink(STATE_FILE);

        return;
    }

    // Si le panier précédent s'est transformé en commande entre-temps, ce
    // n'est plus un fantôme — on n'y touche pas.
    if (Order::getIdByCartId($previousCartId)) {
        printf(
            "[idempotence] Panier #%d du run précédent a une commande liée, on le laisse.\n",
            $previousCartId
        );
        @unlink(STATE_FILE);

        return;
    }

    $previousCart = new Cart($previousCartId);
    if (Validate::isLoadedObject($previousCart)) {
        $previousCart->delete();
        printf("[idempotence] Panier fantôme #%d du run précédent supprimé.\n", $previousCartId);
    }

    @unlink(STATE_FILE);
}

function rememberCart(Cart $cart): void
{
    file_put_contents(STATE_FILE, (string) $cart->id);
}

// ---------------------------------------------------------------------
// Étape 3 — Créer et persister le Cart.
// ---------------------------------------------------------------------

function createCart(Context $context): Cart
{
    $cart = new Cart();
    $cart->id_shop = (int) $context->shop->id;
    $cart->id_shop_group = (int) $context->shop->id_shop_group;
    $cart->id_lang = (int) $context->language->id;
    $cart->id_currency = (int) $context->currency->id;
    $cart->id_customer = (int) $context->customer->id;
    $cart->id_guest = 0; // client identifié, pas un panier invité
    $cart->secure_key = $context->customer->secure_key;

    if (!$cart->add()) {
        fwrite(STDERR, "Échec de la création du panier.\n");
        exit(1);
    }

    $context->cart = $cart;

    return $cart;
}

// ---------------------------------------------------------------------
// Étape 4 — Ajouter les produits (chacun avec sa déclinaison) au panier.
// ---------------------------------------------------------------------

function addProductsToCart(Cart $cart): void
{
    foreach (CART_ITEMS as $item) {
        // ÉCART : updateQty() a besoin de $id_address_delivery dès cet
        // appel, pas seulement à l'étape "attacher les adresses" qui suit.
        // En front-office, ça ne se voit pas parce que l'utilisateur a déjà
        // une adresse par défaut sélectionnée avant même d'ajouter au
        // panier — ici il faut l'expliciter, sinon les lignes cart_product
        // partent avec id_address_delivery = 0 et getDeliveryOptionList()
        // ne les regroupe pas sous la bonne adresse. Un id_address_delivery
        // unique pour toutes les lignes garde tout dans un seul package —
        // c'est voulu, un panier multi-adresses est hors sujet ici.
        $result = $cart->updateQty(
            $item['quantity'],
            $item['id_product'],
            $item['id_product_attribute'] ?: null,
            false,
            'up',
            ADDRESS_DELIVERY_ID
        );

        if ($result === false || $result < 0) {
            fwrite(
                STDERR,
                "Échec de l'ajout du produit #{$item['id_product']} au panier (stock ? déclinaison invalide ?).\n"
            );
            exit(1);
        }
    }
}

// ---------------------------------------------------------------------
// Étape 5 — Rattacher les adresses de livraison et de facturation.
// ---------------------------------------------------------------------

function attachAddresses(Cart $cart): void
{
    $delivery = new Address(ADDRESS_DELIVERY_ID);
    $invoice = new Address(ADDRESS_INVOICE_ID);

    if (!Validate::isLoadedObject($delivery)) {
        fwrite(STDERR, "Address introuvable pour ADDRESS_DELIVERY_ID=" . ADDRESS_DELIVERY_ID . "\n");
        exit(1);
    }
    if (!Validate::isLoadedObject($invoice)) {
        fwrite(STDERR, "Address introuvable pour ADDRESS_INVOICE_ID=" . ADDRESS_INVOICE_ID . "\n");
        exit(1);
    }
    if ((int) $delivery->id_customer !== (int) CUSTOMER_ID) {
        fwrite(STDERR, "ADDRESS_DELIVERY_ID n'appartient pas à CUSTOMER_ID — vérifie les ids.\n");
        exit(1);
    }

    $cart->id_address_delivery = ADDRESS_DELIVERY_ID;
    $cart->id_address_invoice = ADDRESS_INVOICE_ID;

    if (!$cart->update()) {
        fwrite(STDERR, "Échec de la mise à jour des adresses du panier.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------
// Étape 6 — Lister puis sélectionner un transporteur.
// ---------------------------------------------------------------------

/**
 * @return array{0: int, 1: string} [id_carrier, nom du transporteur retenu]
 */
function selectDeliveryOption(Cart $cart): array
{
    $deliveryOptions = $cart->getDeliveryOptionList(null, true);

    if ($deliveryOptions === []) {
        fwrite(STDERR, "Aucune option de livraison — vérifie que des transporteurs couvrent le pays de l'adresse de livraison.\n");
        exit(1);
    }

    // Clé = id_address (l'adresse de livraison réelle, cf. attachAddresses()).
    // On ne présume pas de sa valeur : on prend la première (et normalement
    // unique, un seul produit == un seul package == une seule adresse ici).
    $addressKey = array_key_first($deliveryOptions);
    $optionsForAddress = $deliveryOptions[$addressKey];

    // Chaque clé d'option est une chaîne du type "2," (ids de transporteurs
    // séparés par des virgules, cf. Cart::getDeliveryOptionList()). On
    // prend la première — c'est celle au meilleur prix, PrestaShop la place
    // en tête.
    $optionKey = array_key_first($optionsForAddress);
    $option = $optionsForAddress[$optionKey];

    $carrierIds = array_keys($option['carrier_list']);
    $idCarrier = (int) $carrierIds[0];
    $carrier = new Carrier($idCarrier);

    $cart->setDeliveryOption([$addressKey => $optionKey]);
    if (!$cart->update()) {
        fwrite(STDERR, "Échec de la persistance du transporteur choisi.\n");
        exit(1);
    }

    return [$idCarrier, $carrier->name];
}

// ---------------------------------------------------------------------
// Étape 7 — Calcul du total.
// ---------------------------------------------------------------------

/**
 * @return array{
 *     subtotal_tax_excl: float,
 *     subtotal_tax_incl: float,
 *     shipping_tax_excl: float,
 *     shipping_tax_incl: float,
 *     total_tax_excl: float,
 *     total_tax_incl: float,
 *     tax_amount: float,
 * }
 */
function computeTotals(Cart $cart): array
{
    $subtotalTaxExcl = (float) $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS);
    $subtotalTaxIncl = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
    $shippingTaxExcl = (float) $cart->getOrderTotal(false, Cart::ONLY_SHIPPING);
    $shippingTaxIncl = (float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
    $totalTaxExcl = (float) $cart->getOrderTotal(false, Cart::BOTH);
    $totalTaxIncl = (float) $cart->getOrderTotal(true, Cart::BOTH);

    return [
        'subtotal_tax_excl' => $subtotalTaxExcl,
        'subtotal_tax_incl' => $subtotalTaxIncl,
        'shipping_tax_excl' => $shippingTaxExcl,
        'shipping_tax_incl' => $shippingTaxIncl,
        'total_tax_excl' => $totalTaxExcl,
        'total_tax_incl' => $totalTaxIncl,
        'tax_amount' => $totalTaxIncl - $totalTaxExcl,
    ];
}

// ---------------------------------------------------------------------
// Étape 8 — Affichage.
// ---------------------------------------------------------------------

/**
 * @param array<int, array<string, mixed>> $lines
 * @param array{
 *     subtotal_tax_excl: float,
 *     subtotal_tax_incl: float,
 *     shipping_tax_excl: float,
 *     shipping_tax_incl: float,
 *     total_tax_excl: float,
 *     total_tax_incl: float,
 *     tax_amount: float,
 * } $totals
 */
function printReport(Cart $cart, array $lines, string $carrierName, array $totals, Context $context): void
{
    $currencyIso = $context->currency->iso_code;

    printf("Panier headless #%d\n", (int) $cart->id);
    printf(
        "  boutique=%d  langue=%s  devise=%s  client=#%d (%s)\n\n",
        (int) $context->shop->id,
        $context->language->iso_code,
        $currencyIso,
        (int) $context->customer->id,
        $context->customer->email
    );

    echo "Lignes :\n";
    foreach ($lines as $line) {
        printf(
            "  - %-40s x%-3d  %8.2f %s TTC\n",
            (string) $line['name'],
            (int) $line['cart_quantity'],
            (float) $line['total_wt'],
            $currencyIso
        );
    }

    printf("\nTransporteur retenu : %s\n\n", $carrierName);

    printf("Sous-total HT   : %10.2f %s\n", $totals['subtotal_tax_excl'], $currencyIso);
    printf("Sous-total TTC  : %10.2f %s\n", $totals['subtotal_tax_incl'], $currencyIso);
    printf("Livraison HT    : %10.2f %s\n", $totals['shipping_tax_excl'], $currencyIso);
    printf("Livraison TTC   : %10.2f %s\n", $totals['shipping_tax_incl'], $currencyIso);
    printf("Taxe            : %10.2f %s\n", $totals['tax_amount'], $currencyIso);
    printf("TOTAL TTC       : %10.2f %s\n", $totals['total_tax_incl'], $currencyIso);
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

    bootstrapPrestaShop();
    cleanupPreviousRun();

    $context = mountHeadlessContext();
    $cart = createCart($context);
    addProductsToCart($cart);
    attachAddresses($cart);
    [, $carrierName] = selectDeliveryOption($cart);
    $totals = computeTotals($cart);
    $lines = $cart->getProducts(true);

    printReport($cart, $lines, $carrierName, $totals, $context);

    rememberCart($cart);
}

main();
