/*
 * Chat socket relay. Laravel owns every chat row; this process only keeps the
 * open sockets, rooms and who is online. No business logic, no database.
 *
 *   Browser ──socket.io──► relay ──HTTP + secret──► Laravel /api/internal/chat/*
 *   Laravel ──HTTP + secret──► relay /internal/emit ──► room
 *
 * Connect: the browser passes the token from GET /api/v1/chat/token
 * (auth.token). Without one, the relay asks Laravel for a token with the
 * browser's own cookies and x-portal-type (v1 cookie handshake).
 *
 * Env: PORT (3005), LARAVEL_URL, CHAT_SOCKET_SECRET (same as Laravel's),
 *      CORS_ORIGINS (comma-separated; empty = any).
 */
const http = require('http');
const crypto = require('crypto');
const { Server } = require('socket.io');

const PORT = Number(process.env.PORT || 3005);
const LARAVEL_URL = String(process.env.LARAVEL_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const SECRET = process.env.CHAT_SOCKET_SECRET || '';
const ORIGINS = String(process.env.CORS_ORIGINS || '').split(',').map((o) => o.trim()).filter(Boolean);

if (!SECRET) {
    console.error('CHAT_SOCKET_SECRET is required.');
    process.exit(1);
}

/* ── Token: HS256, signed by Laravel ─────────────────────────────────────── */
const b64url = (buf) => Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const verify = (token) => {
    const [head, body, sig] = String(token || '').split('.');
    if (!head || !body || !sig) return null;
    const expected = b64url(crypto.createHmac('sha256', SECRET).update(`${head}.${body}`).digest());
    const a = Buffer.from(sig);
    const b = Buffer.from(expected);
    if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
    try {
        const claims = JSON.parse(Buffer.from(body.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8'));
        return claims.exp > Date.now() / 1000 && claims.actor ? claims : null;
    } catch {
        return null;
    }
};

/* ── Calls to Laravel ────────────────────────────────────────────────────── */
const laravel = async (path, socket, body) => {
    const res = await fetch(`${LARAVEL_URL}/api/internal/chat/${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Relay-Secret': SECRET },
        body: JSON.stringify({ token: socket.data.token, ...body }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.success === false) throw new Error(json.message || `Laravel answered ${res.status}`);
    return json.data;
};

// The v1 cookie handshake: Laravel signs the browser in by its own cookies.
const tokenFromCookies = async (socket) => {
    const cookie = socket.handshake.headers.cookie;
    if (!cookie) return null;
    const portal = String(socket.handshake.auth?.portalType || socket.handshake.headers['x-portal-type'] || '');
    const res = await fetch(`${LARAVEL_URL}/api/v1/chat/token`, {
        headers: { cookie, Accept: 'application/json', ...(portal ? { 'x-portal-type': portal } : {}) },
    });
    const json = await res.json().catch(() => ({}));
    return res.ok ? json?.data?.token || null : null;
};

/* ── Presence (in memory; one relay process) ─────────────────────────────── */
const online = new Map();
const keyOf = (actor) => `${actor.type}:${actor.id}`;
const snapshot = () => [...online.keys()].map((k) => {
    const [type, id] = k.split(':');
    return { actor_type: type, actor_id: Number(id), online: true };
});

/* ── HTTP: Laravel's pushes ──────────────────────────────────────────────── */
let io;
const server = http.createServer((req, res) => {
    if (req.method === 'GET' && req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ ok: true, online: online.size }));
    }
    if (req.method !== 'POST' || req.url !== '/internal/emit') {
        res.writeHead(404);
        return res.end();
    }
    const given = Buffer.from(String(req.headers['x-relay-secret'] || ''));
    const secret = Buffer.from(SECRET);
    if (given.length !== secret.length || !crypto.timingSafeEqual(given, secret)) {
        res.writeHead(403);
        return res.end();
    }
    let raw = '';
    req.on('data', (chunk) => {
        raw += chunk;
        if (raw.length > 1_000_000) req.destroy();
    });
    req.on('end', () => {
        try {
            const { room, event, data } = JSON.parse(raw);
            if (typeof room === 'string' && typeof event === 'string' && event.startsWith('chat:')) {
                io.to(room).emit(event, data);
            }
            res.writeHead(204);
        } catch {
            res.writeHead(400);
        }
        res.end();
    });
});

/* ── Sockets ─────────────────────────────────────────────────────────────── */
io = new Server(server, {
    cors: { origin: ORIGINS.length ? ORIGINS : true, credentials: true },
});

io.use(async (socket, next) => {
    try {
        let token = socket.handshake.auth?.token;
        let claims = verify(token);
        if (!claims) {
            token = await tokenFromCookies(socket);
            claims = verify(token);
        }
        if (!claims) return next(new Error('Authentication required.'));
        socket.data.token = token;
        socket.data.actor = claims.actor;
        socket.join(`actor:${keyOf(claims.actor)}`);
        return next();
    } catch {
        return next(new Error('Authentication required.'));
    }
});

io.on('connection', (socket) => {
    const actor = socket.data.actor;
    const key = keyOf(actor);
    online.set(key, (online.get(key) || 0) + 1);
    if (online.get(key) === 1) io.emit('chat:presence', { actor_type: actor.type, actor_id: actor.id, online: true });
    socket.emit('chat:presence:list', snapshot());

    const reply = (ack, payload) => typeof ack === 'function' && ack(payload);

    socket.on('chat:join', async ({ conversationId } = {}, ack) => {
        try {
            const conversation = await laravel('join', socket, { conversation_id: Number(conversationId) });
            socket.join(`chat:${conversation.id}`);
            reply(ack, { success: true, conversation });
        } catch (err) {
            reply(ack, { success: false, message: err.message || 'Unable to join conversation.' });
        }
    });

    socket.on('chat:send', async (payload = {}, ack) => {
        try {
            const message = await laravel('send', socket, { payload });
            io.to(`chat:${message.conversation_id}`).emit('chat:message', message);
            reply(ack, { success: true, message });
        } catch (err) {
            reply(ack, { success: false, message: err.message || 'Unable to send message.' });
        }
    });

    // Typing is only relayed into rooms this socket was allowed to join.
    socket.on('chat:typing', ({ conversationId, isTyping = true } = {}) => {
        const room = `chat:${Number(conversationId)}`;
        if (!socket.rooms.has(room)) return;
        socket.to(room).emit('chat:typing', {
            conversation_id: Number(conversationId), actor_type: actor.type, actor_id: actor.id, is_typing: Boolean(isTyping),
        });
    });

    socket.on('chat:read', async ({ conversationId, messageId } = {}, ack) => {
        try {
            const state = await laravel('read', socket, { conversation_id: Number(conversationId), message_id: messageId });
            socket.to(`chat:${Number(conversationId)}`).emit('chat:read', {
                conversation_id: Number(conversationId), actor_type: actor.type, actor_id: actor.id,
                last_read_message_id: state.last_read_message_id,
            });
            reply(ack, { success: true, state });
        } catch (err) {
            reply(ack, { success: false, message: err.message || 'Unable to mark read.' });
        }
    });

    socket.on('disconnect', () => {
        const left = (online.get(key) || 1) - 1;
        if (left > 0) return online.set(key, left);
        online.delete(key);
        io.emit('chat:presence', { actor_type: actor.type, actor_id: actor.id, online: false });
    });
});

server.listen(PORT, () => console.log(`chat-socket relay on :${PORT} → ${LARAVEL_URL}`));
