# JKSH Relay Worker for Back4App

This branch contains a Back4App-compatible build of the JK Social Hub relay worker.

## Important limitation

Back4App Containers expose the application over HTTP(S). This build therefore runs the signed control API and FFmpeg fan-out worker, but it does **not** provide a public RTMP ingest listener on port 1935.

For QA, use an HTTP(S), RTMP(S) or SRT source URL that the container can pull. For direct OBS/camera RTMP ingest, use a VM/VPS or another platform that supports public TCP/UDP ingress.

## Back4App deployment

Create a new Container App from GitHub and use:

- Repository: `justkalinga-alt/justkalinga-releases`
- Branch: `jksh-back4app-relay`
- Root directory: `jksh-relay-worker-back4app`
- Dockerfile: `Dockerfile`
- Port: `8080`

Environment variables:

- `PORT=8080`
- `JKSH_PUBLIC_KEY_B64=<copy from JK Social Hub live worker pairing>`
- `JKSH_KEY_ID=<copy from JK Social Hub live worker pairing>`
- `ALLOWED_SOURCE_HOSTS=` (leave empty during QA, then restrict)

After deploy, open:

`https://<your-back4app-container-domain>/v1/health`

Expected response includes:

- `ok: true`
- `contract: jksh-relay-v1`
- `paired: true`
- `runtime: back4app-container`

Keep JK Social Hub global Dry Run ON while pairing and testing the health endpoint.
