// The stream probe's browser check: rows arrive one at a time in a real browser. It drives headless
// Chrome over the DevTools protocol, with no npm dependency, and loads the probe page, whose own fetch() reader logs
// each part with performance.now().
//
//   node bin/stream-probe-browser.mjs full <page url> [body file]
//       Pass when the network log and the page's reader both see the two done rows at least 1.5 s apart. The body
//       Chrome received is written to the body file when one is given.
//
//   node bin/stream-probe-browser.mjs close <page url>
//       Close the tab right after the first done row reaches the page, and print "CLOSED <unix seconds>".
//
// Chrome is $STREAM_PROBE_CHROME, or Google Chrome's macOS path; its profile goes to $STREAM_PROBE_PROFILE, or a
// directory under the system's temporary directory.

import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [mode, url, bodyFile] = process.argv.slice(2);

if (!['full', 'close'].includes(mode) || !url) {
    console.error('Usage: node bin/stream-probe-browser.mjs {full|close} <page url> [body file]');
    process.exit(64);
}

const chromePath = process.env.STREAM_PROBE_CHROME ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const profile = process.env.STREAM_PROBE_PROFILE ?? mkdtempSync(join(tmpdir(), 'stream-probe-chrome-'));
const port = 9300 + Math.floor(Math.random() * 600);

const chrome = spawn(chromePath, [
    '--headless=new',
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${profile}`,
    '--no-first-run',
    '--no-default-browser-check',
    'about:blank',
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Stop Chrome and leave with the given code. */
async function finish(code) {
    chrome.kill();
    await sleep(200);
    process.exit(code);
}

let version;

for (let attempt = 0; attempt < 75 && !version; attempt++) {
    try {
        version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
    } catch {
        await sleep(200);
    }
}

if (!version) {
    console.error('Chrome did not open its DevTools port.');
    await finish(1);
}

console.log(`browser  ${version.Browser}`);

const socket = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((resolve) => socket.addEventListener('open', resolve));

let nextId = 1;
const pending = new Map();
const handlers = [];

socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);

    if (message.id && pending.has(message.id)) {
        pending.get(message.id)(message);
        pending.delete(message.id);

        return;
    }

    handlers.forEach((handler) => handler(message));
});

const send = (method, params = {}, sessionId = undefined) => new Promise((resolve) => {
    const id = nextId++;

    pending.set(id, resolve);
    socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
});

const { result: { targetId } } = await send('Target.createTarget', { url: 'about:blank' });
const { result: { sessionId } } = await send('Target.attachToTarget', { targetId, flatten: true });

await send('Network.enable', {}, sessionId);
await send('Runtime.enable', {}, sessionId);

const seconds = (value) => value.toFixed(3).padStart(7);

// The stream request as the network log sees it.
let requestId = null;
let t0 = null;
let streamed = false;
let body = '';
let frames = '';
let loadingFinished = null;
const networkDone = [];

// The page's own reader.
const pageDone = [];
let finished = false;
let closedAt = null;

/** Split what Chrome received into frames, and note when each done row arrived. */
function received(text, at) {
    body += text;
    frames += text;

    let index;

    while ((index = frames.indexOf('\n\n')) !== -1) {
        const frame = frames.slice(0, index);
        frames = frames.slice(index + 2);

        if (frame.includes('"type":"data-action"') && frame.includes('"status":"done"')) {
            networkDone.push(at);
            console.log(`net   ${seconds(at)}  done row: ${frame.match(/"label":"([^"]*)"/)?.[1] ?? '?'}`);
        }
    }
}

handlers.push(async (message) => {
    if (message.sessionId !== sessionId) {
        return;
    }

    const params = message.params;

    if (message.method === 'Network.requestWillBeSent' && params.request.url.includes('/probe/stream')) {
        requestId = params.requestId;
        t0 = params.timestamp;
        console.log(`net   ${seconds(0)}  request sent`);
    }

    if (params?.requestId !== requestId || requestId === null) {
        if (message.method === 'Runtime.consoleAPICalled') {
            await consoleLine(params);
        }

        return;
    }

    if (message.method === 'Network.responseReceived') {
        console.log(`net   ${seconds(params.timestamp - t0)}  response headers, ${params.response.protocol}, status ${params.response.status}`);

        // Ask Chrome to hand over the bytes with each dataReceived event, so each row is timed by its own bytes.
        const answer = await send('Network.streamResourceContent', { requestId }, sessionId);

        if (answer.error) {
            console.log(`net            streamResourceContent unavailable (${answer.error.message}); timing by chunk size only`);
        } else {
            streamed = true;
            received(Buffer.from(answer.result.bufferedData ?? '', 'base64').toString('utf8'), params.timestamp - t0);
        }
    }

    if (message.method === 'Network.dataReceived') {
        const at = params.timestamp - t0;

        if (params.data) {
            received(Buffer.from(params.data, 'base64').toString('utf8'), at);
        } else {
            console.log(`net   ${seconds(at)}  dataReceived ${params.dataLength} bytes`);
        }
    }

    if (message.method === 'Network.loadingFinished') {
        loadingFinished = params.timestamp - t0;
        console.log(`net   ${seconds(loadingFinished)}  loadingFinished`);
    }
});

/** A line the probe page logged: a part with its time, or the end of the stream. */
async function consoleLine(params) {
    const text = params.args.map((argument) => argument.value).join(' ');

    if (!text.startsWith('PROBE ')) {
        return;
    }

    const [, at, line = ''] = text.match(/^PROBE (\S+) ?(.*)$/s) ?? [];

    if (at === 'done') {
        console.log(`page  ${seconds(Number(line) / 1000)}  reader done`);
        finished = true;

        return;
    }

    if (line.includes('"type":"data-action"') && line.includes('"status":"done"')) {
        pageDone.push(Number(at) / 1000);
        console.log(`page  ${seconds(Number(at) / 1000)}  done row: ${line.match(/"label":"([^"]*)"/)?.[1] ?? '?'}`);

        if (mode === 'close' && closedAt === null) {
            await send('Target.closeTarget', { targetId });
            closedAt = Date.now() / 1000;
            console.log(`CLOSED ${closedAt.toFixed(3)}`);
            finished = true;
        }
    }
}

await send('Page.navigate', { url }, sessionId);

for (let wait = 0; wait < 150 && !finished; wait++) {
    await sleep(100);
}

await sleep(300);
socket.close();

if (mode === 'close') {
    if (closedAt === null) {
        console.error('FAILED: no done row reached the page, so the tab was never closed.');
        await finish(1);
    }

    await finish(0);
}

if (bodyFile && streamed) {
    writeFileSync(bodyFile, body);
}

const apart = (times) => times.length === 2 ? times[1] - times[0] : NaN;
const checks = [
    [`the network log has two done rows ${apart(networkDone).toFixed(3)} s apart (at least 1.5)`, apart(networkDone) >= 1.5],
    [`the page's reader has two done rows ${apart(pageDone).toFixed(3)} s apart (at least 1.5)`, apart(pageDone) >= 1.5],
    [`the first done row reached the network ${(loadingFinished - networkDone[0]).toFixed(3)} s before the stream ended (at least 1.5)`, loadingFinished - networkDone[0] >= 1.5],
];

for (const [description, passed] of checks) {
    console.log(`${passed ? 'ok' : 'FAILED'}  ${description}`);
}

await finish(checks.every(([, passed]) => passed) && streamed ? 0 : 1);
