<?php

defined('ABSPATH') || exit;

/**
 * `POST /wp-json/orboni-edd/v1/webhook`: Orboni's events for this store. The
 * signature is checked, the event matched to its order, and the session read
 * back from Orboni before anything changes — the order follows the session as
 * it is now, never an old event's copy of it.
 *
 * Answers: 200 for an event taken or one that is not this store's (Orboni
 * sends every event of a project to each of its endpoints, and a non-2xx
 * would be sent again for days); 401 for a bad signature; 400 for a body that
 * is no event; 503 while the store cannot decide yet (no signing secret
 * saved, Orboni not answering, the order busy) — Orboni retries it later.
 */
class Orboni_EDD_Webhook
{
    /** Apart from Orboni for WooCommerce's `orboni/v1`: a site may run both. */
    public const ROUTE_NAMESPACE = 'orboni-edd/v1';

    public const ROUTE = '/webhook';

    public static function register()
    {
        add_action('rest_api_init', static function () {
            register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                // Orboni's server, not a signed-in user: the signature is the check.
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function url()
    {
        return rest_url(self::ROUTE_NAMESPACE.self::ROUTE);
    }

    public static function handle(WP_REST_Request $request)
    {
        $secret = Orboni_EDD_Gateway::webhook_secret();
        $key = Orboni_EDD_Gateway::secret_key();
        if ($secret === '' || $key === '') {
            return self::answer(503, 'not_configured');
        }

        $body = (string) $request->get_body();
        if (! Orboni_EDD_Signature::verify($secret, $body, $request->get_header('webhook_signature'))) {
            Orboni_EDD_Log::error('Webhook refused: the signature does not match this store\'s signing secret.');

            return self::answer(401, 'bad_signature');
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ! is_string($event['type'] ?? null) || ! is_array($event['data']['object'] ?? null)) {
            return self::answer(400, 'bad_event');
        }

        $type = $event['type'];
        $object = $event['data']['object'];
        if (strpos($type, 'checkout.session.') !== 0) {
            return self::answer(200, 'ignored');
        }

        $id = (string) ($object['id'] ?? '');
        $reference = (string) ($object['client_reference_id'] ?? '');
        $site = (string) (((array) ($object['metadata'] ?? []))['edd_site'] ?? '');
        if (preg_match(Orboni_EDD_Api::SESSION_ID, $id) !== 1 || ! ctype_digit($reference) || $site !== Orboni_EDD_Gateway::site_key()) {
            // Another store's, or a payment link's: not ours to act on.
            return self::answer(200, 'ignored');
        }

        $order = edd_get_order((int) $reference);
        $known = $order ? edd_get_order_meta($order->id, Orboni_EDD_Gateway::META_SESSIONS, true) : null;
        if (! $order || $order->gateway !== Orboni_EDD_Gateway::ID || ! is_array($known) || ! in_array($id, $known, true)) {
            Orboni_EDD_Log::info(sprintf('Webhook %s for session %s: no order of this store has it.', $type, $id));

            return self::answer(200, 'ignored');
        }

        $session = (new Orboni_EDD_Api($key))->get_session($id);
        if (is_wp_error($session)) {
            Orboni_EDD_Log::error(sprintf('Webhook %s, order %d: could not read session %s back: %s', $type, $order->id, $id, $session->get_error_message()));

            return self::answer(503, 'retry_later');
        }

        $taken = Orboni_EDD_Order_Sync::apply_locked($order->id, $session, $type, (string) ($event['id'] ?? ''));
        if ($taken === null) {
            return self::answer(503, 'busy');
        }

        Orboni_EDD_Log::info(sprintf('Webhook %s, order %d: %s.', $type, $order->id, $taken ? 'applied' : 'already taken'));

        return self::answer(200, $taken ? 'received' : 'duplicate');
    }

    private static function answer($code, $result)
    {
        return new WP_REST_Response(['result' => $result], $code);
    }
}
