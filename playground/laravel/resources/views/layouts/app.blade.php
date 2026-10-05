<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }}</title>
  {!! $scripts !!}
  <style>
    body { font: 16px system-ui; max-width: 40rem; margin: 2rem auto; padding: 0 1rem }
    nav { margin-bottom: 1rem } nav a { margin-right: 1rem }
    section, .box { border: 1px solid #ccc; border-radius: 6px; padding: .5rem 1rem; margin: 1rem 0 }
    #hover { background: #eef } #hover.on { background: #cfc }
  </style>
</head>
<body>
  <nav><a href="/">Home</a> <a href="/live">Components</a> <a href="/chat/lobby?name=Ada">Chat</a></nav>
  {!! $root !!}
</body>
</html>
