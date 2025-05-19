<!DOCTYPE html>
<html>
<head>
    <title>Notification Email</title>
</head>
<body>
<body>
    <p>Dear {{ $data['name'] }},</p>

    <p>{{ $data['message'] }}</p>

    <p>Thank you for being part of our community.</p>

    <p>Warm regards,<br>
    {{ config('app.name') }}</p>
</body>

</body>
</html>