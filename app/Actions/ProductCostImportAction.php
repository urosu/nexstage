<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductCost;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;

/**
 * Parses a CSV file and bulk-upserts product cost rows for a workspace.
 *
 * Expected CSV columns: product_external_id, sku, unit_cost, currency, effective_from, effective_to
 * Either product_external_id or sku must be present per row. When only sku is given, the product
 * is looked up in the products table to resolve its external_id and store_id.
 *
 * Written by: ManageController::importProductCosts (file upload)
 * Read by: ManageController::productCosts (for summary flash)
 *
 * @see PLANNING.md sections 5, 7
 */
class ProductCostImportAction
{
    /**
     * @return array{inserted: int, updated: int, failed: int, errors: string[]}
     */
    public function execute(UploadedFile $file, Workspace $workspace): array
    {
        $inserted = 0;
        $updated  = 0;
        $failed   = 0;
        $errors   = [];

        $handle  = fopen($file->getRealPath(), 'r');
        $headers = null;
        $lineNum = 0;

        while (($line = fgetcsv($handle)) !== false) {
            $lineNum++;

            if ($headers === null) {
                $headers = array_map('trim', $line);
                continue;
            }

            if (count($line) < count($headers)) {
                $failed++;
                $errors[] = "Row {$lineNum}: column count mismatch";
                continue;
            }

            $row = array_combine($headers, array_map('trim', array_slice($line, 0, count($headers))));

            // Resolve product_external_id and store_id
            $externalId = $row['product_external_id'] ?? '';
            $storeId    = null;

            if ($externalId !== '') {
                $product = Product::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('external_id', $externalId)
                    ->first();
                $storeId = $product?->store_id;
            } elseif (isset($row['sku']) && $row['sku'] !== '') {
                $product = Product::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('sku', $row['sku'])
                    ->first();
                if (! $product) {
                    $failed++;
                    $errors[] = "Row {$lineNum}: SKU '{$row['sku']}' not found";
                    continue;
                }
                $externalId = $product->external_id;
                $storeId    = $product->store_id;
            } else {
                $failed++;
                $errors[] = "Row {$lineNum}: product_external_id or sku required";
                continue;
            }

            if ($storeId === null) {
                $failed++;
                $errors[] = "Row {$lineNum}: product '{$externalId}' not found in any store";
                continue;
            }

            $unitCost = isset($row['unit_cost']) && $row['unit_cost'] !== '' ? (float) $row['unit_cost'] : null;
            if ($unitCost === null || $unitCost < 0) {
                $failed++;
                $errors[] = "Row {$lineNum}: unit_cost must be a non-negative number";
                continue;
            }

            $currency = strtoupper($row['currency'] ?? '');
            if (strlen($currency) !== 3) {
                $failed++;
                $errors[] = "Row {$lineNum}: currency must be a 3-letter ISO code";
                continue;
            }

            $effectiveFromRaw = $row['effective_from'] ?? '';
            $effectiveFrom    = $effectiveFromRaw !== '' ? $effectiveFromRaw : null;
            if ($effectiveFrom !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
                $failed++;
                $errors[] = "Row {$lineNum}: effective_from must be YYYY-MM-DD or empty";
                continue;
            }

            $effectiveTo = (($row['effective_to'] ?? '') !== '') ? $row['effective_to'] : null;

            $existing = ProductCost::withoutGlobalScopes()->where([
                'workspace_id'        => $workspace->id,
                'store_id'            => $storeId,
                'product_external_id' => $externalId,
                'effective_from'      => $effectiveFrom,
            ])->first();

            if ($existing) {
                $existing->update([
                    'unit_cost'    => $unitCost,
                    'currency'     => $currency,
                    'effective_to' => $effectiveTo,
                    'source'       => 'csv',
                ]);
                $updated++;
            } else {
                ProductCost::create([
                    'workspace_id'        => $workspace->id,
                    'store_id'            => $storeId,
                    'product_external_id' => $externalId,
                    'unit_cost'           => $unitCost,
                    'currency'            => $currency,
                    'effective_from'      => $effectiveFrom,
                    'effective_to'        => $effectiveTo,
                    'source'              => 'csv',
                ]);
                $inserted++;
            }
        }

        fclose($handle);

        return compact('inserted', 'updated', 'failed', 'errors');
    }
}
