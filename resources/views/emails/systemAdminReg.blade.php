<!DOCTYPE html>
<html>
<head>
    <title>Email</title>
</head>
<body>
    <h1>Hello {{ $messageContent['firstName'] }},</h1>
    <p>
        Kindly log in to your system admin account using the generated password and your email. Ensure you change this password to make your account more secure.
    </p>
    <p><strong>Admin's Email:</strong>{{ $messageContent['email']}}.</p>
    <p><strong>Generated Password:</strong> {{ $messageContent['password'] }}</p>
    <p>Thanks,<br>{{ config('app.name') }}</p>
</body>
</html>

