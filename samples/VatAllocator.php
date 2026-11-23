<?php

namespace app\components;

/**
 * Allocates a document-level (header) discount across VAT groups and computes
 * the tax per group.
 *
 * This exists so the stored totals and the e-Factura XML cannot disagree. The
 * XML has to allocate per VAT category — EN16931 requires each VAT breakdown's
 * tax to equal its own taxable base times its own rate — while the models used
 * to scale the summed line VAT by a single factor. With mixed rates the two
 * differ by a ban or two, which is enough for the invoice PDF, the stored total
 * and the document filed with ANAF to disagree.
 *
 * Rounding: each group is rounded to 2 decimals; the last group absorbs the
 * allocation remainder so the allowances always sum back to the exact discount.
 */
class VatAllocator
{
    /**
     * Allocate the discount and compute per-group taxable amounts and tax.
     *
     * @param array $groups rows of ['key' => mixed, 'rate' => float, 'net' => float, 'base' => float?]
     *                      where `net` is the post-item-discount base for that group and
     *                      the optional `base` is the part of it the header discount
     *                      applies to (defaults to `net`). They differ only when the
     *                      group holds an advance deduction line: the discount is on
     *                      the goods, never on the advance being subtracted.
     * @param float $discount total header discount to spread across the groups
     * @return array the same rows plus 'allowance', 'taxable' and 'tax'
     */
    public static function allocate(array $groups, float $discount): array
    {
        $totalBase = 0.0;
        $lastIndex = -1;
        foreach ($groups as $i => $g) {
            $base = (float) ($g['base'] ?? $g['net']);
            $totalBase += $base;
            // The remainder goes to the last group with a discountable base, so a
            // deduction-only group never receives an allowance.
            if ($base > 0) {
                $lastIndex = $i;
            }
        }

        $allocated = 0.0;

        foreach ($groups as $i => $g) {
            $net = round((float) $g['net'], 2);
            $base = round((float) ($g['base'] ?? $g['net']), 2);
            $groups[$i]['net'] = $net;
            unset($groups[$i]['base']);

            $allowance = 0.0;
            if ($discount > 0 && $totalBase > 0 && $base > 0) {
                if ($i === $lastIndex) {
                    // The last group absorbs the rounding remainder, so the
                    // allowances sum to exactly the requested discount.
                    $allowance = round($discount - $allocated, 2);
                } else {
                    $allowance = round($discount * ($base / $totalBase), 2);
                    $allocated += $allowance;
                }
            }

            $groups[$i]['allowance'] = $allowance;
            $groups[$i]['taxable'] = round($net - $allowance, 2);
            $groups[$i]['tax'] = round($groups[$i]['taxable'] * ((float) $g['rate'] / 100), 2);
        }

        return $groups;
    }

    /**
     * Total VAT for a set of line items after a header discount.
     *
     * @param iterable $items anything with `vat_rate` and `total_before_vat`
     * @param float $discount header discount
     * @return float
     */
    public static function totalVatForItems(iterable $items, float $discount): float
    {
        $groups = [];
        foreach ($items as $item) {
            $rate = round((float) $item->vat_rate, 2);
            $key = (string) $rate;
            if (!isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'rate' => $rate, 'net' => 0.0, 'base' => 0.0];
            }
            $groups[$key]['net'] += (float) $item->total_before_vat;
            if (!self::isDeduction($item)) {
                $groups[$key]['base'] += (float) $item->total_before_vat;
            }
        }

        $total = 0.0;
        foreach (self::allocate(array_values($groups), $discount) as $g) {
            $total += $g['tax'];
        }

        return round($total, 2);
    }

    /**
     * Whether a line is an advance deduction, which the header discount skips.
     *
     * @param object $item a document line
     */
    public static function isDeduction($item): bool
    {
        return $item instanceof \app\models\InvoiceItem && $item->isAdvanceDeduction();
    }
}
