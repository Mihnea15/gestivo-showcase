<?php

namespace app\services;

use Yii;
use app\components\BankStatementParser;
use app\models\BankTransaction;
use app\models\Invoice;
use app\models\Payment;

/**
 * Imports a bank statement and proposes, for each incoming payment, the invoice
 * it settles — so recording payments becomes "check and confirm" instead of
 * typing every one by hand.
 *
 * Confidence levels:
 *   high   — the invoice number appears in the payment details and the amount
 *            fits in what is left to pay;
 *   medium — the amount equals the balance of exactly one open invoice of the
 *            payer (matched by fiscal code in the details or by name);
 *   low    — the amount equals the balance of exactly one open invoice overall.
 * Nothing becomes a payment until the user confirms it.
 */
class BankMatchingService
{
    /** Invoice statuses that still expect money. */
    const OPEN = [Invoice::STATUS_ISSUED, Invoice::STATUS_SENT, Invoice::STATUS_PARTIALLY_PAID, Invoice::STATUS_OVERDUE];

    /**
     * Parse and store a statement's incoming payments, then match them.
     *
     * @return array{imported: int, duplicates: int, ignored_debits: int}
     * @throws \InvalidArgumentException on an unreadable file
    // ... import(), importRow() and matchPending() omitted

    /**
     * The most likely invoice for a transaction among the open ones.
     *
     * @param Invoice[] $open
     * @return array{0: Invoice|null, 1: string|null}
     */
    public static function bestMatch(BankTransaction $tx, array $open): array
    {
        $candidates = array_values(array_filter($open, fn (Invoice $inv) => $inv->effectiveCurrency() === $tx->currency));
        $text = self::normalize((string) $tx->remittance . ' ' . (string) $tx->counterparty_name);
        $cents = (int) round((float) $tx->amount * 100);

        // 1. Invoice number in the payment details, amount fits.
        foreach ($candidates as $inv) {
            $number = self::normalize((string) $inv->invoice_number);
            if ($number !== '' && self::containsToken($text, $number) && $inv->acceptsPayment($tx->amount)) {
                return [$inv, BankTransaction::CONFIDENCE_HIGH];
            }
        }

        $exact = array_values(array_filter($candidates, fn (Invoice $inv) => (int) round($inv->getRemainingAmount() * 100) === $cents));

        // 2. Exact balance of the payer's single open invoice.
        $payer = array_values(array_filter($exact, fn (Invoice $inv) => self::isPayer($tx, $inv)));
        if (count($payer) === 1) {
            return [$payer[0], BankTransaction::CONFIDENCE_MEDIUM];
        }

        // 3. Exact balance of a single open invoice, whoever paid.
        if (count($exact) === 1) {
            return [$exact[0], BankTransaction::CONFIDENCE_LOW];
        }

        return [null, null];
    }

    /**
     * Turn a transaction into a payment on the given invoice.
     *
     * Same safeguards as a manual payment: the invoice row is locked and the
     * amount must fit in what is left to pay.
     *
     * @throws \yii\base\UserException when it cannot be applied
     */
    public static function confirm(BankTransaction $tx, int $invoiceId): Payment
    {
        if ($tx->status !== BankTransaction::STATUS_NEW) {
            throw new \yii\base\UserException(Yii::t('app', 'This bank transaction was already processed.'));
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            // The bank line is locked and re-read first: "confirm" and "confirm all
            // certain" running at once both saw it as new, and one credit became two
            // payments.
            $txStatus = $db->createCommand(
                'SELECT status FROM {{%bank_transactions}} WHERE id = :id FOR UPDATE',
                [':id' => $tx->id]
            )->queryScalar();
            if ($txStatus !== BankTransaction::STATUS_NEW) {
                throw new \yii\base\UserException(Yii::t('app', 'This bank transaction was already processed.'));
            }

            $db->createCommand('SELECT id FROM {{%invoices}} WHERE id = :id AND company_id = :c FOR UPDATE', [':id' => $invoiceId, ':c' => $tx->company_id])->queryScalar();
            $invoice = Invoice::findOne(['id' => $invoiceId, 'company_id' => $tx->company_id]);
            if (!$invoice || !in_array($invoice->status, self::OPEN, true)) {
                throw new \yii\base\UserException(Yii::t('app', 'The invoice is not open for payments.'));
            }
            // A manually chosen invoice skipped the currency check the automatic
            // match does: a EUR credit could settle a RON invoice at face value.
            if (!empty($tx->currency) && strtoupper((string) $tx->currency) !== $invoice->effectiveCurrency()) {
                throw new \yii\base\UserException(Yii::t('app', 'The payment is in {from} but the invoice is in {to}.', [
                    'from' => strtoupper((string) $tx->currency),
                    'to' => $invoice->effectiveCurrency(),
                ]));
            }
            if (!$invoice->acceptsPayment($tx->amount)) {
                throw new \yii\base\UserException(Yii::t('app', 'The amount exceeds what is left to pay on this invoice.'));
            }

            $payment = self::recordPayment($tx, $invoice, (float) $tx->amount, 'Extras bancar: ');

            $tx->status = BankTransaction::STATUS_MATCHED;
            $tx->payment_id = $payment->id;
            $tx->suggested_invoice_id = $invoice->id;
            $tx->save(false, ['status', 'payment_id', 'suggested_invoice_id']);

            $transaction->commit();
            return $payment;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
    // ... confirmAllocations(), recordPayment() and the text-normalisation helpers omitted
}
