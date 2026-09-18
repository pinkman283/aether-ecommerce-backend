<!DOCTYPE html>
<html>
<head>
    <title>Admin Invitation</title>
</head>
<body>
    <h2>You have been invited to INHALIQ Admin Portal</h2>
    <p>You have been assigned the role of <strong>{{ $role }}</strong>.</p>
    <p>Please click the link below to set your password and activate your account:</p>
    <a href="{{ $activationUrl }}" style="padding: 10px 20px; background-color: #059669; color: #fff; text-decoration: none; border-radius: 5px; display: inline-block;">Activate Account</a>
    <p>If you cannot click the button, copy and paste this link into your browser:</p>
    <p>{{ $activationUrl }}</p>
    <p>This invitation will expire in 24 hours.</p>
</body>
</html>
