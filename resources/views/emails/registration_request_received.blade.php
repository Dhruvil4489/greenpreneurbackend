<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Registration Request Received</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.6;">
<p>Hello {{ $user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Peer' }},</p>

<p>Thank you for registering on Greenpreneur. We have received your registration request.</p>

<p>Your account is currently under review by our administration team. This process ensures the quality and authenticity of our community members. We will notify you via email as soon as your account is approved and ready for use.</p>

<p>If you have any questions or require further assistance, please feel free to reach out to our support team.</p>

<p>Warm regards,<br>Greenpreneur Team</p>
</body>
</html>
