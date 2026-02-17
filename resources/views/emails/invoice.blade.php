<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice PDF</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #333;
            font-size: 12px;
            line-height: 1.4;
        }
        .invoice-box { width: 100%; padding: 30px; border: 1px solid #eee; }
        .header { display: flex; justify-content: space-between; margin-bottom: 20px; }
        .company-info, .invoice-info { width: 48%; }
        .invoice-details { width: 100%; margin-bottom: 20px; }
        .invoice-details td { padding: 5px; }
        .table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table th, .table td { text-align: left; padding: 8px; border: 1px solid #ddd; }
        .total, .text-right { text-align: right; }
        .description { margin-top: 10px; }
        .footer { text-align: center; margin-top: 30px; }
        .bold { font-weight: bold; }
        .text-muted { color: #666; }
    </style>
</head>
<body>

@php
    $book = $invoice['book_data'];
    $chosenDays = $invoice['schedule'] ?? [];
@endphp

<div class="invoice-box">
    <div class="header">
        <div class="company-info">
            <h3>{{ $invoice['tenant_name'] ?? 'Company Name' }}</h3>
            <p class="text-muted">Generated Invoice</p>
        </div>
        <div class="invoice-info text-right">
            <p><strong>Invoice Ref:</strong> {{ $invoice['invoice_ref'] }}</p>
            <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($book->created_at)->toFormattedDateString() }}</p>
        </div>
    </div>

    <table class="invoice-details">
        <tr>
            <td><strong>Billed To:</strong><br>{{ $invoice['user_invoice'] ?? 'N/A' }}</td>
            <td class="text-right"><strong>Issued By:</strong><br>{{ $invoice['booked_by_user'] ?? 'System' }}</td>
        </tr>
    </table>

    <table class="table">
        <thead>
            <tr>
                <th>Booked Days</th>
                <th>Description</th>
                <th class="text-right">Charged: ({{ ucwords($invoice['space_booking_type']) ?? 'N/A' }})</th>
            </tr>
        </thead>
        <tbody>
        @if (!empty($chosenDays))
            @foreach ($chosenDays as $day)
                <tr>
                    <td>
                        <strong>Days & Time:</strong><br>
                        @php
                            $tz = $invoice['time_zone'] ?? 'UTC';

                            $start = \Carbon\Carbon::parse($day['start_time'])->setTimezone($tz);
                            $end   = \Carbon\Carbon::parse($day['end_time'])->setTimezone($tz);

                            // Hourly duration (rounded up)
                            $hourDuration = max(1, ceil($start->diffInMinutes($end) / 60));
                        @endphp

                        {{ ucfirst($day['day']) }}
                        ({{ \Carbon\Carbon::parse($day['date'])->format('F j, Y') }}):<br>
                        {{ $start->format('g:i A') }} - {{ $end->format('g:i A') }}<br>

                        <strong>Duration:</strong>
                        {{ $hourDuration }} {{ Str::plural('hour', $hourDuration) }}
                    </td>

                    <td>
                        Reserved Spot -<br>
                        {{ $invoice['space_category'] ?? 'N/A' }}
                    </td>

                    <td class="text-right">
                        &#8358;
                        @php
                            $price = (float) ($invoice['space_price'] ?? 0);
                            $type  = $invoice['space_booking_type'] ?? 'hourly';
                            $info  = $invoice['invoice_info'] ?? [];

                            $days   = (int) ($info['number_days'] ?? 1);
                            $weeks  = (int) ($info['number_weeks'] ?? 1);
                            $months = (int) ($info['number_months'] ?? 1);

                            switch ($type) {
                                case 'hourly':
                                    $unitCount = $hourDuration;
                                    $unitLabel = Str::plural('hour', $unitCount);
                                    break;
                                case 'daily':
                                    $unitCount = max(1, $days);
                                    $unitLabel = Str::plural('day', $unitCount);
                                    break;
                                case 'weekly':
                                    $unitCount = max(1, $weeks);
                                    $unitLabel = Str::plural('week', $unitCount);
                                    break;
                                case 'monthly':
                                    $unitCount = max(1, $months);
                                    $unitLabel = Str::plural('month', $unitCount);
                                    break;
                                default:
                                    $unitCount = 1;
                                    $unitLabel = 'unit';
                            }

                            $amount = $price * $unitCount;
                        @endphp

                        {{ number_format($amount, 2) }}
                        <br>
                        <small class="text-muted">
                            {{ $unitCount }} {{ $unitLabel }}
                        </small>
                    </td>
                </tr>
            @endforeach
        @else
            <tr>
                <td colspan="3" class="text-center">N/A</td>
            </tr>
        @endif

        {{-- Taxes --}}
        @if (!empty($invoice['taxes']))
            @foreach ($invoice['taxes'] as $tax)
                <tr>
                    <td>Tax:</td>
                    <td>{{ ucfirst($tax['tax_name'] ?? 'Tax') }}</td>
                    <td class="text-right">&#8358;{{ number_format($tax['amount'], 2) }}</td>
                </tr>
            @endforeach
        @endif

        {{-- Charges --}}
        @if (!empty($invoice['charges']))
            @foreach ($invoice['charges'] as $charge)
                <tr>
                    <td>Charges:</td>
                    <td>{{ ucfirst($charge['charge_name'] ?? 'Fee') }}({{ number_format($charge['unit_amount'], 2) }}) x {{ $charge['quantity'] ?? 1 }}</td>
                    <td class="text-right">&#8358;{{ number_format($charge['total_amount'], 2) }}</td>
                </tr>
            @endforeach
        @endif
        </tbody>

        <tfoot>
            <tr>
                <td colspan="2" class="total"><strong>Total:</strong></td>
                <td class="text-right">
                    <strong>&#8358;{{ number_format($invoice['total_price'], 2) }}</strong>
                </td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <p class="description"><strong>Information:</strong> Kindly make payment to secure your reservation.</p>
        <p class="description"><strong>Bank Name:</strong> {{ucwords($invoice['bank_details']['bank_name'] ?? 'N/A') }}</p>
        <p class="description"><strong>Account Name:</strong> {{ ucwords($invoice['bank_details']['account_name'] ?? 'N/A') }}</p>
        <p class="description"><strong>Account Number:</strong> {{ $invoice['bank_details']['account_number'] ?? 'N/A' }}</p>
        <p class="description"><strong>Note:</strong> This invoice is generated automatically.</p>
        <p>Thank you for your patronage!</p>
    </div>
</div>

</body>
</html>
