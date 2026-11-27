# Phase 0: machine link result

Date: 2026-11-23
Status: hosting side proven; test with the real MB20 still to do.

## Outcome

**Outcome 1 from the design (section 6): the MB20 can push directly to the HRM over plain HTTP.** No forwarder PC is needed, provided the machine's firmware has the Cloud Server (ADMS) setting.

## What was verified on the live site (hrm.luminocrm.online)

Tested from outside with `curl` against release `4e3b3d1-20261123T113949Z`:

| Check                                                                         | Result                                                  |
| ----------------------------------------------------------------------------- | ------------------------------------------------------- |
| `GET http://…/iclock/cdata?SN=<registered>&options=all`                       | 200, `text/plain`, option list, no redirect, no cookies |
| `POST http://…/iclock/cdata?SN=<registered>&table=ATTLOG` with one punch line | 200, body `OK: 1`                                       |
| `GET http://…/iclock/getrequest?SN=<unknown>`                                 | 403                                                     |
| `GET http://…/login`                                                          | 301 to HTTPS                                            |
| `GET https://…/login`                                                         | 200                                                     |
| `/.env`, `/deploy/hostinger/*`                                                | 403                                                     |
| `/register`                                                                   | 404                                                     |

## How it was made to work

- Hostinger redirects every new site from HTTP to HTTPS at hosting level. That redirect is **switched off** for this site (hPanel setting, changed through the API on 2026-11-23).
- The root `.htaccess` does the HTTPS redirect itself for every path except `/iclock`.
- Hostinger's web server (LiteSpeed, behind Hostinger's CDN) honours those rules.

If someone turns "Force HTTPS" back on in hPanel for this site, the machine will stop delivering punches. Leave it off.

## Server facts found

- Web and CLI PHP: 8.4.28. `proc_open` is available to CLI PHP (cron), so Laravel's scheduler can run commands normally. It is disabled for web requests.
- No SSH and no remote command or file-write API. Deploys are an upload plus a once-a-minute cron job (`docs/deploy.md`).
- Requests arrive through Hostinger's CDN (`Server: hcdn`), so the client IP the app sees may be the CDN's. Restricting `/iclock` to the office IP (an optional extra in the design) needs trusted-proxy handling first.

## Still to do: test with the real machine

1. Read the MB20's serial number and register it on the server (`HRM_DEVICE_SERIALS`).
2. Note the machine's current Cloud Server settings (they point at BioTime).
3. Set server address `hrm.luminocrm.online`, Enable Domain Name on, port 80, proxy off.
4. Punch once. Confirm a handshake row and an ATTLOG row appear in `device_logs`.
5. Put the BioTime settings back. BioTime stays in use until attendance is built (phase 3).

To record here after the test: firmware version, the exact handshake query string and ATTLOG line the machine sent, and any change needed to the reply format.
