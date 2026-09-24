<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FOODEX Installer</title></head>
<body>
<main>
<h1>FOODEX Setup Wizard</h1>
<p>Bootstrap framework only.</p>
<ol>
@foreach ($steps as $step)
<li>{{ $step }}</li>
@endforeach
</ol>
</main>
</body>
</html>
