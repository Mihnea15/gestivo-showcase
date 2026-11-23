<?php
// Excerpt from app\components\StripeService (Gestivo). The checkout, subscription and
// per-event handler methods are omitted.

class StripeService
{
    private const WEBHOOK_CLAIM_TIMEOUT_SECONDS = 300;

    // ...

    /**
     * Process Stripe webhook event
     *
     * @param array $payload Raw webhook payload
     * @param string $signature Stripe signature header
     * @return bool
     * @throws \Exception
     */
    public function processWebhook($payload, $signature)
    {
        $webhookSecret = getenv('STRIPE_WEBHOOK_SECRET') ?: ($_ENV['STRIPE_WEBHOOK_SECRET'] ?? null);

        if (!$webhookSecret) {
            throw new \Exception('Stripe webhook secret not configured');
        }

        try {
            // Verify webhook signature
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            throw new \yii\web\BadRequestHttpException('Invalid payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            throw new \yii\web\BadRequestHttpException('Invalid signature');
        }

        // Idempotency: Stripe redelivers events on retries/network errors.
        // The row is CLAIMED here (processed_at NULL) and only marked processed
        // once the handler has actually completed — a row alone is not proof of
        // work, because the process can die between the two.
        try {
            Yii::$app->db->createCommand()->insert('{{%stripe_webhook_events}}', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ])->execute();
        } catch (\yii\db\IntegrityException $e) {
            $existing = Yii::$app->db->createCommand(
                'SELECT processed_at, created_at FROM {{%stripe_webhook_events}} WHERE event_id = :id',
                [':id' => $event->id]
            )->queryOne();

            if ($existing && !empty($existing['processed_at'])) {
                Yii::info("Duplicate Stripe event {$event->id} ({$event->type}) — already processed, acknowledging");
                return true;
            }

            // Claimed but never finished. If the claim is stale the previous run
            // died mid-handler, so take it over; if it is fresh another delivery
            // is still working on it and we must NOT acknowledge, or Stripe would
            // stop retrying an event nobody completed.
            $claimAge = $existing ? (time() - strtotime($existing['created_at'])) : PHP_INT_MAX;
            if ($claimAge < self::WEBHOOK_CLAIM_TIMEOUT_SECONDS) {
                throw new \Exception("Stripe event {$event->id} is already being processed");
            }

            Yii::warning("Reclaiming stale Stripe event {$event->id} ({$event->type}) after {$claimAge}s", __METHOD__);
            Yii::$app->db->createCommand()->update(
                '{{%stripe_webhook_events}}',
                ['created_at' => date('Y-m-d H:i:s')],
                ['event_id' => $event->id]
            )->execute();
        }

        try {
            $result = $this->dispatchWebhookEvent($event);
        } catch (\Throwable $e) {
            // Processing failed — remove the idempotency row so Stripe's retry can reprocess
            $this->forgetWebhookEvent($event->id);
            throw $e;
        }

        if (!$result) {
            $this->forgetWebhookEvent($event->id);
            return $result;
        }

        Yii::$app->db->createCommand()->update(
            '{{%stripe_webhook_events}}',
            ['processed_at' => date('Y-m-d H:i:s')],
            ['event_id' => $event->id]
        )->execute();

        return $result;
    }

    /**
     * Route a verified Stripe event to its handler.
     *
     * @param \Stripe\Event $event
     * @return bool
     */
    protected function dispatchWebhookEvent($event)
    {
        switch ($event->type) {
            case 'checkout.session.completed':
                return $this->handleCheckoutSessionCompleted($event->data->object);

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                return $this->handleSubscriptionUpdated($event->data->object);

            case 'customer.subscription.deleted':
                return $this->handleSubscriptionDeleted($event->data->object);

            case 'invoice.payment_succeeded':
                return $this->handleInvoicePaymentSucceeded($event->data->object);

            case 'invoice.payment_failed':
                return $this->handleInvoicePaymentFailed($event->data->object);

            default:
                Yii::info("Unhandled webhook event type: {$event->type}");
                return true; // Return true to acknowledge receipt
        }
    }

    /**
     * Remove an event from the idempotency store (so a Stripe retry can reprocess it).
     *
     * @param string $eventId
     */
    protected function forgetWebhookEvent($eventId)
    {
        try {
            Yii::$app->db->createCommand()
                ->delete('{{%stripe_webhook_events}}', ['event_id' => $eventId])
                ->execute();
        } catch (\Throwable $e) {
            Yii::error("Failed to remove webhook idempotency row for {$eventId}: " . $e->getMessage());
        }
    }

    // ...
}
