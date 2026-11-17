<?php

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * An Orboni checkout session, read back, onto its Easy Digital Downloads
 * order. The one place an order changes for a payment: the webhook, the
 * buyer's return and the scheduled check all come here with the session as
 * Orboni shows it now, so the order follows the payment whatever arrives
 * first, twice or out of order.
 *
 * - complete + paid → Completed (the receipt is emailed, the files unlock);
 * - on its way (processing) → Processing;
 * - short of the amount (partially_paid) or held for the merchant's review
 *   (held) → On Hold, with what happened;
 * - expired with nothing paid → Abandoned, if it is still the order's own
 *   session and the order still waits; the buyer can take it up again;
 * - a completed order is taken back to On Hold only when Orboni says money
 *   that paid it left the chain (`payment_reversed`); nothing else
 *   downgrades it.
 */
class Orboni_EDD_Order_Sync
{
    /** What was last applied, "id:status:payment_status": the same state twice adds no second note. */
    public const META_STATE = '_orboni_state';

    public const META_EXPIRES = '_orboni_expires_at';

    /** The webhook events already taken, so a retry or a replay adds nothing twice. */
    public const META_EVENTS = '_orboni_events';

    public const EVENTS_KEPT = 30;

    public const REVERSED = 'checkout.session.payment_reversed';

    public const EXTRA = 'checkout.session.extra_received';

    /** The scheduled read-back: Action Scheduler where Easy Digital Downloads ships it, WP-Cron otherwise. */
    public const CHECK_HOOK = 'orboni_edd_check_order';

    /** Minutes after the session is made, then after it expires, that it is read back. */
    private const CHECK_AFTER_CREATED = [10];

    private const CHECK_AFTER_EXPIRY = [2, 30, 65];

    /** Orboni's network keys as people write them; a network added later reads as its key. */
    private const CHAIN_NAMES = [
        'tron' => 'TRON', 'ethereum' => 'Ethereum', 'bsc' => 'BNB Chain', 'solana' => 'Solana', 'ton' => 'TON',
        'polygon' => 'Polygon', 'base' => 'Base', 'arbitrum' => 'Arbitrum', 'optimism' => 'Optimism',
        'avalanche' => 'Avalanche', 'bitcoin' => 'Bitcoin', 'litecoin' => 'Litecoin', 'dogecoin' => 'Dogecoin',
        'bitcoincash' => 'Bitcoin Cash', 'xrp' => 'XRP Ledger', 'stellar' => 'Stellar',
    ];

    /** Seconds a lock left by a request that died is waited out. */
    private const LOCK_SECONDS = 60;

    public static function register()
    {
        add_action(self::CHECK_HOOK, [self::class, 'check']);
    }

    /**
     * {@see apply()} with the order loaded fresh under a lock: the webhook
     * and the buyer's return can land in the same second.
     *
     * @param  array<string, mixed>  $session
     * @return bool|null true applied, false an event already taken, null the order is busy
     */
    public static function apply_locked($order_id, array $session, $event = '', $event_id = '')
    {
        $order_id = (int) $order_id;
        $lock = 'orboni_edd_lock_'.$order_id;
        // add_option() inserts or fails: one request at a time holds it.
        if (! add_option($lock, time(), '', false)) {
            if ((int) get_option($lock) > time() - self::LOCK_SECONDS) {
                return null;
            }
            update_option($lock, time(), false);
        }

        try {
            $order = edd_get_order($order_id);
            if (! $order) {
                return false;
            }

            if ($event_id !== '') {
                $taken = edd_get_order_meta($order_id, self::META_EVENTS, true);
                $taken = is_array($taken) ? $taken : [];
                if (in_array($event_id, $taken, true)) {
                    return false;
                }
                $taken[] = $event_id;
                edd_update_order_meta($order_id, self::META_EVENTS, array_slice($taken, -self::EVENTS_KEPT));
            }

            self::apply($order, $session, $event);

            return true;
        } finally {
            delete_option($lock);
        }
    }

    /**
     * Read-backs for a new session: the order is right even when no webhook
     * is set up and the buyer never comes back.
     */
    public static function schedule($order_id, $expires_at)
    {
        $order_id = (int) $order_id;
        $times = [];
        foreach (self::CHECK_AFTER_CREATED as $minutes) {
            $times[] = time() + $minutes * MINUTE_IN_SECONDS;
        }
        foreach (self::CHECK_AFTER_EXPIRY as $minutes) {
            $times[] = (int) $expires_at + $minutes * MINUTE_IN_SECONDS;
        }

        if (function_exists('as_schedule_single_action')) {
            $args = ['order_id' => $order_id];
            as_unschedule_all_actions(self::CHECK_HOOK, $args, 'orboni');
            foreach (array_unique($times) as $time) {
                as_schedule_single_action($time, self::CHECK_HOOK, $args, 'orboni');
            }

            return;
        }

        wp_clear_scheduled_hook(self::CHECK_HOOK, [$order_id]);
        foreach (array_unique($times) as $time) {
            wp_schedule_single_event($time, self::CHECK_HOOK, [$order_id]);
        }
    }

    /** A scheduled read-back. */
    public static function check($order_id)
    {
        $order = edd_get_order((int) $order_id);
        $secret = Orboni_EDD_Gateway::secret_key();
        if (! $order || $secret === '' || $order->gateway !== Orboni_EDD_Gateway::ID || Orboni_EDD_Gateway::is_paid_status($order->status)) {
            return;
        }

        $id = (string) edd_get_order_meta($order->id, Orboni_EDD_Gateway::META_SESSION, true);
        if ($id === '') {
            return;
        }

        $session = (new Orboni_EDD_Api($secret))->get_session($id);
        if (is_wp_error($session)) {
            Orboni_EDD_Log::error(sprintf('Order %d: scheduled check could not read session %s: %s', $order->id, $id, $session->get_error_message()));

            return;
        }

        self::apply_locked($order->id, $session);
    }

    /**
     * @param  Order  $order
     * @param  array<string, mixed>  $session  Orboni's checkout session object
     * @param  string  $event  the webhook's type, when a webhook brought it
     */
    private static function apply($order, array $session, $event = '')
    {
        $order_id = (int) $order->id;
        $id = (string) ($session['id'] ?? '');
        if ($id === '' || ! self::belongs($order, $session)) {
            Orboni_EDD_Log::error(sprintf('Order %d: session %s is not this order\'s; left alone.', $order_id, $id));

            return;
        }

        $status = (string) ($session['status'] ?? '');
        $payment = (string) ($session['payment_status'] ?? '');
        $state = $id.':'.$status.':'.$payment;
        $paid_here = $status === 'complete' && $payment === 'paid';

        if (Orboni_EDD_Gateway::is_paid_status($order->status)) {
            self::after_paid($order, $session, $event, $paid_here, $state);

            return;
        }

        if ((string) edd_get_order_meta($order_id, self::META_STATE, true) === $state) {
            return;
        }
        edd_update_order_meta($order_id, self::META_STATE, $state);

        if ($paid_here) {
            self::complete($order, $session);

            return;
        }

        switch ($payment) {
            case 'processing':
                self::move($order, 'processing', __('A payment is on the chain and waiting for confirmations. The order will be completed by itself once it is final.', 'orboni-for-easy-digital-downloads'));

                return;

            case 'partially_paid':
                self::move($order, 'on_hold', $status === 'expired'
                    ? sprintf(
                        /* translators: 1: the amount received, 2: the amount due, each with its currency, e.g. "25.00 EUR" */
                        __('The payment page closed partly paid: %1$s of %2$s arrived in your wallet. Settle it with the buyer (send the rest back or ask for the difference); Orboni will not complete this order.', 'orboni-for-easy-digital-downloads'),
                        self::money($session, 'amount_received'),
                        self::money($session, 'amount_due')
                    )
                    : sprintf(
                        /* translators: 1: the amount received, 2: the amount due, each with its currency, e.g. "25.00 EUR" */
                        __('Partly paid so far: %1$s of %2$s. The payment page asks the buyer for the rest.', 'orboni-for-easy-digital-downloads'),
                        self::money($session, 'amount_received'),
                        self::money($session, 'amount_due')
                    ));

                return;

            case 'held':
                self::move($order, 'on_hold', sprintf(
                    /* translators: %s: why Orboni held the payment, e.g. "late" or "overpaid" */
                    __('The money is in your wallet but held for your review in the Orboni dashboard (reason: %s). Accept it there and this order is completed.', 'orboni-for-easy-digital-downloads'),
                    (string) ($session['review_reason'] ?? '—')
                ));

                return;
        }

        if ($status === 'expired' && $payment === 'unpaid') {
            // A newer session (the buyer came back and pressed Purchase again) still counts.
            if ((string) edd_get_order_meta($order_id, Orboni_EDD_Gateway::META_SESSION, true) === $id && $order->status === 'pending') {
                self::move($order, 'abandoned', __('The Orboni payment page expired with nothing paid.', 'orboni-for-easy-digital-downloads'));
            }
        }
    }

    /**
     * This order's session: made for it by this store. The webhook checks
     * the same before reading anything back, and a session read back is
     * checked again here.
     *
     * @param  Order  $order
     * @param  array<string, mixed>  $session
     */
    public static function belongs($order, array $session)
    {
        $id = (string) ($session['id'] ?? '');
        $known = edd_get_order_meta($order->id, Orboni_EDD_Gateway::META_SESSIONS, true);

        return $order->gateway === Orboni_EDD_Gateway::ID
            && is_array($known) && in_array($id, $known, true)
            && (string) ($session['client_reference_id'] ?? '') === (string) $order->id
            && (string) (((array) ($session['metadata'] ?? []))['edd_site'] ?? '') === Orboni_EDD_Gateway::site_key();
    }

    /**
     * @param  Order  $order
     * @param  array<string, mixed>  $session
     */
    private static function complete($order, array $session)
    {
        $asked = (int) ($session['amount_total'] ?? 0);
        $asked_in = strtoupper((string) ($session['currency'] ?? ''));
        $currency = strtoupper((string) $order->currency);
        $due = Orboni_EDD_Gateway::minor((float) $order->total, $currency);

        // The buyer took the order up again with a dearer cart after this page was opened.
        if ($asked_in !== $currency || $asked < $due) {
            self::move($order, 'on_hold', sprintf(
                /* translators: 1: the amount the session asked, with its currency, 2: the order's total, with its currency */
                __('Orboni completed a payment of %1$s, but this order now totals %2$s. Check the order before giving access.', 'orboni-for-easy-digital-downloads'),
                Orboni_EDD_Gateway::format_minor($asked, $asked_in),
                Orboni_EDD_Gateway::format_minor($due, $currency)
            ));

            return;
        }

        edd_insert_payment_note($order->id, self::paid_note($session));
        edd_set_payment_transaction_id($order->id, (string) $session['id']);
        edd_update_order_status($order->id, 'complete');
        Orboni_EDD_Log::info(sprintf('Order %d: paid by session %s.', $order->id, $session['id']));
    }

    /**
     * @param  Order  $order
     * @param  array<string, mixed>  $session
     */
    private static function after_paid($order, array $session, $event, $paid_here, $state)
    {
        $id = (string) $session['id'];
        $paid_by = (string) $order->get_transaction_id();

        if ($paid_by === $id) {
            if ($event === self::REVERSED && ! $paid_here && $order->status === 'complete') {
                // Recorded, so the session paid in full again completes the order again.
                edd_update_order_meta($order->id, self::META_STATE, $state);
                self::move($order, 'on_hold', sprintf(
                    /* translators: %s: the amount still counted as received, with its currency */
                    __('Orboni reports that money which paid this order left the chain (a reorganisation or a double spend); %s still counts as received. Access is paused until the Orboni dashboard shows it paid again.', 'orboni-for-easy-digital-downloads'),
                    self::money($session, 'amount_received')
                ));
                Orboni_EDD_Log::error(sprintf('Order %d: payment reversed on session %s.', $order->id, $id));
            } elseif ($event === self::EXTRA) {
                edd_insert_payment_note($order->id, sprintf(
                    /* translators: %s: the amount received in all, with its currency */
                    __('More money arrived after this order was paid: %s received in all. Orboni does not complete an order twice; send the difference back from your wallet if the buyer asks.', 'orboni-for-easy-digital-downloads'),
                    self::money($session, 'amount_received')
                ));
            }

            return;
        }

        // Another of the order's sessions paid too: the buyer paid twice.
        if ($paid_here && (string) edd_get_order_meta($order->id, self::META_STATE, true) !== $state) {
            edd_update_order_meta($order->id, self::META_STATE, $state);
            edd_insert_payment_note($order->id, sprintf(
                /* translators: 1: Orboni session id, 2: the amount received, with its currency */
                __('This order was already paid, and a second payment page (%1$s) was paid as well: %2$s. Send it back to the buyer from your wallet.', 'orboni-for-easy-digital-downloads'),
                $id,
                self::money($session, 'amount_received')
            ));
            Orboni_EDD_Log::error(sprintf('Order %d: paid twice, again by session %s.', $order->id, $id));
        }
    }

    /**
     * A note, and the order's status if it changes.
     *
     * @param  Order  $order
     */
    private static function move($order, $status, $note)
    {
        edd_insert_payment_note($order->id, $note);
        if ($order->status !== $status) {
            edd_update_order_status($order->id, $status);
        }
    }

    /** "Paid with 25.00 USDT on TRON (25.00 USD) through Orboni, session cs_live_…." @param  array<string, mixed>  $session */
    private static function paid_note(array $session)
    {
        $payment = is_array($session['payment'] ?? null) ? $session['payment'] : [];
        $test = empty($session['livemode']) ? ' '.__('(test payment)', 'orboni-for-easy-digital-downloads') : '';
        $late = ! empty($session['paid_late']) ? ' '.__('It arrived after the payment page expired.', 'orboni-for-easy-digital-downloads') : '';

        if (isset($payment['coin'], $payment['amount'])) {
            return sprintf(
                /* translators: 1: coin amount, 2: coin, 3: network, 4: the amount it counted for, with its currency, 5: session id */
                __('Paid with %1$s %2$s on %3$s (%4$s) through Orboni, session %5$s.', 'orboni-for-easy-digital-downloads'),
                (string) $payment['amount'],
                (string) $payment['coin'],
                self::CHAIN_NAMES[(string) ($payment['chain'] ?? '')] ?? ucfirst((string) ($payment['chain'] ?? '')),
                self::money($session, 'amount_received'),
                (string) $session['id']
            ).$test.$late;
        }

        return sprintf(
            /* translators: 1: the amount, with its currency, 2: session id */
            __('Paid %1$s through Orboni, session %2$s.', 'orboni-for-easy-digital-downloads'),
            self::money($session, 'amount_received'),
            (string) $session['id']
        ).$test.$late;
    }

    /**
     * One of the session's decimal amounts with its currency: "25.00 EUR", "1500 JPY".
     *
     * @param  array<string, mixed>  $session
     */
    private static function money(array $session, $field)
    {
        $currency = strtoupper((string) ($session['currency'] ?? 'usd'));

        return number_format((float) ($session[$field] ?? 0), Orboni_EDD_Gateway::decimals($currency), '.', '').' '.$currency;
    }
}
