@php
    /**
     * Monthly PDF report template.
     * Rendered by GenerateMonthlyReportJob and InsightsController::downloadMonthlyReport
     * via barryvdh/laravel-dompdf. Uses only inline styles that dompdf supports —
     * no flexbox, no CSS variables.
     *
     * @var string $workspace_name
     * @var string $reporting_currency
     * @var string $month_label
     * @var array  $period
     * @var array  $hero
     * @var array  $ads
     * @var array  $cogs
     * @var array  $top_products
     * @var string $generated_at
     */
    $currency = $reporting_currency ?: 'USD';
    $fmt = static function (?float $value) use ($currency): string {
        if ($value === null) return 'N/A';
        return $currency . ' ' . number_format($value, 2, '.', ',');
    };
    $pct = static function (?float $value): string {
        return $value === null ? 'N/A' : number_format($value, 1) . '%';
    };
    $ratio = static function (?float $value): string {
        return $value === null ? 'N/A' : number_format($value, 2) . '×';
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Report — {{ $workspace_name }} — {{ $month_label }}</title>
    <style>
        @page { margin: 32px 40px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #18181b;
        }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 13px; margin: 24px 0 8px; color: #52525b; text-transform: uppercase; letter-spacing: 0.04em; }
        .subtle { color: #71717a; font-size: 10px; }
        .header { border-bottom: 1px solid #e4e4e7; padding-bottom: 12px; margin-bottom: 16px; }

        table { width: 100%; border-collapse: collapse; }
        .kpi { margin-top: 8px; }
        .kpi td {
            width: 33.33%;
            padding: 12px 14px;
            border: 1px solid #e4e4e7;
            vertical-align: top;
        }
        .kpi .label { color: #71717a; font-size: 9px; text-transform: uppercase; letter-spacing: 0.06em; }
        .kpi .value { font-size: 18px; font-weight: 600; margin-top: 4px; }
        .kpi .sub { font-size: 9px; color: #a1a1aa; margin-top: 2px; }

        table.data { margin-top: 6px; }
        table.data th, table.data td {
            padding: 6px 8px;
            border-bottom: 1px solid #e4e4e7;
            text-align: left;
        }
        table.data th {
            background: #fafafa;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #71717a;
        }
        table.data td.num, table.data th.num { text-align: right; }

        .note {
            margin-top: 8px;
            padding: 10px 12px;
            background: #fafafa;
            border-left: 3px solid #d4d4d8;
            color: #52525b;
            font-size: 10px;
        }
        .footer {
            margin-top: 28px;
            padding-top: 10px;
            border-top: 1px solid #e4e4e7;
            color: #a1a1aa;
            font-size: 9px;
        }
    </style>
</head>
<body>

<div class="header">
    <h1>{{ $workspace_name }}</h1>
    <div class="subtle">Monthly report — {{ $month_label }} ({{ $period['from'] }} → {{ $period['to'] }})</div>
</div>

<h2>Store performance</h2>
<table class="kpi">
    <tr>
        <td>
            <div class="label">Revenue</div>
            <div class="value">{{ $fmt($hero['revenue']) }}</div>
            <div class="sub">{{ number_format($hero['orders']) }} orders · {{ number_format($hero['items_sold']) }} items sold</div>
        </td>
        <td>
            <div class="label">Average order value</div>
            <div class="value">{{ $fmt($hero['aov']) }}</div>
            <div class="sub">{{ number_format($hero['new_customers']) }} new · {{ number_format($hero['returning_customers']) }} returning</div>
        </td>
        <td>
            <div class="label">Contribution margin</div>
            @if ($cogs['configured'])
                <div class="value">{{ $fmt($cogs['contribution_margin']) }}</div>
                <div class="sub">{{ $pct($cogs['margin_pct']) }} · COGS {{ $fmt($cogs['total_cogs']) }}</div>
            @else
                <div class="value" style="color:#a1a1aa;">N/A</div>
                <div class="sub">COGS not configured</div>
            @endif
        </td>
    </tr>
</table>

<h2>Advertising</h2>
<table class="kpi">
    <tr>
        <td>
            <div class="label">Ad spend</div>
            <div class="value">{{ $fmt($ads['spend']) }}</div>
            <div class="sub">Campaign-level total, reporting currency</div>
        </td>
        <td>
            <div class="label">Real ROAS</div>
            <div class="value">{{ $ratio($ads['real_roas']) }}</div>
            <div class="sub">Store revenue ÷ ad spend</div>
        </td>
        <td>
            <div class="label">Platform ROAS</div>
            <div class="value">{{ $ratio($ads['platform_roas']) }}</div>
            <div class="sub">Reported by ad platforms</div>
        </td>
    </tr>
</table>

<h2>Top products</h2>
@if (count($top_products) === 0)
    <div class="note">No product revenue recorded for this period.</div>
@else
    <table class="data">
        <thead>
            <tr>
                <th>Product</th>
                <th class="num">Units</th>
                <th class="num">Revenue</th>
                <th class="num">Contribution margin</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($top_products as $product)
                <tr>
                    <td>{{ $product['name'] }}</td>
                    <td class="num">{{ number_format($product['units']) }}</td>
                    <td class="num">{{ $fmt($product['revenue']) }}</td>
                    <td class="num">{{ $product['margin'] !== null ? $fmt($product['margin']) : 'N/A' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@unless ($cogs['configured'])
    <div class="note">
        Configure COGS in your store (WooCommerce core, WPFactory, or the WooCommerce.com Cost of Goods plugin) to
        unlock contribution margin on the next monthly report.
    </div>
@endunless

<div class="footer">
    Generated {{ $generated_at }} · Nexstage
</div>

</body>
</html>
