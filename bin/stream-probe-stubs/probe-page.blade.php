<!doctype html>
<html>
<head><title>Stream probe</title></head>
<body>
<script>
(async () => {
    const t0 = performance.now();
    const response = await fetch('/probe/stream?run=' + encodeURIComponent(@json($run)));
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { value, done } = await reader.read();

        if (done) {
            console.log('PROBE done ' + (performance.now() - t0).toFixed(0));
            break;
        }

        buffer += decoder.decode(value, { stream: true });

        let index;

        while ((index = buffer.indexOf('\n\n')) !== -1) {
            const line = buffer.slice(0, index);
            buffer = buffer.slice(index + 2);
            console.log('PROBE ' + (performance.now() - t0).toFixed(0) + ' ' + line);
        }
    }
})();
</script>
</body>
</html>
