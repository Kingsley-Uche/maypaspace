<!DOCTYPE html>
<html>
<head>
    <title>Email</title>
</head>
<body>
    <h3>Hello System Owner,</h3>
    <p> A System Admin, {{$messageContent['name']}} requested a password change.</p>
    <p><strong>Admin's Email:</strong>{{ $messageContent['email']}}.</p>
    <p><strong>Generated Password:</strong> {{ $messageContent['password'] }}</p>
    <p>For extra security, Ensure the password was requested by the Admin before sending them this generated password.</p>
    <p>Thanks,<br>{{ config('app.name') }}</p>
</body>
</html>

