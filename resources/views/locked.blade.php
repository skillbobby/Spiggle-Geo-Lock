<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>This panel is geo locked</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f8fafc; color: #18181b; font-family: ui-sans-serif, system-ui, sans-serif; }
        main { width: min(32rem, calc(100% - 2rem)); background: white; border: 1px solid #e4e4e7; border-radius: 1rem; padding: 2rem; }
        h1 { margin: 0 0 0.5rem; font-size: 1.5rem; }
        p { margin: 0.75rem 0 0; line-height: 1.5; color: #3f3f46; }
        strong { color: #18181b; }
    </style>
</head>
<body>
    <main>
        <h1>This panel is geo locked</h1>
        <p>
            @if ($decision->reason === 'blocked')
                Your location is blocked for this role.
            @else
                This role can only open the panel from the locations on its allow rule.
            @endif
        </p>
        <p>Detected location: <strong>{{ $decision->location?->label() ?? 'Country unknown' }}</strong></p>
        <p>An administrator can change this from Geo Lock Rules.</p>
    </main>
</body>
</html>
