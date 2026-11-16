<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Frame Studio — a thoughtful image-generation workspace with transparent pricing.">
    <meta name="robots" content="noindex,nofollow">
    @vite(['resources/js/app.js'])
    @inertiaHead
</head>
<body>@inertia</body>
</html>
