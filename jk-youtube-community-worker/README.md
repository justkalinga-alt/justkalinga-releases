# JK YouTube Community Worker v0.9.3.1

Windows + Node + Playwright worker for JustKalinga Social Hub.

## Architecture

This worker uses **outbound HTTPS polling**:

1. Windows worker polls JK Social Hub every 20 seconds.
2. Hub leases one due YouTube Community job.
3. Worker opens the authenticated YouTube profile.
4. It fills the Community composer with the exact caption + original images.
5. It clicks **Post**.
6. It verifies a real public `youtube.com/post/...` URL.
7. It sends that receipt back to Social Hub.
8. Hub marks the Community job Published only after receipt verification.

No inbound tunnel, public port, Cloudflare Tunnel, or Back4App worker is required for Community posts.

## Safety

- Stable idempotency key per Hub job.
- Local persistent ledger prevents blind double-posting.
- If Post may have been clicked but a public URL cannot be confirmed, the worker reports **uncertain** and automatic retry is blocked.
- Hub does not mark Published without a public YouTube `/post/` URL.

## Install on the existing Windows machine

Target folder can remain:

`D:\Dev\JKSocialHub\jk-youtube-community-mcp`

Copy these worker files into that project, or use this folder as the new worker location.

Run:

`setup.ps1`

Then edit `worker-config.json` and paste the **Community Worker Token** shown by JK Social Hub's Community pull pairing status. Do not share that token publicly.

Run once:

`login.ps1`

Sign into the JustKalinga YouTube account in the dedicated browser profile, confirm the Community tab works, then return to PowerShell and press Enter.

Start the worker:

`start.ps1`

The worker will then poll the Hub continuously and process due Community jobs.

## Important

YouTube does not provide the official Data API with Community-post creation. This worker uses the normal authenticated YouTube web composer in a dedicated browser profile.
