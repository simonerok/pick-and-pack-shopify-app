<!doctype html>
<html>
<head><meta charset="utf-8"><title>Redirecting…</title></head>
<body>
<script>
  const url = @json($redirectUri);
  if (window.top === window.self) {
    window.location.href = url;
  } else {
    window.top.location.href = url;
  }
</script>
</body>
</html>