<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <title>Email for {{ $emailContent['receiver_name'] }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 650px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .footer {
            margin-top: 25px;
            font-size: 14px;
            color: #555;
        }
        .attachments {
            margin-top: 20px;
            padding: 10px 15px;
            background: #f4f4f4;
            border-left: 4px solid #007BFF;
        }
        .attachments h4 {
            margin: 0 0 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">

        <p class="header">Hello {{ $emailContent['receiver_name'] }},</p>

        <p>
            {!! nl2br(e($emailContent['body'])) !!}
        </p>
        
        @if (!empty($files) && count($files) > 0)
            <p>This email contains attachments</p>
        @endif

        <p class="footer">
            Thanks,<br>
            Management<br>
            {{ $emailContent['company'] }}
        </p>

    </div>
</body>
</html>


