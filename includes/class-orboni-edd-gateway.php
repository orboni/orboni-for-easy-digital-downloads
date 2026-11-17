<?php

use EDD\Discounts\OrderValidation;
use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * "Crypto" at checkout: the buyer is sent to Orboni's payment page, picks a
 * coin and pays to the store's own wallet; the order is completed by the
 * signed webhook (`Orboni_EDD_Webhook`), by reading the session back when the
 * buyer returns, or by a scheduled read-back, whichever comes first.
 */
class Orboni_EDD_Gateway
{
    public const ID = 'orboni';

    /**
     * The currencies Orboni takes a price in, and the decimals of each one's
     * smallest unit (ISO 4217): Orboni turns a price into dollars at the
     * rate of the day, the ECB's or the market's.
     */
    public const CURRENCIES = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CHF' => 2, 'PLN' => 2, 'CZK' => 2,
        'HUF' => 2, 'RON' => 2, 'DKK' => 2, 'SEK' => 2, 'NOK' => 2, 'ISK' => 0,
        'TRY' => 2, 'JPY' => 0, 'KRW' => 0, 'CNY' => 2, 'HKD' => 2, 'SGD' => 2,
        'INR' => 2, 'IDR' => 2, 'MYR' => 2, 'PHP' => 2, 'THB' => 2, 'ILS' => 2,
        'AUD' => 2, 'NZD' => 2, 'CAD' => 2, 'MXN' => 2, 'BRL' => 2, 'ZAR' => 2,
        'UAH' => 2, 'ARS' => 2, 'CLP' => 0, 'AED' => 2, 'SAR' => 2, 'TWD' => 2,
        'VND' => 0,
    ];

    /** Orboni's least payment is worth $1: checked here in a dollar store, by Orboni in any other. */
    public const MIN_CENTS = 100;

    /**
     * Time a payment page must still have to be reused when Pay is pressed
     * again: less, and the buyer gets a new one. A page Orboni still calls
     * open may be past its time (held open while a network cannot be read).
     */
    public const REUSE_MIN_SECONDS = 300;

    public const META_SESSION = '_orboni_session_id';

    /** Every session made for the order: a buyer may still pay an older one. */
    public const META_SESSIONS = '_orboni_session_ids';

    /** The amount the current session asks, in the currency's smallest unit. */
    public const META_AMOUNT = '_orboni_amount';

    public const META_ATTEMPT = '_orboni_attempt';

    public const SECRET_KEY = '/^sk_(live|test)_[A-Za-z0-9]+$/';

    public const WEBHOOK_SECRET = '/^whsec_[A-Za-z0-9]+$/';

    public static function register()
    {
        add_filter('edd_payment_gateways', [self::class, 'add_gateway']);
        add_filter('edd_enabled_payment_gateways', [self::class, 'offer']);
        add_filter('edd_is_gateway_active', [self::class, 'is_active'], 10, 2);
        add_action('edd_'.self::ID.'_cc_form', [self::class, 'checkout_note']);
        add_action('edd_gateway_'.self::ID, [self::class, 'process_purchase']);

        add_filter('edd_settings_sections_gateways', [self::class, 'add_section']);
        add_filter('edd_settings_gateways', [self::class, 'add_settings']);
        add_filter('edd_settings_gateways-'.self::ID.'_sanitize', [self::class, 'sanitize_settings']);
        add_action('admin_notices', [self::class, 'settings_notices']);

        add_action('template_redirect', [self::class, 'on_return']);
        add_filter('the_content', [self::class, 'return_message'], 99998);
    }

    /** @param  array<string, array<string, mixed>>  $gateways */
    public static function add_gateway($gateways)
    {
        $gateways[self::ID] = [
            'admin_label' => __('Orboni (crypto)', 'orboni-for-easy-digital-downloads'),
            'checkout_label' => self::checkout_label(),
            'supports' => [],
        ];

        return $gateways;
    }

    public static function checkout_label()
    {
        $label = trim((string) edd_get_option('orboni_checkout_label', ''), " \t\n\r\0\x0B");

        return $label !== '' ? $label : __('Crypto (USDT, USDC, BTC and more)', 'orboni-for-easy-digital-downloads');
    }

    /**
     * Checkout offers crypto only where it can work: a key saved, the store's
     * currency one Orboni takes, and a dollar cart worth at least $1.
     *
     * @param  array<string, mixed>  $gateways
     */
    public static function offer($gateways)
    {
        if (isset($gateways[self::ID]) && ! self::can_offer()) {
            unset($gateways[self::ID]);
        }

        return $gateways;
    }

    /**
     * The same for "is Orboni active?": otherwise Easy Digital Downloads
     * keeps it as the store's default gateway with no other to choose, and
     * the buyer of a 50-cent cart could not pay at all.
     */
    public static function is_active($active, $gateway)
    {
        return $gateway === self::ID ? ($active && self::can_offer()) : $active;
    }

    private static function can_offer()
    {
        // The admin's lists show every gateway that is switched on.
        if (is_admin() && ! wp_doing_ajax()) {
            return true;
        }
        if (! self::is_configured()) {
            return false;
        }

        $currency = strtoupper((string) edd_get_currency());
        $total = function_exists('edd_get_cart_total') ? (float) edd_get_cart_total() : 0.0;

        return ! ($currency === 'USD' && $total > 0 && self::minor($total, $currency) < self::MIN_CENTS);
    }

    /** A key saved, in a store whose currency Orboni takes. */
    public static function is_configured()
    {
        return self::secret_key() !== '' && self::supports_currency(edd_get_currency());
    }

    public static function secret_key()
    {
        return (string) edd_get_option('orboni_secret_key', '');
    }

    public static function webhook_secret()
    {
        return (string) edd_get_option('orboni_webhook_secret', '');
    }

    /** In place of a card form: what happens when the buyer presses Purchase. */
    public static function checkout_note()
    {
        echo '<p class="orboni-edd-note">'.esc_html__('You will be taken to a secure payment page to choose a coin and pay from your wallet or exchange.', 'orboni-for-easy-digital-downloads').'</p>';
    }

    /**
     * The checkout was sent with Orboni chosen: a pending order, then the
     * buyer goes to Orboni's payment page.
     *
     * @param  array<string, mixed>  $purchase_data
     */
    public static function process_purchase($purchase_data)
    {
        if (! wp_verify_nonce((string) ($purchase_data['gateway_nonce'] ?? ''), 'edd-gateway')) {
            wp_die(esc_html__('Nonce verification has failed', 'orboni-for-easy-digital-downloads'), esc_html__('Error', 'orboni-for-easy-digital-downloads'), ['response' => 403]);
        }

        $back = '?payment-mode='.self::ID;
        $currency = strtoupper((string) edd_get_currency());
        if (! self::supports_currency($currency)) {
            edd_set_error('orboni_currency', sprintf(
                /* translators: %s: the store's currency code, e.g. ARS */
                __('Crypto payments are not taken in %s.', 'orboni-for-easy-digital-downloads'),
                $currency
            ));
            edd_send_back_to_checkout($back);

            return;
        }
        if (self::secret_key() === '') {
            edd_set_error('orboni_unavailable', self::unavailable());
            edd_send_back_to_checkout($back);

            return;
        }
        if ($currency === 'USD' && self::minor((float) ($purchase_data['price'] ?? 0), $currency) < self::MIN_CENTS) {
            edd_set_error('orboni_minimum', __('This order is below the minimum for a crypto payment ($1).', 'orboni-for-easy-digital-downloads'));
            edd_send_back_to_checkout($back);

            return;
        }

        $payment_data = [
            'price' => $purchase_data['price'],
            'user_email' => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'downloads' => $purchase_data['downloads'],
            'user_info' => $purchase_data['user_info'],
            'cart_details' => $purchase_data['cart_details'],
            'gateway' => self::ID,
            'status' => 'pending',
        ];
        if (! empty($purchase_data['date'])) {
            $payment_data['date'] = $purchase_data['date'];
        }

        // A pending order the buyer left earlier is taken up again (Easy Digital Downloads' own recovery).
        $order_id = (int) edd_build_order($payment_data);
        if ($order_id <= 0) {
            edd_record_gateway_error(__('Payment Error', 'orboni-for-easy-digital-downloads'), __('Orboni: the order could not be created before sending the buyer to pay.', 'orboni-for-easy-digital-downloads'));
            edd_set_error('orboni_unavailable', self::unavailable());
            edd_send_back_to_checkout($back);

            return;
        }

        // Easy Digital Downloads 3.7.2+: a discount whose uses ran out in the meantime fails the order.
        if (class_exists('\EDD\Discounts\OrderValidation') && method_exists('\EDD\Discounts\OrderValidation', 'refuse_unclaimed')
            && OrderValidation::refuse_unclaimed($order_id)) {
            edd_send_back_to_checkout($back);

            return;
        }

        $order = edd_get_order($order_id);
        if (! $order) {
            edd_set_error('orboni_unavailable', self::unavailable());
            edd_send_back_to_checkout($back);

            return;
        }

        $url = self::session_url($order, $currency);
        if ($url === '') {
            edd_send_back_to_checkout($back);

            return;
        }

        // The checkout's own way back to this order, should the buyer return to the cart.
        EDD()->session->set('edd_resume_payment', $order_id);

        self::redirect($url);
    }

    /**
     * The payment page for the order: the open session for its amount, or a
     * new one. An empty string after an error was set for the checkout.
     *
     * @param  Order  $order
     */
    private static function session_url($order, $currency)
    {
        $order_id = (int) $order->id;
        $amount = self::minor((float) $order->total, $currency);
        $api = new Orboni_EDD_Api(self::secret_key());

        // Pay pressed again for the same amount: the session still open is reused,
        // unless it has run out or is about to (REUSE_MIN_SECONDS).
        $existing = (string) edd_get_order_meta($order_id, self::META_SESSION, true);
        if ($existing !== '' && (int) edd_get_order_meta($order_id, self::META_AMOUNT, true) === $amount) {
            $session = $api->get_session($existing);
            if (self::reusable($session)) {
                return $session['url'];
            }
        }

        // The key is kept while a try fails, so a session Orboni made for a try
        // whose answer was lost is the one the next try gets.
        $attempt = (int) edd_get_order_meta($order_id, self::META_ATTEMPT, true) + 1;
        $body = self::session_body($order, $amount, $currency);
        $session = $api->create_session($body, self::idempotency_key($order, $amount, $currency, $attempt));

        // That lost try was sent with other details (the buyer's language, another
        // cart): Orboni refuses the key for them, and a new one is taken.
        if (is_wp_error($session) && self::error_type($session) === 'idempotency_error' && (int) (($session->get_error_data())['status'] ?? 0) === 400) {
            $attempt++;
            $session = $api->create_session($body, self::idempotency_key($order, $amount, $currency, $attempt));
        }

        if (is_wp_error($session)) {
            $type = self::error_type($session);
            Orboni_EDD_Log::error(sprintf('Order %d: Orboni refused a session (%s): %s', $order_id, $type, $session->get_error_message()));

            // Below the least Orboni takes: under a network's minimum, or worth under $1 in another currency.
            $param = is_array($session->get_error_data()) ? (string) (($session->get_error_data())['param'] ?? '') : '';
            if ($type === 'amount_too_small' || ($type === 'invalid_request_error' && $param === 'amount')) {
                edd_set_error('orboni_minimum', __('This order is below the minimum amount for a crypto payment.', 'orboni-for-easy-digital-downloads'));
            } else {
                edd_set_error('orboni_unavailable', self::unavailable());
            }

            return '';
        }

        if (! is_string($session['id'] ?? null) || ! is_string($session['url'] ?? null)) {
            Orboni_EDD_Log::error(sprintf('Order %d: Orboni answered without a session id or url.', $order_id));
            edd_set_error('orboni_unavailable', self::unavailable());

            return '';
        }

        $known = edd_get_order_meta($order_id, self::META_SESSIONS, true);
        $known = is_array($known) ? $known : [];
        $known[] = $session['id'];
        $expires = (int) ($session['expires_at'] ?? 0) ?: time() + HOUR_IN_SECONDS;

        edd_update_order_meta($order_id, self::META_SESSION, $session['id']);
        edd_update_order_meta($order_id, self::META_SESSIONS, array_values(array_unique($known)));
        edd_update_order_meta($order_id, self::META_AMOUNT, $amount);
        edd_update_order_meta($order_id, self::META_ATTEMPT, $attempt);
        edd_update_order_meta($order_id, Orboni_EDD_Order_Sync::META_EXPIRES, $expires);
        edd_insert_payment_note($order_id, sprintf(
            /* translators: %s: Orboni checkout session id */
            __('Buyer sent to the Orboni payment page (session %s).', 'orboni-for-easy-digital-downloads'),
            $session['id']
        ));
        Orboni_EDD_Order_Sync::schedule($order_id, $expires);

        Orboni_EDD_Log::info(sprintf('Order %d: session %s for %s.', $order_id, $session['id'], self::format_minor($amount, $currency)));

        return $session['url'];
    }

    /**
     * A session the buyer can still pay on: open, with its page, and more
     * than REUSE_MIN_SECONDS before it runs out.
     *
     * @param  mixed  $session
     */
    private static function reusable($session)
    {
        return is_array($session)
            && ($session['status'] ?? '') === 'open'
            && is_string($session['url'] ?? null)
            && (int) ($session['expires_at'] ?? 0) > time() + self::REUSE_MIN_SECONDS;
    }

    /** To Orboni's payment page: its host is allowed for this one redirect. */
    private static function redirect($url)
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        add_filter('allowed_redirect_hosts', static function ($hosts) use ($host) {
            $hosts[] = $host;

            return $hosts;
        });

        edd_redirect($url);
    }

    /**
     * The buyer is back from the payment page (`?payment-confirmation=orboni`):
     * before the page is drawn, the session is read once, so the order is
     * right even before the webhook comes, or without one. A completed order
     * goes on to the receipt.
     */
    public static function on_return()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link back from Orboni's payment page; the purchase session or the receipt hash checks it.
        if (! isset($_GET['payment-confirmation'], $_GET['payment-id']) || sanitize_key(wp_unslash($_GET['payment-confirmation'])) !== self::ID) {
            return;
        }
        $order = edd_get_order(absint(wp_unslash($_GET['payment-id'])));
        $hash = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (! $order || $order->gateway !== self::ID || ! self::is_buyer($order, $hash)) {
            return;
        }

        edd_empty_cart();

        if (! self::is_paid_status($order->status)) {
            $id = (string) edd_get_order_meta($order->id, self::META_SESSION, true);
            if ($id !== '' && self::secret_key() !== '') {
                $session = (new Orboni_EDD_Api(self::secret_key()))->get_session($id);
                if (is_wp_error($session)) {
                    Orboni_EDD_Log::error(sprintf('Order %d: could not read session %s: %s', $order->id, $id, $session->get_error_message()));
                } else {
                    Orboni_EDD_Order_Sync::apply_locked($order->id, $session);
                }
            }
            $order = edd_get_order($order->id);
        }

        if ($order && $order->status === 'complete') {
            edd_redirect(edd_get_success_page_uri());
        }
    }

    /**
     * Above the receipt, while the order still waits: what is happening to
     * the payment. On the page itself, confirmation or success: Easy Digital
     * Downloads before 3.2 applied its own `edd_payment_confirm_{gateway}`
     * filter on the success page alone.
     */
    public static function return_message($content)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: which order the page shows; on_return() checked the buyer.
        if (! isset($_GET['payment-confirmation'], $_GET['payment-id']) || sanitize_key(wp_unslash($_GET['payment-confirmation'])) !== self::ID) {
            return $content;
        }
        $pages = array_filter([(int) edd_get_option('confirmation_page', 0), (int) edd_get_option('success_page', 0)]);
        if (! $pages || ! is_page($pages)) {
            return $content;
        }
        $order = edd_get_order(absint(wp_unslash($_GET['payment-id'])));
        $hash = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if (! $order || $order->gateway !== self::ID || ! self::is_buyer($order, $hash)) {
            return $content;
        }

        switch ($order->status) {
            case 'processing':
                $message = __('Your payment is on its way. The order will be confirmed as soon as the network confirms the transfer, and your receipt will be emailed to you — you can close this page.', 'orboni-for-easy-digital-downloads');
                break;
            case 'on_hold':
                $message = __('Your payment has arrived and the store is checking it. You will receive your receipt once the order is confirmed.', 'orboni-for-easy-digital-downloads');
                break;
            case 'pending':
                $message = __('Your payment has not been seen yet. If you have sent it, this page will show it within a few minutes — refresh it later, or watch for the receipt in your email.', 'orboni-for-easy-digital-downloads');
                break;
            default:
                return $content;
        }

        return '<p class="orboni-edd-waiting">'.esc_html($message).'</p>'.$content;
    }

    /**
     * The order's buyer: the purchase session of this browser, or the
     * receipt link's hash (Easy Digital Downloads 3.7.1+), which then starts
     * the session the receipt needs.
     *
     * @param  Order  $order
     */
    private static function is_buyer($order, $hash)
    {
        $purchase = edd_get_purchase_session();
        if (is_array($purchase) && ! empty($purchase['purchase_key']) && hash_equals((string) $order->payment_key, (string) $purchase['purchase_key'])) {
            return true;
        }

        if ($hash !== '' && method_exists($order, 'is_receipt_hash_valid') && $order->is_receipt_hash_valid($hash)) {
            edd_set_purchase_session(['purchase_key' => $order->payment_key]);

            return true;
        }

        return false;
    }

    /** @param  array<string, string>  $sections */
    public static function add_section($sections)
    {
        $sections[self::ID] = __('Orboni', 'orboni-for-easy-digital-downloads');

        return $sections;
    }

    /** @param  array<string, mixed>  $settings */
    public static function add_settings($settings)
    {
        $settings[self::ID] = [
            'orboni_header' => [
                'id' => 'orboni_header',
                'name' => '<strong>'.esc_html__('Orboni — crypto payments', 'orboni-for-easy-digital-downloads').'</strong>',
                'type' => 'header',
            ],
            'orboni_secret_key' => [
                'id' => 'orboni_secret_key',
                'name' => __('Secret key', 'orboni-for-easy-digital-downloads'),
                'desc' => __('In your Orboni dashboard, under Developers → API keys. A test key (sk_test_…) to try it, a live key (sk_live_…) for real payments.', 'orboni-for-easy-digital-downloads'),
                'type' => 'password',
                'size' => 'regular',
            ],
            'orboni_webhook_secret' => [
                'id' => 'orboni_webhook_secret',
                'name' => __('Webhook signing secret', 'orboni-for-easy-digital-downloads'),
                'desc' => sprintf(
                    /* translators: %s: this store's webhook address */
                    __('In your Orboni dashboard, under Developers → Webhooks, add an endpoint with the address %s and paste its signing secret (whsec_…) here. Without it orders are completed when the buyer comes back to your store, or by a check a few minutes later.', 'orboni-for-easy-digital-downloads'),
                    '<code>'.esc_html(Orboni_EDD_Webhook::url()).'</code>'
                ),
                'type' => 'password',
                'size' => 'regular',
            ],
            'orboni_checkout_label' => [
                'id' => 'orboni_checkout_label',
                'name' => __('Checkout label', 'orboni-for-easy-digital-downloads'),
                'desc' => __('What the buyer sees at checkout.', 'orboni-for-easy-digital-downloads'),
                'type' => 'text',
                'size' => 'regular',
                'std' => __('Crypto (USDT, USDC, BTC and more)', 'orboni-for-easy-digital-downloads'),
            ],
            'orboni_debug' => [
                'id' => 'orboni_debug',
                'name' => __('Debug log', 'orboni-for-easy-digital-downloads'),
                'desc' => __('Write requests to Orboni and webhooks to Tools → Debug Log. Errors are written either way.', 'orboni-for-easy-digital-downloads'),
                'type' => 'checkbox',
            ],
        ];

        return $settings;
    }

    /**
     * A key or a secret pasted in the wrong field keeps the saved one, with a
     * word why.
     *
     * @param  array<string, mixed>  $input
     */
    public static function sanitize_settings($input)
    {
        $input = is_array($input) ? $input : [];
        $checks = [
            'orboni_secret_key' => [self::SECRET_KEY, __('The secret key starts with sk_live_ or sk_test_. A publishable key (pk_…) will not work here; the saved key was kept.', 'orboni-for-easy-digital-downloads')],
            'orboni_webhook_secret' => [self::WEBHOOK_SECRET, __('The webhook signing secret starts with whsec_; the saved one was kept.', 'orboni-for-easy-digital-downloads')],
        ];

        foreach ($checks as $key => [$pattern, $message]) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = trim((string) $input[$key], " \t\n\r\0\x0B");
            if ($value !== '' && preg_match($pattern, $value) !== 1) {
                if (function_exists('add_settings_error')) {
                    add_settings_error('edd-notices', 'orboni-'.$key, $message, 'error');
                }
                $value = (string) edd_get_option($key, '');
            }
            $input[$key] = $value;
        }

        return $input;
    }

    /** On the gateway's own settings: a currency Orboni does not take, and test mode. */
    public static function settings_notices()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- which settings screen is open, read only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ($page !== 'edd-settings' || $section !== self::ID || ! current_user_can('manage_shop_settings')) {
            return;
        }

        $currency = strtoupper((string) edd_get_currency());
        if (! self::supports_currency($currency)) {
            echo '<div class="notice notice-warning"><p>'.esc_html(sprintf(
                /* translators: 1: the store's currency code, e.g. ARS, 2: the codes Orboni takes, comma-separated */
                __('Orboni does not take prices in %1$s, this store\'s currency: crypto will not be offered at checkout. It takes %2$s.', 'orboni-for-easy-digital-downloads'),
                $currency,
                implode(', ', array_keys(self::CURRENCIES))
            )).'</p></div>';
        }

        if (strpos(self::secret_key(), 'sk_test_') === 0) {
            echo '<div class="notice notice-info"><p>'.esc_html__('Test mode: a test key makes test payments only. Open the payment page while signed in to your Orboni dashboard to simulate one.', 'orboni-for-easy-digital-downloads').'</p></div>';
        }
    }

    /**
     * @param  Order  $order
     * @return array<string, mixed>
     */
    private static function session_body($order, $amount, $currency)
    {
        $email = (string) $order->email;
        $body = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'description' => self::fit(self::items($order), 250),
            'details' => self::fit(sprintf(
                /* translators: 1: order number, 2: store name */
                __('Order %1$s at %2$s', 'orboni-for-easy-digital-downloads'),
                $order->get_number(),
                wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
            ), 500),
            'client_reference_id' => (string) $order->id,
            'customer_email' => is_email($email) ? $email : null,
            'locale' => str_replace('_', '-', determine_locale()),
            'success_url' => self::return_url($order),
            // Back to choose another way to pay: the checkout, whose cart is still full.
            'cancel_url' => edd_get_checkout_uri(),
            'metadata' => [
                'edd_order_id' => (string) $order->id,
                'edd_order_number' => (string) $order->get_number(),
                'edd_site' => self::site_key(),
            ],
        ];

        return array_filter($body, static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * Where the payment page sends the buyer back: the confirmation page,
     * which reads the session and goes on to the receipt.
     *
     * @param  Order  $order
     */
    private static function return_url($order)
    {
        $args = ['payment-confirmation' => self::ID, 'payment-id' => (string) $order->id];
        if (method_exists($order, 'get_receipt_hash')) {
            $args['order'] = $order->get_receipt_hash();
            $args['id'] = (string) $order->id;
        }
        $page = function_exists('edd_get_confirmation_page_uri') ? edd_get_confirmation_page_uri() : edd_get_success_page_uri();

        return add_query_arg(array_map('rawurlencode', $args), $page);
    }

    /**
     * "E-book × 2, Course — Pro" — what the payment page shows as the purchase.
     *
     * @param  Order  $order
     */
    private static function items($order)
    {
        $names = [];
        foreach ((array) $order->get_items() as $item) {
            $name = trim(wp_strip_all_tags((string) $item->product_name), " \t\n\r\0\x0B");
            $quantity = (int) $item->quantity;
            $names[] = $quantity > 1 ? $name.' × '.$quantity : $name;
        }

        return implode(', ', array_filter($names));
    }

    /** At most `$max` characters, cut at a word with an ellipsis. */
    private static function fit($text, $max)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text), " \t\n\r\0\x0B");
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,.;:').'…';
    }

    /**
     * The store, the order, the amount and the try. The order's own purchase
     * key is in it too: a store restored from a backup numbers its orders
     * again, and Orboni keeps a key a day.
     *
     * @param  Order  $order
     */
    private static function idempotency_key($order, $amount, $currency, $attempt)
    {
        $instance = substr(hash('sha256', (string) $order->payment_key), 0, 8);

        return sprintf('edd-%s-%d-%s-%d%s-%d', self::site_key(), (int) $order->id, $instance, $amount, strtolower($currency), $attempt);
    }

    private static function error_type(WP_Error $error)
    {
        $data = $error->get_error_data();

        return is_array($data) ? (string) ($data['type'] ?? '') : '';
    }

    private static function unavailable()
    {
        return __('Crypto payment is not available right now. Please try again in a minute or choose another payment method.', 'orboni-for-easy-digital-downloads');
    }

    /** Completed, or past it (refunded, revoked): a payment never changes these but a reversal. */
    public static function is_paid_status($status)
    {
        return in_array((string) $status, edd_get_complete_order_statuses(), true);
    }

    public static function supports_currency($currency)
    {
        return isset(self::CURRENCIES[strtoupper((string) $currency)]);
    }

    public static function decimals($currency)
    {
        return self::CURRENCIES[strtoupper((string) $currency)] ?? 2;
    }

    /** A price in the currency's smallest unit, as Orboni takes it: 1250 for 12.50 EUR, 1500 for 1500 JPY. */
    public static function minor($amount, $currency)
    {
        return (int) round(((float) $amount) * (10 ** self::decimals($currency)));
    }

    /** "12.50 EUR" for 1250 euro cents, "1500 JPY" for 1500 yen. */
    public static function format_minor($amount, $currency)
    {
        $decimals = self::decimals($currency);

        return number_format(((int) $amount) / (10 ** $decimals), $decimals, '.', '').' '.strtoupper((string) $currency);
    }

    /**
     * This store among others on the same Orboni project: in the idempotency
     * key and the session's metadata, so two stores' order 42 never meet.
     */
    public static function site_key()
    {
        return substr(hash('sha256', (string) home_url()), 0, 12);
    }
}
