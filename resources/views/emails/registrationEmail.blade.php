<!DOCTYPE html>
<html>
<head>
    <title>Email</title>
</head>
<body>
    <h1>Hello {{ $messageContent['firstName'] }},</h1>
    <p>
        Please click the button below to log in to your account using the generated password. Ensure you change this password to make your account more secure.
    </p>
    <p><strong>Generated Password:</strong> {{ $messageContent['password'] }}</p>
    <a href="{{ url('/'.$messageContent['slug'].'/login') }}" style="display: inline-block; padding: 10px 20px; background-color: #007bff; color: #fff; text-decoration: none; border-radius: 5px;">Login</a>
    <p>Thanks,<br>{{ config('app.name') }}</p>
</body>
</html>

