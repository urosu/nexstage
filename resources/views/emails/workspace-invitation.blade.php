<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workspace Invitation</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f4f4f5; margin: 0; padding: 40px 20px; color: #18181b; }
        .container { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 8px; border: 1px solid #e4e4e7; padding: 40px; }
        .logo { font-size: 22px; font-weight: 700; color: #4f46e5; margin-bottom: 32px; }
        h1 { font-size: 20px; font-weight: 600; margin: 0 0 16px; }
        p { font-size: 15px; line-height: 1.6; color: #52525b; margin: 0 0 16px; }
        .button { display: inline-block; background: #4f46e5; color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-size: 15px; font-weight: 500; margin: 8px 0 24px; }
        .footer { font-size: 13px; color: #a1a1aa; margin-top: 32px; border-top: 1px solid #f4f4f5; padding-top: 24px; }
        .url { font-size: 12px; color: #a1a1aa; word-break: break-all; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">Nexstage</div>

        <h1>You've been invited to join {{ $workspace->name }}</h1>

        <p>
            You've been invited to join <strong>{{ $workspace->name }}</strong> on Nexstage
            as <strong>{{ ucfirst($invitation->role) }}</strong>.
        </p>

        <p>This invitation expires in 7 days.</p>

        <a href="{{ $acceptUrl }}" class="button">
            {{ $userExists ? 'Accept invitation' : 'Create account &amp; accept' }}
        </a>

        <p>Or copy and paste this link into your browser:</p>
        <p class="url">{{ $acceptUrl }}</p>

        <div class="footer">
            <p>If you did not expect this invitation, you can safely ignore this email.</p>
        </div>
    </div>
</body>
</html>
