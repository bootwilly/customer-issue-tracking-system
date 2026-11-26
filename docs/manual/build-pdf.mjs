// dist/manual.html -> dist/CITS-manual.pdf using the installed Chrome (no npm packages). Node 22+.
import { spawn } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { pathToFileURL, fileURLToPath } from 'node:url'

const dist = fileURLToPath(new URL('./dist/', import.meta.url))
const chromePath = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe'
const profile = mkdtempSync(join(tmpdir(), 'manual-pdf-'))
const port = 9334
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const chrome = spawn(chromePath, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--no-first-run', 'about:blank'], { stdio: 'ignore' })

try {
    let page
    for (let i = 0; i < 40 && !page; i++) {
        await sleep(250)
        page = await fetch(`http://127.0.0.1:${port}/json`).then((r) => r.json()).then((l) => l.find((t) => t.type === 'page')).catch(() => null)
    }
    const ws = new WebSocket(page.webSocketDebuggerUrl)
    await new Promise((r) => (ws.onopen = r))
    let seq = 0
    const pending = new Map()
    ws.onmessage = (m) => {
        const d = JSON.parse(m.data)
        pending.get(d.id)?.(d)
    }
    const send = (method, params = {}) =>
        new Promise((res, rej) => {
            pending.set(++seq, (d) => (d.error ? rej(new Error(d.error.message)) : res(d.result)))
            ws.send(JSON.stringify({ id: seq, method, params }))
        })
    await send('Emulation.setEmulatedMedia', { media: 'print' })
    await send('Page.navigate', { url: pathToFileURL(join(dist, 'manual.html')).href })
    let state
    for (let i = 0; i < 120 && !state; i++) {
        await sleep(500)
        state = (await send('Runtime.evaluate', { expression: 'document.body?.dataset.ready', returnByValue: true })).result.value
    }
    if (state !== '1') throw new Error(`render failed: ${state}`)
    await sleep(1000)
    const { data } = await send('Page.printToPDF', {
        printBackground: true,
        preferCSSPageSize: true,
        displayHeaderFooter: true,
        headerTemplate: '<span></span>',
        footerTemplate: '<div style="width:100%;font-size:8px;text-align:center;color:#64748b"><span class="pageNumber"></span> / <span class="totalPages"></span></div>',
        generateDocumentOutline: true,
    })
    writeFileSync(join(dist, 'CITS-manual.pdf'), Buffer.from(data, 'base64'))
    console.log('dist/CITS-manual.pdf')
    ws.close()
} finally {
    chrome.kill()
    await sleep(500)
    rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 300 })
}
