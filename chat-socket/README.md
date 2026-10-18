# chat-socket

The socket.io relay for chat. Laravel owns every chat row; this process only
keeps open sockets, rooms and who is online.

```
npm install
PORT=3005 LARAVEL_URL=http://127.0.0.1:8000 CHAT_SOCKET_SECRET=<same as Laravel> npm start
```

- Browsers connect with the token from `GET /api/v1/chat/token` (`auth.token`);
  without one the relay asks Laravel for a token with the browser's cookies.
- `chat:join`, `chat:send`, `chat:read` go to Laravel's `/api/internal/chat/*`
  with the secret; `chat:typing` and presence stay in the relay.
- Laravel pushes REST-sent messages to `POST /internal/emit` (same secret).
- `GET /health` for the platform's health check.
