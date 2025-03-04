<!DOCTYPE html>
<html>
<head>
    <title>Email</title>
</head>
<body>
    <h1>Hello {{ $messageContent['firstName'] }},</h1>
    <p>
        You have successfully been subscribed to the {{$messageContent['planName']}} plan. This plan will expire on {{ $messageContent['planExpire'] }}.
    </p>
    <p>We hope you enjoy our platform.</p>
    <p>Thanks,<br>{{ config('app.name') }}</p>
</body>
</html>

