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
                <th class="text-right">Amount</th>
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
                            {{ \Carbon\Carbon::parse($day['end_time'])->format('g:i A') }}<br>
                      
                </td>
                 
                <td>
                    Reserved Spot - <br>
                    {{ $invoice['space_category'] }}
                </td>
                <td class="text-right">&#8358;{{ number_format($invoice['space_price'], 2) }}</td>
            </tr>
 @endforeach
                    @else
                        N/A
                    @endif
            {{-- Taxes --}}
            @if (!empty($invoice['taxes']))
                @foreach ($invoice['taxes'] as $tax)
                    <tr>
                        <td></td>
                        <td>{{ $tax['tax_name'] }}</td>
                        <td class="text-right">&#8358;{{ number_format($tax['amount'], 2) }}</td>
                    </tr>
                @endforeach
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="total"><strong>Total:</strong></td>
                <td class="text-right"><strong>&#8358;{{ number_format($invoice['total_price'], 2) }}</strong></td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <p class="description">
            <strong>Information:</strong> Kindly make payment to the information below to secure your researvation.
        </p>
        <p class="description">
           <strong>Bank Name:</strong> {{ $invoice['bank_details']['bank_name'] }}<br>         
        </p>
         <p class="description">
           <strong>Account Name :</strong> {{ $invoice['bank_details']['account_name'] }}<br>         
        </p>
        <p class="description">
           <strong>Account Number :</strong> {{ $invoice['bank_details']['account_number'] }}<br>         
        </p>
        <p class="description">
            <strong>Note:</strong> This invoice is generated automatically. Please do not reply to this email.
        <p>Thank you for your patronage!</p>
    </div>
</div>

</body>
</html>
