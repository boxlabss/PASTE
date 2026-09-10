# FILEHOST: uploads from IRC clients

Paste can act as the upload host an IRC network advertises with the IRCv3
`draft/FILEHOST` ISUPPORT token. IRC clients that support it (file attach,
drag and drop, paste an image) upload here and post the resulting link.

Authentication uses IRCv3 `draft/authtoken`: the IRC server hands the client a
short-lived, single-use, signed token (an ES256 JWT) bound to this site; the
client sends it as `Authorization: Bearer`. We verify the signature with the
network's public key. No IRC password ever reaches Paste, and Paste never has
to connect to the IRC server.

```
client ──TOKEN GENERATE FILEHOST──► ircd ──JWT──► client
client ──POST /filehost  Authorization: Bearer <jwt>──► paste
paste  ──201 Created  Location: https://paste.example.com/…──► client
```

## Requirements

PHP 8.1+ with `openssl` and `fileinfo`; MySQL/MariaDB; the site's `mod_rewrite`
rules (the shipped `.htaccess` or `docs/nginx.example.conf`). An IRC server that
implements `draft/authtoken` JWT services (Nefarious `ircv3.2-upgrade` does:
`Authtoken "FILEHOST" { url; key; }`).

## Setup

1. Apply the schema: `mysql -uuser -p dbname < upgrade/2.1-to-2.2-filehost.sql`
   (tables `filehost_jti`, `filehost_files`).
2. Create a site user that will own text uploads (they appear as unlisted
   pastes), for example `irc`, and put its name in `FILEHOST_MEMBER`.
3. Copy the `FILEHOST_*` block from `docs/config.example.php` into
   `config.php`:
   - `FILEHOST_URL`: the public URL of the endpoint, e.g.
     `https://paste.example.com/filehost`. The IRC server must advertise
     exactly this string (it is the token audience).
   - `FILEHOST_ISSUER`: the IRC network's name as the ircd puts it in the
     token (`NETWORK` feature / `iss` claim).
   - `FILEHOST_PUBKEY`: the network's public key, PEM. An IRC operator gets
     it with `/STATS authtoken` on the ircd:

     ```
     A :FILEHOST https://paste.example.com/filehost jwt :File upload
     A :  -----BEGIN PUBLIC KEY-----
     A :  MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE…
     A :  -----END PUBLIC KEY-----
     ```

   - `FILEHOST_DIR`: where binaries live. Keep it outside the web root; files
     are served through `filehost.php` with the stored `Content-Type`,
     `nosniff` and a sandboxing CSP.
   - `FILEHOST_MAX_BYTES`, `FILEHOST_ACCEPT`, `FILEHOST_EXPIRY`,
     `FILEHOST_RETAIN_DAYS`, `FILEHOST_PER_HOUR`: limits.
   - `FILEHOST_STRIP_METADATA` (default on): EXIF, XMP and text metadata are
     removed from JPEG, PNG and WebP uploads before storage, losslessly
     (segments and chunks are dropped, pixels are never re-encoded, animation
     survives). The response says `X-Filehost-Metadata: stripped` or `kept`.
   - `FILEHOST_ENABLED = true`.
4. Make sure the web server and PHP allow bodies of `FILEHOST_MAX_BYTES`
   (`client_max_body_size` / `LimitRequestBody`, PHP `post_max_size`) and that
   the `Authorization` header reaches PHP (the shipped `.htaccess` sets
   `HTTP_AUTHORIZATION`; nginx + php-fpm passes it by default).
5. Cron, optional: `php /path/to/filehost.php --cron` daily removes expired
   binaries. Each upload also sweeps a few.
6. On the IRC server:

   ```
   Authtoken "FILEHOST" {
     url = "https://paste.example.com/filehost";
     description = "File upload";
     key = "<base64url 32-byte P-256 scalar, same on every server>";
   };
   ```

   The ircd then advertises `draft/FILEHOST=https://paste.example.com/filehost`
   (and `soju.im/FILEHOST` for goguma).

Check with `curl -i -X OPTIONS https://paste.example.com/filehost`: you should
see `Allow: OPTIONS, POST` and `Accept-Post`.

## Endpoint

| Request | Response |
|---|---|
| `OPTIONS /filehost` | 204, `Allow`, `Accept-Post`, CORS headers (browser clients need them) |
| `POST /filehost` with `Authorization: Bearer <jwt>`, `Content-Type`, optional `Content-Disposition: inline; filename="…"` | 201, `Location: <url>`, JSON `{url,type,size,expires}` |
| `GET` / `HEAD <Location>` | the file (`Range` supported) or, for text, the paste's raw view |

Errors are JSON `{success:false,error,message}`: 401 `unauthorized` (no or
Basic credentials; `WWW-Authenticate: Bearer`), 401 `invalid_token` (bad
signature, wrong issuer or audience, expired), 401 `replayed_token`, 403
`banned` (site IP bans apply), 413 `too_large`, 415 `unsupported_type` /
`type_mismatch` (declared type not in `Accept-Post`, or the bytes do not look
like it; SVG, HTML and TIFF are never accepted), 429 `rate_limited`.

Arbitrary binaries are not accepted: the default `FILEHOST_ACCEPT` is images,
video and text; an upload without a `Content-Type`, or with `application/*`,
gets 415. Images and video must sniff (`finfo`) as their declared major type;
text must be UTF-8; any other type an operator adds to `FILEHOST_ACCEPT` must
sniff as exactly that type.

Text uploads (`text/*`, JSON, XML; must be UTF-8) become unlisted pastes owned
by `FILEHOST_MEMBER`, titled after the uploaded filename, syntax guessed from
the extension; `Location` is the raw URL. Everything else is written to
`FILEHOST_DIR/<slug>.<ext>` where the extension comes from a fixed MIME table,
never from the client.

The token's `sub` (IRC account) and `iss` (network) are recorded on every
`filehost_files` row, so abuse reports can be taken to the network's operators,
and `DELETE` of the row plus the file is the takedown.

## What the token contains

`{"alg":"ES256","typ":"JWT"}` . `{"iss":"<network>","aud":"<FILEHOST_URL>",
"sub":"<account>","name":"<nick>","scope":"<#channel>","iat":…,"exp":…,
"jti":"<48 hex>"}` . signature. Verified in `includes/filehost_jwt.php` with
`openssl_verify`; `jti` is stored until an hour past `exp` to refuse replays.

## Notes for maintainers

- Metadata stripping is lossless and format-aware (`includes/filehost_exif.php`);
  formats it does not know (AVIF, HEIC, video) are stored as they are.
- `Bearer` carries an authtoken JWT rather than an OAUTHBEARER token; the
  FILEHOST draft predates authtoken. `Basic` is refused deliberately.
