<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt PDF</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #333;
            font-size: 12px;
            line-height: 1.4;
        }

        .invoice-box {
            width: 100%;
            padding: 30px;
            border: 1px solid #eee;
        }

        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .company-info, .invoice-info {
            width: 48%;
        }

        .invoice-details {
            width: 100%;
            margin-bottom: 20px;
        }

        .invoice-details td {
            padding: 5px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th, .table td {
            text-align: left;
            padding: 8px;
            border: 1px solid #ddd;
        }

        .total, .text-right {
            text-align: right;
        }

        .description {
            margin-top: 10px;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
        }

        .bold {
            font-weight: bold;
        }

        .text-muted {
            color: #666;
        }
    </style>
</head>
<body>

@php
    $book = $receiptData['book_data'];

    $chosenDays =$receiptData['schedule'] ?? [];
@endphp

<div class="invoice-box">
    <div class="header">
        <div class="company-info">
            <h3>{{ $receiptData['tenant_name'] ?? 'Company Name' }}</h3>
            <p class="text-muted">Generated Invoice</p>
        </div>
        <div class="invoice-info text-right">
            <p><strong>Invoice Ref:</strong> {{ $receiptData['invoice_ref'] }}</p>
            <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($book->created_at)->toFormattedDateString() }}</p>
        </div>
    </div>

    <table class="invoice-details">
        <tr>
            <td><strong>Billed To:</strong><br>{{ $receiptData['user_invoice'] ?? 'N/A' }}</td>
            <td class="text-right"><strong>Issued By:</strong><br>{{ $receiptData['tenant_name'] ?? 'System' }}</td>
        </tr>
    </table>

    <table class="table">
        <thead>
            <tr>
                <th>Booked Days</th>
                <th>Description</th>
                <th class="text-right">Total </th>
            </tr>
        </thead>
        <tbody>
            @if (!empty($chosenDays))
                @foreach ($chosenDays as $day)
                    <tr>
                        <td>
                            <strong>Days & Time:</strong><br>
                            {{ ucfirst($day['day']) }} ({{ \Carbon\Carbon::parse($day['date'])->toFormattedDateString() }}):<br>
                            {{ \Carbon\Carbon::parse($day['start_time'])->format('g:i A') }} - 
                            {{ \Carbon\Carbon::parse($day['end_time'])->format('g:i A') }}

                            @php
                                $duration = \Carbon\Carbon::parse($day['start_time'])->diffInHours(\Carbon\Carbon::parse($day['end_time']));
                            @endphp
                            <br>
                            <strong>Duration:</strong> {{ $duration }} {{ $duration > 1 ? 'hours' : 'hour' }}
                        </td>
                        <td>
                            Reserved Spot -<br>
                            {{ $receiptData['space_category'] ?? 'N/A' }}
                        </td>
                        <td class="text-right">&#8358;
                            @if($receiptData['space_booking_type'] === 'hourly')
                                {{ number_format($receiptData['space_price'] * $duration, 2) }}
                                ({{ ucwords($receiptData['space_booking_type']) ?? 'N/A' }})
                            @else
                                {{ number_format($receiptData['space_price'], 2) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="3" class="text-center">N/A</td>
                </tr>
            @endif

            {{-- Taxes --}}
            @if (!empty($receiptData['taxes']))
                @foreach ($receiptData['taxes'] as $tax)
                    <tr>
                        <td><td>                        <td> Tax: {{ $tax['tax_name'] ?? 'Tax' }}</td>
                        <td class="text-right">&#8358;{{ number_format($tax['amount'], 2) }}</td>
                    </tr>
                @endforeach
            @endif
            {{-- Charges --}}
            @if (!empty($invoice['charges']))
                @foreach ($invoice['charges'] as $charge)
                    <tr>
                        <td>Charges:</td>
                        <td>{{ $charge['charge_name'] ?? 'Fee' }}</td>
                        <td class="text-right">&#8358;{{ number_format($charge['amount'], 2) }}</td>
                    </tr>
                @endforeach
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="total"><strong>Total:</strong></td>
                <td class="text-right"><strong>&#8358;{{ number_format($receiptData['total_price'], 2) }}</strong></td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <p>Thank you for your patronage!</p>
    </div>
</div>

</body>
</html>
