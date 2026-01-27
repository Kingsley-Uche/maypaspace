<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking Expiration Reminder</title>
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
                            <h2 style="margin:0; font-size:20px;">Booking Expiration Reminder</h2>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:30px; color:#333333; font-size:14px; line-height:1.6;">
                            
                            <p style="margin-top:0;">
                                Dear <strong>{{ $data['name'] }}</strong>,
                            </p>

                            <p>
                                I hope this message finds you well.
                            </p>

                            <p>
                                This is a gentle reminder that your booked spot
                                (<strong>{{ $data['space'] }}</strong>) in our workspace
                                is scheduled to expire on
                                <strong>{{ $data['expiry'] }}</strong>.
                            </p>

                            <p>
                                To continue enjoying uninterrupted access to our workspace,
                                kindly ensure that payment is made on or before the expiration date.
                            </p>

                            <p>
                                Please feel free to reach out if you have any questions or need assistance.
                            </p>

                            <p style="margin-bottom:0;">
                                Best regards,<br>
                                <strong>{{ $data['tenant'] }} Team</strong>
                            </p>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f9f9f9; padding:15px; text-align:center; font-size:12px; color:#777777;">
                            © {{ date('Y') }} {{ $data['tenant'] }}. All rights reserved.
                        </td>
                    </tr>

                </table>
                <!-- End Container -->

            </td>
        </tr>
    </table>

</body>
</html>
