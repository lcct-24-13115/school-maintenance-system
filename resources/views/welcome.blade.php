<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="0;url={{ route('login') }}">
    <title>LCCT Maintenance Ticketing System</title>
</head>
<body>
    {{-- The home page is the login page (see routes/web.php). --}}
    <a href="{{ route('login') }}">Continue to login</a>
</body>
</html>
