<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OTP Verification</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f5f5; font-family: Arial, Helvetica, sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f5; padding:20px;">
        <tr>
            <td align="center">

                <!-- Email Container -->
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:6px; overflow:hidden;">

                    <!-- Header -->
                    <tr>
                        <td style="background-color:#fe0002; padding:20px; text-align:center; color:#ffffff;">
                            <h2 style="margin:0; font-size:20px;">OTP Verification</h2>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:30px; color:#333333; font-size:14px; line-height:1.6;">

                            <p style="margin-top:0;">
                                Hello <strong>{{ $messageContent['firstName'] }}</strong>,
                            </p>

                            <p>
                                Your OTP code is:
                            </p>

                            <p style="font-size:24px; font-weight:bold; color:#fe0002; margin:20px 0;">
                                {{ $messageContent['otp'] }}
                            </p>

                            <p>
                                This code will expire in <strong>5 minutes</strong>.
                            </p>

                            <p style="margin-bottom:0;">
                                If you did not request this code, please ignore this message.
                            </p>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f9f9f9; padding:15px; text-align:center; font-size:12px; color:#777777;">
                            © {{ date('Y') }} Maypas Workspace. All rights reserved.
                        </td>
                    </tr>

                </table>
                <!-- End Container -->

            </td>
        </tr>
    </table>

</body>
</html>
