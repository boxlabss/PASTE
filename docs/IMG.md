# Image and video uploads (/img)

Visitors upload images and videos at `/img/`. Settings are in
**Admin > Uploads**.

**Every upload is stored encrypted, and its link is the key.** Each file
gets a random 22-character name (about 131 bits), which goes in its links:
a page at `/img/<name>` and the file itself at `/img/<name>.<ext>`. The
server keeps only the encrypted file and an id derived from the name, never
the name, so nobody can open a stored file without its link: not someone
with the database or the storage folder, and not the site's admins. Direct
links, Markdown, BBCode and HTML embeds all work.

For extra privacy, **Encrypt in my browser** encrypts the file before it
leaves the browser, the same way as client-encrypted pastes. Then the key
goes after `#pass=` in the link, which browsers never send to the server, so
the server never sees the file or its key. The view page decrypts it, so
direct links and embeds do not work for those uploads.

IRC uploads through FILEHOST are stored the same way (see
`docs/FILEHOST.md`). Scripts and apps can upload with an API key through
`api.php?action=upload` (see `docs/API.md`); those uploads get the same
checks, limits and encryption.

## What is accepted

| Kind   | Types                    | Default limit |
|--------|--------------------------|---------------|
| Images | JPEG, PNG, GIF, WebP     | 10 MB         |
| Videos | MP4, WebM, MOV, AVI, MKV | 25 MB         |

The type is detected from the file contents, never from the name. The
server checks it, or the browser does before encrypting when "Encrypt in my
browser" is ticked. SVG, HTML and anything else are refused. AVI and MKV
are offered as downloads because browsers cannot play them.

## Options on the upload form

- **Title** (optional, up to 120 characters): shown on the view page, in
  "Your uploads" and in the share snippets instead of the file name, and
  used as the download name with the file's extension. With several files
  the title applies to all of them, and each can be given its own in the
  list. The API takes it as `title`.
- **Encrypt in my browser** (off by default; the default is set in Admin >
  Uploads). Each file gets a random key unless a passphrase of at least 12
  characters is entered, which is then used for every file in that batch.
- **Strip EXIF & metadata**: removes GPS location, camera details and other
  metadata from JPEG, PNG and WebP without re-encoding, and blanks the
  metadata boxes (including location) in MP4, MOV and M4V videos. A JPEG
  with a rotation tag is turned upright first so it does not display
  sideways. This happens in the browser, so the metadata never reaches the
  server, and the server strips it again.
- **Resize images before upload**: width and height in pixels. With the
  aspect lock on, images shrink to fit inside the box and are never
  enlarged; with it off, they are stretched to exactly that size. The JPEG
  quality slider sets the re-encoding quality. GIFs are never resized, so
  animation is kept.
- **Delete after**: never, 1 hour, 1 day, 1 week or 1 month.
- **Keep links in my account** (signed-in users, on by default): the
  account keeps a copy of each link, encrypted with the site's `SECRET`, so
  "Your uploads" shows names and previews on any device. Untick it for an
  upload the site should not be able to open. Uploads made before this
  option are added when their owner opens them, or visits /img in a browser
  that remembers their links. The API keeps links too unless sent `keep=0`.
- **Remember links in this browser** keeps the links and keys in this
  browser's storage so "Your uploads" can show names and previews.

## Albums

Two or more files uploaded together go into an album by default (**Put these
in an album**), so they share one page and one link. The title box then names
the album, and there is room for a description; each file can still have its
own title. Switch it off for a separate link to each file; the results then
offer **Make an album from these** to group them afterwards. Files encrypted
in the browser, and batches larger than an album holds, get separate links,
and the form says why. An album opens as one
page with every image and video, share snippets for all of them (direct
links, Markdown, BBCode), and for its maker: Add uploads, Edit (title and
description), move up and down, remove from the album, and Delete (the album
only, or the album and its uploads).

- Albums hold uploads stored with link keys, not ones encrypted in the
  browser. Like uploads, an album is stored encrypted and its link is the key.
- **Uploads per album** is set in Admin > Uploads separately for guests
  (default 5) and members (default 50). 0 turns albums off for that group.
- Signed-in users see their albums on any device (kept like their links);
  guests see the ones made in their session.
- Removing an upload from an album leaves it at its own link. An album's
  "Delete after" applies to the album; each upload keeps its own.
- Admins find an album the same way as an upload, by pasting its link into
  Admin > Uploads, and can delete it with or without its uploads.
- API: `POST /api.php?action=album` with `keys` (your uploads' keys or
  links), and optional `title`, `description` and `expiry`.

## How the encryption works

**Link keys (the default).** The server derives an AES-256 key from the
link name with HKDF-SHA256 and seals the file in 64 KB chunks with
AES-256-GCM, so a video can be seeked by decrypting only the chunks a range
request covers. The original file name and the title are sealed with the
same key. On disk
the file is `<id>.enc`, where the id is an HMAC of the name; the database
row holds the same id, the type, size, uploader and dates. When a link is
opened, the server derives the id and key from the name in it, decrypts the
file and streams it with `Cache-Control: private`, so shared caches and CDNs
do not keep a decrypted copy.

**Encrypt in my browser.** The browser encrypts three things with one key:
the file, a small preview (images, and a frame of videos the browser can
play) and the details (file name, title, type, size, dimensions). The format
matches encrypted pastes: salt (16 bytes) | IV (12 bytes) | AES-256-GCM
ciphertext, with the key derived from the passphrase by PBKDF2-SHA256 with
600000 iterations. The salt's first four bytes record the count (`C5 9E`,
then the count in thousands as two bytes); files and pastes encrypted before
that have a fully random salt and use 100000. The server stores `<slug>.bin`, `<slug>.thumb` and the
encrypted details, and refuses an "encrypted" upload that is really a plain
image or video.

Pages that carry keys (the upload form, view pages and client-encrypted
pastes) load no ads, analytics or "additional scripts" and send
`Referrer-Policy: no-referrer`; view pages also send a strict
Content-Security-Policy. Files themselves are served with a policy that
runs no scripts: `default-src 'none'; sandbox`, with `img-src 'self'` for
images and `media-src 'self'; sandbox allow-same-origin` for videos, so a
video opened from its direct link plays in the browser's own player.

Things to know:

- **Whoever has the link can view the upload**, and a lost link cannot be
  recovered. Admins with access to website uploads can still delete any of
  them.
- With link keys the server handles the plain file while storing and
  serving it, and the key arrives in every request. **Do not log upload
  links**: `docs/nginx.example.conf` turns access logging off for them, and
  for Apache see Server setup below. With "Encrypt in my browser" the server
  never sees the file or the key.
- Links and keys remembered in the browser stay in that browser only.
  "Forget links on this device" clears them. Signed-in users see names and
  previews on any device for uploads whose links their account keeps; the
  rest show only where the link is saved or opened.
- A link kept in an account is encrypted with a key derived from the site's
  `SECRET`, so whoever holds `config.php` and the database can open those
  uploads. Uploads with "Keep links in my account" unticked, guest uploads
  and browser-encrypted uploads stay unreadable to the site.
- Saved links sit in the site's browser storage, which any script running on
  the site's pages could read. The key-handling pages drop third-party
  scripts, but ads or analytics on other pages of the same site share that
  storage. If you run those, consider leaving "Remember links" unticked.

## Reports and finding uploads

Every view page has a **Report** button. The report includes the link (or,
for browser-encrypted uploads, the key if the reporter leaves it ticked), so
moderators can open the upload from Admin > Uploads > Reports, dismiss the
report or delete the upload. Each IP can send 10 reports an hour.

Admin > Uploads lists every stored file without previews, names or links.
To deal with a link reported some other way (by email, or from IRC), paste
it, or just the key, into **Find an upload by its link**: it shows the
upload's details with Open and Delete.

## Deleting

The delete button on a view page shows only to whoever uploaded the file:
the logged-in account that uploaded it, or the guest's own browser session.
Admins delete from Admin > Uploads. Deleting an account, by its owner or in
Admin > Users, deletes its uploads and albums too.

## Admin access

Admin > Uploads shows website uploads to admins with the **Website uploads**
section and IRC uploads to admins with **IRC uploads** (Admin Account >
Manage Admins). An admin with only one of them sees, finds and deletes only
that kind, and only website-upload admins see the /img settings
and reports. Uploaders' IP addresses show only with **Users and personal
data**.

## Missing files

Entries whose files no longer exist in `FILEHOST_DIR` are removed so they
stop being listed: by the upgrader and the cron, or with **Remove them** in
Admin > Uploads, which also marks them "File missing" in the list. The
upgrader and the cron leave them alone if the storage folder is completely
empty, since that usually means `FILEHOST_DIR` points at the wrong folder or
a disk is not mounted.

## Turning it on

The installer has an **Enable Image and Video Uploads** option, with the
storage folder, whether guests can upload, and whether "Encrypt in my
browser" starts ticked. Re-running the installer on an existing site offers
**Keep current setting**, which leaves uploads and the storage folder as
they are. After installing, everything is in **Admin > Uploads**.

## Who can upload

Logged-in users, and guests unless **Allow guests** is turned off in Admin >
Uploads. Guests pass the same captcha as the paste form; passing it covers
ten minutes of uploads. Banned IPs are refused. The hourly limit and the
daily allowance count per account, or per IP for guests; spam-exempt users
and admins skip them.

## Storage

Uploads share the FILEHOST store: rows in `filehost_files` with
`network = 'web'`, files in `FILEHOST_DIR`. The two kinds stay separate:
IRC uploads are only served at `/filehost/` and have their own hourly
limit; website uploads are only served at `/img/`. Admin > Uploads lists
both, with a Source filter. The table, its newer columns (`encrypted`,
`meta`, `thumb_size`, `owner_key`) and the `img_reports` and `img_albums`
tables are created on first use, or by the installer's upgrade. Keep `FILEHOST_DIR` outside the
web root; a relative path is taken from the site root. Admin > Uploads warns
when the folder is inside the web root or not writable.

## Server setup

- **Access logs**: keep upload links out of them, since a link is a key.
  - nginx: use the `map` and `access_log ... if=` lines from
    `docs/nginx.example.conf`.
  - Apache (in the server config, not `.htaccess`):

    ```
    SetEnvIf Request_URI "^/(img/|filehost)" paste_nolog
    CustomLog ${APACHE_LOG_DIR}/access.log combined env=!paste_nolog
    ```
  - A CDN or proxy in front of the site keeps its own logs of request URLs.
- **HTTPS**: needed for "Encrypt in my browser" (browsers only allow it on
  HTTPS pages and localhost), and so links are not readable on the network.
- **Apache**: the shipped `.htaccess` has the `/img` rewrite rules.
- **nginx**: copy the `location ^~ /img/` block from `docs/nginx.example.conf`.
- **PHP limits**: `upload_max_filesize` and `post_max_size` must allow the
  largest upload. `img/.user.ini` raises them to 26 MB / 28 MB under php-fpm;
  with mod_php set them in php.ini. The upload page shows the lower of the
  admin limit and the PHP limit.
- **Expired files**: hidden as soon as they expire, and removed a few at a
  time after each upload or all at once by `php filehost.php --cron`.
