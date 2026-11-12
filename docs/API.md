# Paste API Documentation

## Overview

The Paste API allows you to programmatically create, retrieve, list, search, and delete pastes, and to upload images and videos to /img. 

**Base URL:** `https://your-paste-site.com/api.php`

## Authentication

All API requests (except `languages`) require authentication via an API key.

### Getting an API Key

1. Log in to your Paste account
2. Go to your **Profile** page
3. Scroll down to the **API Keys** section
4. Click **Create API Key**
5. Copy your key immediately - it won't be shown again!

### Using Your API Key

Include your API key in requests using one of these methods:

**HTTP Header (recommended):**
```
X-API-Key: your_api_key_here
```

or

```
Authorization: Bearer your_api_key_here
```

**Query Parameter:**
```
?api_key=your_api_key_here
```

**POST Body** (form field or JSON):
```json
{
  "api_key": "your_api_key_here"
}
```

Prefer a header: query strings end up in server and proxy logs.

### Key Scopes

When you create a key you choose what it can do:

| Scope | Allows |
|-------|--------|
| `read` | `get`, `list`, `search` and `user` |
| `paste` | `paste`, `update` and `delete` |
| `upload` | `upload`, `upload_delete` and `album` |

A request outside the key's scopes gets `403`. For a script that only uploads
images, give its key only `upload`, so it cannot read or delete your pastes.

Keys are stored hashed, so the site cannot show a key again. If you lose one,
regenerate it on your Profile page; that keeps its name and scopes.

### Paste IDs

Endpoints that take an `id` accept the paste's slug or its number. When the
site blocks numeric URLs, numbers only find your own pastes.

---

## Endpoints

### Create a Paste

Create a new paste.

**Endpoint:** `POST /api.php?action=paste`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `content` | string | Yes | The paste content, as UTF-8 text |
| `title` | string | No | Paste title (default: "Untitled") |
| `syntax` | string | No | Syntax highlighting language (default: "text") |
| `visibility` | string | No | `public` (default), `unlisted` or `private` (or `0`, `1`, `2`) |
| `expiry` | string | No | See Expiry Codes below (default: `N`, never) |
| `password` | string | No | Password protect the paste |

Send JSON or form fields. Text that is not valid UTF-8, an unknown
`visibility` or an unknown `expiry` is refused with `400`.

**Expiry Codes:**

| Code | Expires after | Also accepted |
|------|---------------|---------------|
| `N` | Never | `NULL`, `never` |
| `10M` | 10 minutes | `T` |
| `1H` | 1 hour | `H` |
| `1D` | 1 day | `D` |
| `1W` | 1 week | `W` |
| `2W` | 2 weeks | |
| `1M` | 1 month | `M` |
| `6M` | 6 months | `B` |
| `1Y` | 1 year | `Y` |
| `SELF` | View once: deleted after its first reader, as on the website | |

Codes are not case-sensitive.

**Example Request (cURL):**
```bash
curl -X POST "https://paste.example.com/api.php?action=paste" \
  -H "X-API-Key: your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "content": "print(\"Hello, World!\")",
    "title": "My Python Script",
    "syntax": "python",
    "visibility": "public"
  }'
```

**Example Request (Python):**
```python
import requests

response = requests.post(
    "https://paste.example.com/api.php?action=paste",
    headers={"X-API-Key": "your_api_key"},
    json={
        "content": "print('Hello, World!')",
        "title": "My Python Script",
        "syntax": "python",
        "visibility": "public"
    }
)
print(response.json())
```

**Success Response (201):**
```json
{
  "success": true,
  "paste": {
    "id": 12345,
    "slug": "aBcDeFgH",
    "url": "https://paste.example.com/aBcDeFgH",
    "raw_url": "https://paste.example.com/raw/aBcDeFgH",
    "title": "My Python Script",
    "syntax": "python",
    "visibility": "public",
    "expiry": "1D",
    "expires_at": "2025-02-02T12:00:00+00:00",
    "view_once": false,
    "password_protected": false,
    "encrypted": false,
    "encrypted_at_rest": true,
    "created_at": "2025-02-01 12:00:00"
  }
}
```

A paste the spam filter holds for review answers `202` with
`"status": "pending_review"` instead, and `"url"`: where the paste will be
once a moderator approves it (null when the site uses numeric links).

---

### Get a Paste

Retrieve a paste by ID or slug.

**Endpoint:** `GET /api.php?action=get&id={id}`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | string | Yes | Paste ID or slug |
| `password` | string | No | For password-protected pastes (see below) |
| `format` | string | No | `raw` returns the text only, as `text/plain` |

Send a paste password in the `X-Paste-Password` header or the request body
(`password`). A `password` query parameter also works, but ends up in logs.
Each key gets 10 wrong passwords per 10 minutes; after that `get` answers
`429` for password-protected pastes until the time is up.

Reading a view-once paste counts as its view, so it is deleted after the
first reader, as on the website; the reply then has `"deleted": true`.

`encrypted` is true when `content` is ciphertext: the paste was encrypted in
the browser and only its link can decrypt it (`format=raw` refuses those
with `422`). `encrypted_at_rest` is true for pastes the server stores
encrypted and decrypts for you.

**Example Request:**
```bash
curl "https://paste.example.com/api.php?action=get&id=aBcDeFgH" \
  -H "X-API-Key: your_api_key"

# Text only, from a password-protected paste
curl "https://paste.example.com/api.php?action=get&id=aBcDeFgH&format=raw" \
  -H "X-API-Key: your_api_key" \
  -H "X-Paste-Password: hunter2"
```

**Success Response (200):**
```json
{
  "success": true,
  "paste": {
    "id": 12345,
    "slug": "aBcDeFgH",
    "url": "https://paste.example.com/aBcDeFgH",
    "title": "My Python Script",
    "content": "print('Hello, World!')",
    "syntax": "python",
    "visibility": "public",
    "expiry": "NULL",
    "expires_at": null,
    "view_once": false,
    "deleted": false,
    "password_protected": false,
    "encrypted": false,
    "encrypted_at_rest": true,
    "author": "username",
    "created_at": "2025-02-01 12:00:00",
    "views": 42
  }
}
```

`expiry` is the stored value (`NULL`, `SELF` or a Unix time); `expires_at` is
the same as an ISO 8601 time, or null.

---

### Update a Paste

Change one of your pastes. Send only the fields to change.

**Endpoint:** `POST /api.php?action=update&id={id}` (`PUT` and `PATCH` also work)

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | string | Yes | Paste ID or slug |
| `content` | string | No | New content (not for pastes encrypted in the browser) |
| `title` | string | No | New title |
| `syntax` | string | No | New syntax highlighting language |
| `visibility` | string | No | `public`, `unlisted` or `private` |

**Example Request:**
```bash
curl -X PUT "https://paste.example.com/api.php?action=update&id=aBcDeFgH" \
  -H "X-API-Key: your_api_key" \
  -H "Content-Type: application/json" \
  -d '{"title": "Renamed", "visibility": "unlisted"}'
```

---

### List Your Pastes

Get a paginated list of your pastes.

**Endpoint:** `GET /api.php?action=list`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `page` | int | No | Page number (default: 1) |
| `offset` | int | No | Items to skip, instead of `page` |
| `limit` | int | No | Items per page, max 100 (default: 20) |

**Example Request:**
```bash
curl "https://paste.example.com/api.php?action=list&page=1&limit=10" \
  -H "X-API-Key: your_api_key"
```

**Success Response (200):**
```json
{
  "success": true,
  "pastes": [
    {
      "id": 12345,
      "slug": "aBcDeFgH",
      "url": "https://paste.example.com/aBcDeFgH",
      "title": "My Python Script",
      "syntax": "python",
      "visibility": "public",
      "expiry": "NULL",
      "expires_at": null,
      "expired": false,
      "view_once": false,
      "password_protected": false,
      "encrypted": false,
      "encrypted_at_rest": true,
      "created_at": "2025-02-01 12:00:00"
    }
  ],
  "pagination": {
    "page": 1,
    "limit": 10,
    "offset": 0,
    "total": 42,
    "pages": 5
  }
}
```

---

### Delete a Paste

Delete one of your pastes.

**Endpoint:** `DELETE /api.php?action=delete&id={id}`

Or: `POST /api.php?action=delete&id={id}`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | string | Yes | Paste ID or slug |

**Example Request:**
```bash
curl -X DELETE "https://paste.example.com/api.php?action=delete&id=aBcDeFgH" \
  -H "X-API-Key: your_api_key"
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Paste deleted"
}
```

---

### Search Pastes

Search public pastes and your own pastes.

**Endpoint:** `GET /api.php?action=search&q={query}`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `q` | string | Yes | Search query (min 2 characters) |
| `page` | int | No | Page number (default: 1) |
| `offset` | int | No | Items to skip, instead of `page` |
| `limit` | int | No | Items per page, max 50 (default: 20) |

Searches titles, and the content of pastes not stored encrypted. Results are
your own pastes plus public pastes the archive lists (no password, not view
once). Expired pastes are left out.

**Example Request:**
```bash
curl "https://paste.example.com/api.php?action=search&q=python" \
  -H "X-API-Key: your_api_key"
```

**Success Response (200):**
```json
{
  "success": true,
  "query": "python",
  "pastes": [
    {
      "id": 12345,
      "slug": "aBcDeFgH",
      "url": "https://paste.example.com/aBcDeFgH",
      "title": "My Python Script",
      "syntax": "python",
      "author": "username",
      "created_at": "2025-02-01 12:00:00"
    }
  ],
  "pagination": {
    "page": 1,
    "limit": 20,
    "offset": 0,
    "total": 5,
    "pages": 1
  }
}
```

---

### Your Account

**Endpoint:** `GET /api.php?action=user`

**Success Response (200):**
```json
{
  "success": true,
  "user": {
    "username": "johndoe",
    "email": "john@example.com",
    "paste_count": 42,
    "api_key_count": 2,
    "key_scopes": ["read", "paste", "upload"]
  }
}
```

---

### List Languages

Get available syntax highlighting languages. **No authentication required.**

**Endpoint:** `GET /api.php?action=languages`

**Example Request:**
```bash
curl "https://paste.example.com/api.php?action=languages"
```

**Success Response (200):**
```json
{
  "success": true,
  "languages": {
    "text": "Plain Text",
    "python": "Python",
    "javascript": "JavaScript",
    "php": "PHP",
    ...
  }
}
```

---

### Upload an Image or Video

Upload a file to /img. It is stored encrypted with its link as the key, like
uploads from the website, and the same type checks, size limits, hourly limit
and daily allowance apply. JPEG, PNG, GIF and WebP images and MP4, WebM, MOV,
AVI and MKV videos are accepted.

**Endpoint:** `POST /api.php?action=upload` (multipart/form-data)

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file` | file | Yes | The image or video |
| `title` | string | No | Shown on the view page instead of the file name, and used as the download name (up to 120 characters) |
| `strip_exif` | string | No | `1` (default) removes EXIF, GPS location and other metadata; `0` keeps it |
| `expiry` | string | No | `N` (never, default), `1H`, `1D`, `1W` or `1M` (the aliases above work too) |
| `keep` | string | No | `1` (default) keeps a copy of the link in your account, so the upload is listed with its name and preview on the website; `0` does not |

**Example Request:**
```bash
curl -X POST "https://paste.example.com/api.php?action=upload" \
  -H "X-API-Key: your_api_key" \
  -F "file=@photo.jpg" \
  -F "title=Sunset over the bay"
```

**Success Response (201):**
```json
{
  "success": true,
  "upload": {
    "key": "nD3SrAgZive8g4Y4JcsFV8",
    "url": "https://paste.example.com/img/nD3SrAgZive8g4Y4JcsFV8",
    "file": "https://paste.example.com/img/nD3SrAgZive8g4Y4JcsFV8.jpg",
    "kind": "image",
    "mime": "image/jpeg",
    "ext": "jpg",
    "name": "photo.jpg",
    "title": "Sunset over the bay",
    "size": 183204,
    "expiry": "N",
    "expires": null
  }
}
```

`file` is the direct link and `url` the view page. `name` is the original file
name and `title` is null when none was sent. The key is part of both
links and the server does not keep it, so save the reply if you may want to
delete the upload later.

**Errors:** `400` an expiry uploads do not support, `413` larger than the
server accepts, `422` unsupported type or over the size limit, `429` hourly
limit or daily allowance reached, `503` uploads are turned off.

---

### Delete an Upload

Delete an upload made by your account.

**Endpoint:** `DELETE /api.php?action=upload_delete&key={key}`

Or: `POST /api.php?action=upload_delete&key={key}`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | string | Yes | The key, or either link, from the upload reply |

**Example Request:**
```bash
curl -X DELETE "https://paste.example.com/api.php?action=upload_delete&key=nD3SrAgZive8g4Y4JcsFV8" \
  -H "X-API-Key: your_api_key"
```

**Success Response (200):**
```json
{
  "success": true,
  "deleted": true
}
```

---

### Make an Album

Put uploads made by your account into an album with one link. Needs an API
key with the `upload` scope.

**Endpoint:** `POST /api.php?action=album` (form fields or JSON)

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `keys` | list or string | Yes | Your uploads' keys or links, in album order (a JSON list, `keys[]` fields, or comma-separated) |
| `title` | string | No | Album title (up to 120 characters) |
| `description` | string | No | Shown under the title (up to 1000 characters) |
| `expiry` | string | No | `N` (never, default), `1H`, `1D`, `1W` or `1M` |
| `keep` | string | No | `1` (default) lists the album in your account on the website; `0` does not |

The number of uploads is limited by **Uploads per album, members** in Admin > Uploads (50 by default).

**Example Request:**
```bash
curl -X POST "https://paste.example.com/api.php?action=album" \
  -H "X-API-Key: your_api_key" \
  -F "keys=nD3SrAgZive8g4Y4JcsFV8,Qm7pXb2Ld9vRt4Kw8YzHs3" \
  -F "title=Weekend walk"
```

**Success Response (201):**
```json
{
  "success": true,
  "album": {
    "key": "Hk4Tz9WbN2qLs7Vd3XpRm8",
    "url": "https://paste.example.com/img/Hk4Tz9WbN2qLs7Vd3XpRm8",
    "uploads": 2,
    "title": "Weekend walk",
    "expiry": "N"
  }
}
```

**Errors:** `403` albums are turned off, `404` a key is not one of your uploads, `422` more uploads than an album may hold.

---

## Error Responses

All errors follow this format:

```json
{
  "success": false,
  "error": "Error message here"
}
```

**Common HTTP Status Codes:**

| Code | Description |
|------|-------------|
| 400 | Bad Request - Invalid parameters |
| 401 | Unauthorized - Invalid or missing API key, or a missing or wrong paste password |
| 403 | Forbidden - Access denied, or the key lacks the scope |
| 404 | Not Found - Paste doesn't exist |
| 405 | Method Not Allowed - Wrong HTTP method |
| 413 | Payload Too Large - Content or file too large |
| 422 | Unprocessable - Rejected as spam, or a file type that is not supported |
| 429 | Too Many Requests - Rate limit or upload limit reached |
| 503 | Service Unavailable - API or uploads are disabled |

---

## Rate Limits

Each key can make 60 requests per minute (the site admin can change this in
Admin > Configuration > API). Every reply says where you are:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 57
X-RateLimit-Reset: 1738411260
```

`X-RateLimit-Reset` is the Unix time the minute ends. Over the limit the API
answers `429` with a `Retry-After` header in seconds. Uploads also count
against the site's hourly upload limit and daily allowance.

---

## Code Examples

### Python - Create and Retrieve Paste

```python
import requests

API_URL = "https://paste.example.com/api.php"
API_KEY = "your_api_key_here"

headers = {"X-API-Key": API_KEY}

# Create a paste
response = requests.post(
    f"{API_URL}?action=paste",
    headers=headers,
    json={
        "content": "Hello from Python!",
        "title": "API Test",
        "syntax": "text"
    }
)
result = response.json()
print(f"Created paste: {result['paste']['url']}")

# Get the paste back
paste_id = result['paste']['slug']
response = requests.get(
    f"{API_URL}?action=get&id={paste_id}",
    headers=headers
)
paste = response.json()['paste']
print(f"Content: {paste['content']}")
```

### Bash - Quick Paste Script

```bash
#!/bin/bash
# paste.sh - Quick paste from command line

API_KEY="your_api_key_here"
API_URL="https://paste.example.com/api.php"

# Read from stdin or file
if [ -z "$1" ]; then
    CONTENT=$(cat)
else
    CONTENT=$(cat "$1")
fi

# Post to API
RESPONSE=$(curl -s -X POST "$API_URL?action=paste" \
    -H "X-API-Key: $API_KEY" \
    -H "Content-Type: application/json" \
    -d "{\"content\": $(echo "$CONTENT" | jq -Rs .)}")

# Extract URL
echo "$RESPONSE" | jq -r '.paste.url'
```

Usage: `cat myfile.py | ./paste.sh` or `./paste.sh myfile.py`

### JavaScript/Node.js

```javascript
const API_URL = 'https://paste.example.com/api.php';
const API_KEY = 'your_api_key_here';

async function createPaste(content, title = 'Untitled', syntax = 'text') {
    const response = await fetch(`${API_URL}?action=paste`, {
        method: 'POST',
        headers: {
            'X-API-Key': API_KEY,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ content, title, syntax })
    });
    return response.json();
}

// Usage
createPaste('console.log("Hello!");', 'JS Test', 'javascript')
    .then(result => console.log(result.paste.url));
```

---

## Support

If you encounter issues with the API, please check:

1. Your API key is correct and active
2. The API is enabled (admin setting)
3. You're using the correct HTTP method
4. Required parameters are provided

For further assistance, contact the site administrator.
