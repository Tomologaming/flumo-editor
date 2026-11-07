/* Test-FTP-Server: minimaler, aber echter FTP-Server fuer die Integrationstests.
   Aufruf: node ftp-server.mjs <rootVerzeichnis> <port> */
import net from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(process.argv[2] || '.');
const PORT = parseInt(process.argv[3] || '2121', 10);
const USER = process.env.WFX_FTP_USER || 'tester';
const PASS = process.env.WFX_FTP_PASS || 'secret';

fs.mkdirSync(ROOT, { recursive: true });

function safeJoin(cwd, arg) {
    const p = arg.startsWith('/') ? arg : path.posix.join(cwd, arg);
    const norm = path.posix.normalize(p);
    if (norm.includes('..')) return null;
    return norm;
}

const server = net.createServer((socket) => {
    let cwd = '/';
    let user = null;
    let authed = false;
    let dataServer = null;
    let dataSocket = null;
    let rnfr = null;

    socket.setEncoding('utf8');
    socket.write('220 Test-FTP bereit\r\n');

    const abs = (rel) => path.join(ROOT, safeJoin('/', rel || '/').replace(/^\//, ''));

    function reply(text) { if (!socket.destroyed) socket.write(text + '\r\n'); }

    function resetData() {
        if (dataSocket) { try { dataSocket.destroy(); } catch { /* ignore */ } dataSocket = null; }
        if (dataServer) { try { dataServer.close(); } catch { /* ignore */ } dataServer = null; }
    }

    function openPassive() {
        resetData();
        dataServer = net.createServer((ds) => {
            dataSocket = ds;
            ds.on('error', () => {});
        });
        dataServer.on('error', () => reply('425 Kein Datenkanal'));
        dataServer.listen(0, '127.0.0.1', () => {
            const port = dataServer.address().port;
            reply(`227 Entering Passive Mode (127,0,0,1,${Math.floor(port / 256)},${port % 256})`);
        });
    }

    function waitForData(fn) {
        let tries = 0;
        const tick = () => {
            if (dataSocket) { fn(dataSocket); return; }
            if (++tries > 500) { reply('425 Zeitueberschreitung Datenkanal'); return; }
            setTimeout(tick, 10);
        };
        tick();
    }

    function listTexts(dirReal, mode) {
        let out = '';
        if (!fs.existsSync(dirReal) || !fs.statSync(dirReal).isDirectory()) return out;
        for (const name of fs.readdirSync(dirReal)) {
            const full = path.join(dirReal, name);
            const st = fs.statSync(full);
            if (mode === 'NLST') { out += name + '\r\n'; continue; }
            if (mode === 'MLSD') {
                const t = st.isDirectory() ? 'dir' : 'file';
                out += `type=${t};size=${st.size};modify=${new Date(st.mtime).toISOString().replace(/[-:T]/g, '').slice(0, 14)}; ${name}\r\n`;
                continue;
            }
            const d = new Date(st.mtime);
            const mon = d.toLocaleString('en-US', { month: 'short' });
            const mode_str = st.isDirectory() ? 'drwxr-xr-x' : '-rw-r--r--';
            out += `${mode_str} 1 owner group ${st.isDirectory() ? 0 : st.size} ${mon} ${String(d.getDate()).padStart(2, ' ')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')} ${name}\r\n`;
        }
        return out;
    }

    socket.on('data', (chunk) => {
        for (const line of chunk.split('\r\n').filter((l) => l !== '')) {
            const idx = line.indexOf(' ');
            const cmd = (idx === -1 ? line : line.slice(0, idx)).toUpperCase();
            const arg = idx === -1 ? '' : line.slice(idx + 1);
            switch (cmd) {
                case 'USER': user = arg; reply('331 Passwort erforderlich'); break;
                case 'PASS':
                    if (user === USER && arg === PASS) { authed = true; reply('230 Angemeldet'); }
                    else reply('530 Anmeldung fehlgeschlagen');
                    break;
                case 'SYST': reply('215 UNIX Type: L8'); break;
                case 'FEAT': socket.write('211-Features:\r\n MLSD\r\n SIZE\r\n MDTM\r\n UTF8\r\n211 End\r\n'); break;
                case 'AUTH': reply('502 Nicht unterstuetzt'); break;
                case 'TYPE': reply('200 Typ gesetzt'); break;
                case 'PWD': reply(`257 "${cwd}"`); break;
                case 'CWD': {
                    const target = safeJoin(cwd, arg);
                    const real = abs(target || '/');
                    if (target === null || !fs.existsSync(real) || !fs.statSync(real).isDirectory()) reply('550 Verzeichnis nicht gefunden');
                    else { cwd = target; reply('250 OK'); }
                    break;
                }
                case 'PASV': openPassive(); break;
                case 'EPSV': reply('502 EPSV nicht unterstuetzt'); break;
                case 'LIST':
                case 'NLST':
                case 'MLSD': {
                    if (!authed) { reply('530 Nicht angemeldet'); break; }
                    const dirArg = arg ? safeJoin(cwd, arg) : cwd;
                    const real = abs(dirArg || '/');
                    const out = listTexts(real, cmd);
                    reply('150 Liste folgt');
                    waitForData((ds) => ds.end(out, () => { reply('226 Uebertragen'); resetData(); }));
                    break;
                }
                case 'SIZE': {
                    const real = abs(safeJoin(cwd, arg) || '/');
                    if (fs.existsSync(real) && fs.statSync(real).isFile()) reply('213 ' + fs.statSync(real).size);
                    else reply('550 Datei nicht gefunden');
                    break;
                }
                case 'MDTM': {
                    const real = abs(safeJoin(cwd, arg) || '/');
                    if (fs.existsSync(real)) {
                        reply('213 ' + new Date(fs.statSync(real).mtime).toISOString().replace(/[-:T]/g, '').slice(0, 14));
                    } else reply('550 Nicht gefunden');
                    break;
                }
                case 'RETR': {
                    if (!authed) { reply('530 Nicht angemeldet'); break; }
                    const real = abs(safeJoin(cwd, arg) || '/');
                    if (!fs.existsSync(real) || !fs.statSync(real).isFile()) { reply('550 Datei nicht gefunden'); break; }
                    const content = fs.readFileSync(real);
                    reply('150 Datei folgt');
                    waitForData((ds) => ds.end(content, () => { reply('226 Fertig'); resetData(); }));
                    break;
                }
                case 'STOR': {
                    if (!authed) { reply('530 Nicht angemeldet'); break; }
                    const real = abs(safeJoin(cwd, arg) || '/');
                    fs.mkdirSync(path.dirname(real), { recursive: true });
                    reply('150 Bereit');
                    waitForData((ds) => {
                        const chunks = [];
                        ds.on('data', (d) => chunks.push(Buffer.from(d)));
                        ds.on('end', () => {
                            fs.writeFileSync(real, Buffer.concat(chunks));
                            reply('226 Gespeichert');
                            resetData();
                        });
                        ds.on('error', () => reply('426 Fehler'));
                    });
                    break;
                }
                case 'DELE': {
                    const real = abs(safeJoin(cwd, arg) || '/');
                    if (fs.existsSync(real)) { fs.unlinkSync(real); reply('250 Gelöscht'); }
                    else reply('550 Nicht gefunden');
                    break;
                }
                case 'MKD': {
                    const target = safeJoin(cwd, arg);
                    const real = abs(target || '/');
                    try { fs.mkdirSync(real, { recursive: false }); reply(`257 "${target}" angelegt`); }
                    catch { reply('550 Konnte nicht angelegt werden'); }
                    break;
                }
                case 'RNFR': {
                    rnfr = abs(safeJoin(cwd, arg) || '/');
                    if (fs.existsSync(rnfr)) reply('350 Bereit');
                    else reply('550 Nicht gefunden');
                    break;
                }
                case 'RNTO': {
                    if (!rnfr) { reply('503 RNFR fehlt'); break; }
                    const real = abs(safeJoin(cwd, arg) || '/');
                    try { fs.renameSync(rnfr, real); reply('250 Umbenannt'); }
                    catch { reply('550 Fehler'); }
                    rnfr = null;
                    break;
                }
                case 'NOOP': reply('200 OK'); break;
                case 'QUIT': reply('221 Auf Wiedersehen'); socket.end(); break;
                default: reply('502 Nicht implementiert: ' + cmd);
            }
        }
    });

    socket.on('error', () => {});
    socket.on('close', resetData);
});

server.listen(PORT, '127.0.0.1', () => {
    console.log('FTP-Testserver auf 127.0.0.1:' + PORT + ' (root ' + ROOT + ')');
});

process.on('SIGTERM', () => { server.close(() => process.exit(0)); });
process.on('SIGINT', () => { server.close(() => process.exit(0)); });
