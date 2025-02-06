<!DOCTYPE html>
<html>
<head>
    <title>Otp Email</title>
</head>
<body>
    <h1>Hello {{ $messageContent['firstName'] }},</h1>
    <h1>Your OTP Code</h1>
    <p>Your OTP code is: <strong>{{ $messageContent['otp']}}</strong></p>
    <p>This code will expire in 5 minutes.</p>

</body>
</html>

