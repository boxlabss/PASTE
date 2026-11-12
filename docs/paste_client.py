#!/usr/bin/env python3
"""
Command-line client for the Paste API.

Usage:
    # Set up environment variables (recommended)
    export PASTE_API_URL="https://paste.example.com/api.php"
    export PASTE_API_KEY="your_api_key_here"

    # Create a paste from stdin
    echo "Hello, World!" | python paste_client.py

    # Create a paste from a file
    python paste_client.py myfile.py

    # Create a paste with options
    python paste_client.py myfile.py --title "My Script" --syntax python --private

    # Get a paste
    python paste_client.py --get aBcDeFgH

    # Get raw content only
    python paste_client.py --get aBcDeFgH --raw

    # Get a password-protected paste
    python paste_client.py --get aBcDeFgH --password hunter2

    # Upload an image or video, printing its direct link
    python paste_client.py --upload photo.jpg --url-only
    python paste_client.py --upload clip.mp4 --title "Cat vs laser pointer"

    # Delete an upload by its key or link
    python paste_client.py --delete-upload nD3SrAgZive8g4Y4JcsFV8

    # Put uploads into an album (keys or links, in album order)
    python paste_client.py --album nD3SrAgZive8g4Y4JcsFV8 Qm7pXb2Ld9vRt4Kw8YzHs3 --title "Weekend walk"

    # List your pastes
    python paste_client.py --list

    # Search pastes
    python paste_client.py --search "hello world"

    # Delete a paste
    python paste_client.py --delete aBcDeFgH

    # Get user info
    python paste_client.py --user

    # Output as JSON
    python paste_client.py --list --json
"""

import argparse
import json
import mimetypes
import os
import sys
import uuid
from pathlib import Path
from typing import Optional, Dict, Any, List
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode

# Version
__version__ = "1.1"

# Default configuration
DEFAULT_API_URL = os.environ.get("PASTE_API_URL", "")
DEFAULT_API_KEY = os.environ.get("PASTE_API_KEY", "")

# File extension to syntax mapping
EXTENSION_MAP = {
    ".py": "python", ".js": "javascript", ".ts": "typescript",
    ".jsx": "javascript", ".tsx": "typescript", ".html": "html",
    ".htm": "html", ".css": "css", ".scss": "scss", ".sass": "sass",
    ".less": "less", ".json": "json", ".xml": "xml", ".yaml": "yaml",
    ".yml": "yaml", ".md": "markdown", ".markdown": "markdown",
    ".sh": "bash", ".bash": "bash", ".zsh": "bash", ".fish": "bash",
    ".ps1": "powershell", ".bat": "batch", ".cmd": "batch",
    ".c": "c", ".h": "c", ".cpp": "cpp", ".cc": "cpp", ".cxx": "cpp",
    ".hpp": "cpp", ".cs": "csharp", ".java": "java", ".kt": "kotlin",
    ".swift": "swift", ".go": "go", ".rs": "rust", ".rb": "ruby",
    ".php": "php", ".pl": "perl", ".pm": "perl", ".lua": "lua",
    ".r": "r", ".R": "r", ".sql": "sql", ".dockerfile": "dockerfile",
    ".toml": "toml", ".ini": "ini", ".cfg": "ini", ".conf": "ini",
    ".vim": "vim", ".tex": "latex", ".diff": "diff", ".patch": "diff",
    ".asm": "nasm", ".s": "nasm", ".m": "objectivec", ".dart": "dart",
    ".elm": "elm", ".erl": "erlang", ".ex": "elixir", ".exs": "elixir",
    ".hs": "haskell", ".ml": "ocaml", ".fs": "fsharp", ".scala": "scala",
    ".clj": "clojure", ".groovy": "groovy", ".gradle": "groovy",
    ".v": "verilog", ".vhd": "vhdl", ".proto": "protobuf",
    ".graphql": "graphql", ".gql": "graphql",
}


class PasteClient:
    """Client for interacting with the Paste API."""

    def __init__(self, api_url: str, api_key: str):
        self.api_url = api_url.rstrip("/")
        self.api_key = api_key

    def _request(self, method: str, action: str, params: Optional[Dict] = None,
                 data: Optional[Dict] = None, headers: Optional[Dict] = None,
                 body: Optional[bytes] = None, raw: bool = False) -> Any:
        """Make an API request. Returns the decoded JSON, or the text when raw."""
        url = f"{self.api_url}?action={action}"
        if params:
            url += "&" + urlencode(params)

        all_headers = {
            "Content-Type": "application/json",
            "User-Agent": f"paste-client/{__version__}"
        }
        if self.api_key:
            all_headers["X-API-Key"] = self.api_key
        all_headers.update(headers or {})

        if body is None and data:
            body = json.dumps(data).encode("utf-8")
        req = Request(url, data=body, headers=all_headers, method=method.upper())

        try:
            with urlopen(req, timeout=120) as response:
                text = response.read().decode("utf-8", "replace")
                return text if raw else json.loads(text)
        except HTTPError as e:
            try:
                return json.loads(e.read().decode("utf-8"))
            except:
                return {"success": False, "error": f"HTTP {e.code}: {e.reason}"}
        except URLError as e:
            return {"success": False, "error": f"Connection error: {e.reason}"}
        except json.JSONDecodeError:
            return {"success": False, "error": "Invalid JSON response"}
        except Exception as e:
            return {"success": False, "error": str(e)}

    def create_paste(self, content: str, title: Optional[str] = None, 
                     syntax: Optional[str] = None, visibility: str = "public",
                     expiry: Optional[str] = None, password: Optional[str] = None) -> Dict[str, Any]:
        """Create a new paste."""
        data = {"content": content}
        if password:
            data["password"] = password
        if title:
            data["title"] = title
        if syntax:
            data["syntax"] = syntax
        if visibility:
            data["visibility"] = visibility
        if expiry:
            data["expiry"] = expiry
        return self._request("POST", "paste", data=data)

    def get_paste(self, paste_id: str, password: Optional[str] = None, raw: bool = False) -> Any:
        """Get a paste by ID or slug. With raw, returns the text only (or an error dict)."""
        params = {"id": paste_id}
        if raw:
            params["format"] = "raw"
        headers = {"X-Paste-Password": password} if password else None
        return self._request("GET", "get", params=params, headers=headers, raw=raw)

    def update_paste(self, paste_id: str, content: Optional[str] = None,
                     title: Optional[str] = None, syntax: Optional[str] = None,
                     visibility: Optional[str] = None) -> Dict[str, Any]:
        """Update an existing paste."""
        data = {}
        if content is not None:
            data["content"] = content
        if title is not None:
            data["title"] = title
        if syntax is not None:
            data["syntax"] = syntax
        if visibility is not None:
            data["visibility"] = visibility
        return self._request("POST", "update", params={"id": paste_id}, data=data)

    def delete_paste(self, paste_id: str) -> Dict[str, Any]:
        """Delete a paste."""
        return self._request("DELETE", "delete", params={"id": paste_id})

    def list_pastes(self, limit: int = 20, offset: int = 0) -> Dict[str, Any]:
        """List your pastes."""
        return self._request("GET", "list", params={"limit": str(limit), "offset": str(offset)})

    def search_pastes(self, query: str, limit: int = 20) -> Dict[str, Any]:
        """Search pastes."""
        return self._request("GET", "search", params={"q": query, "limit": str(limit)})

    def get_user(self) -> Dict[str, Any]:
        """Get user information."""
        return self._request("GET", "user")

    def upload_file(self, path: str, strip_exif: bool = True,
                    expiry: Optional[str] = None, title: Optional[str] = None) -> Dict[str, Any]:
        """Upload an image or video to /img, with an optional title."""
        boundary = uuid.uuid4().hex
        filename = Path(path).name
        ctype = mimetypes.guess_type(filename)[0] or "application/octet-stream"
        parts = [("strip_exif", "1" if strip_exif else "0")]
        if expiry:
            parts.append(("expiry", expiry))
        if title:
            parts.append(("title", title))
        body = b""
        for name, value in parts:
            body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n"
                     f"{value}\r\n").encode("utf-8")
        with open(path, "rb") as f:
            body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; "
                     f"filename=\"{filename}\"\r\nContent-Type: {ctype}\r\n\r\n").encode("utf-8")
            body += f.read() + f"\r\n--{boundary}--\r\n".encode("utf-8")
        headers = {"Content-Type": f"multipart/form-data; boundary={boundary}"}
        return self._request("POST", "upload", headers=headers, body=body)

    def delete_upload(self, key: str) -> Dict[str, Any]:
        """Delete one of your uploads by its key or link."""
        return self._request("DELETE", "upload_delete", params={"key": key})

    def make_album(self, keys: List[str], title: Optional[str] = None, description: Optional[str] = None,
                   expiry: Optional[str] = None) -> Dict[str, Any]:
        """Put your uploads (keys or links, in order) into an album."""
        data: Dict[str, Any] = {"keys": keys}
        if title:
            data["title"] = title
        if description:
            data["description"] = description
        if expiry:
            data["expiry"] = expiry
        return self._request("POST", "album", data=data)


def detect_syntax(filename: str) -> Optional[str]:
    """Detect syntax highlighting from filename extension."""
    ext = Path(filename).suffix.lower()
    return EXTENSION_MAP.get(ext)


def format_output(data: Dict[str, Any], json_output: bool = False) -> str:
    """Format API response for display."""
    if json_output:
        return json.dumps(data, indent=2)
    
    if not data.get("success"):
        return f"Error: {data.get('error', 'Unknown error')}"
    
    if "paste" in data:
        paste = data["paste"]
        if isinstance(paste, dict):
            lines = []
            if "url" in paste:
                lines.append(f"URL: {paste['url']}")
            if "title" in paste:
                lines.append(f"Title: {paste['title']}")
            if "syntax" in paste:
                lines.append(f"Syntax: {paste['syntax']}")
            if "visibility" in paste:
                lines.append(f"Visibility: {paste['visibility']}")
            if "content" in paste:
                lines.append(f"\n--- Content ---\n{paste['content']}")
            return "\n".join(lines)
    
    if "pastes" in data:
        pastes = data["pastes"]
        if not pastes:
            return "No pastes found."
        lines = [f"Found {len(pastes)} paste(s):\n"]
        for p in pastes:
            slug = p.get("slug") or p.get("id", "?")
            title = p.get("title", "Untitled")[:40]
            syntax = p.get("syntax", "text")
            date = p.get("created_at", "")[:10]
            lines.append(f"  [{slug}] {title} ({syntax}) - {date}")
        return "\n".join(lines)
    
    if "upload" in data:
        up = data["upload"]
        title = f"Title: {up['title']}\n" if up.get("title") else ""
        return (f"{title}Link: {up.get('file', '')}\n"
                f"Page: {up.get('url', '')}\n"
                f"Key:  {up.get('key', '')} (keep it to delete the upload later)")

    if "album" in data:
        album = data["album"]
        title = f"Title: {album['title']}\n" if album.get("title") else ""
        return (f"{title}Album: {album.get('url', '')}\n"
                f"Uploads: {album.get('uploads', 0)}")

    if "user" in data:
        user = data["user"]
        return (f"Username: {user.get('username', 'N/A')}\n"
                f"Email: {user.get('email', 'N/A')}\n"
                f"Paste Count: {user.get('paste_count', 0)}\n"
                f"API Keys: {user.get('api_key_count', 0)}")
    
    if data.get("deleted"):
        return "Deleted."

    if "message" in data:
        return data["message"]
    
    return json.dumps(data, indent=2)


def main():
    parser = argparse.ArgumentParser(
        description="Paste API Client - Create, retrieve, and manage pastes from the command line.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  %(prog)s myfile.py                     Create paste from file
  echo "code" | %(prog)s                 Create paste from stdin
  %(prog)s --get aBcDeFgH                Get a paste
  %(prog)s --get aBcDeFgH --raw          Get raw content only
  %(prog)s --upload photo.jpg            Upload an image or video
  %(prog)s --delete-upload KEY           Delete an upload
  %(prog)s --album KEY KEY ...           Put uploads into an album
  %(prog)s --list                        List your pastes
  %(prog)s --search "query"              Search pastes
  %(prog)s --delete aBcDeFgH             Delete a paste
  %(prog)s --user                        Show account info

Environment Variables:
  PASTE_API_URL    API endpoint URL
  PASTE_API_KEY    Your API key (optional for new pastes and uploads on
                   sites that allow guests)
        """
    )
    
    parser.add_argument("file", nargs="?", help="File to paste (omit for stdin)")
    
    action_group = parser.add_mutually_exclusive_group()
    action_group.add_argument("--get", "-g", metavar="ID", help="Get paste by ID or slug")
    action_group.add_argument("--delete", "-d", metavar="ID", help="Delete paste by ID or slug")
    action_group.add_argument("--update", "-u", metavar="ID", help="Update paste by ID or slug")
    action_group.add_argument("--list", "-l", action="store_true", help="List your pastes")
    action_group.add_argument("--search", "-s", metavar="QUERY", help="Search pastes")
    action_group.add_argument("--user", action="store_true", help="Show user info")
    action_group.add_argument("--upload", metavar="FILE", help="Upload an image or video")
    action_group.add_argument("--delete-upload", metavar="KEY", help="Delete an upload by its key or link")
    action_group.add_argument("--album", metavar="KEY", nargs="+", help="Put uploads (keys or links) into an album")
    
    parser.add_argument("--title", "-t", help="Paste title, or the title of an --upload or --album")
    parser.add_argument("--description", help="Description of an --album")
    parser.add_argument("--syntax", "-S", help="Syntax highlighting")
    parser.add_argument("--public", action="store_const", const="public", dest="visibility")
    parser.add_argument("--unlisted", action="store_const", const="unlisted", dest="visibility")
    parser.add_argument("--private", action="store_const", const="private", dest="visibility")
    parser.add_argument("--expiry", "-e", type=str.upper,
                        choices=["N", "NEVER", "10M", "1H", "1D", "1W", "2W", "1M", "6M", "1Y", "SELF"],
                        help="Expiry (SELF = view once). Uploads take N, 1H, 1D, 1W or 1M")
    parser.add_argument("--password", "-p", help="Password for --get, or to protect a new paste")
    parser.add_argument("--keep-exif", action="store_true", help="Keep metadata on --upload")
    parser.add_argument("--limit", type=int, default=20, help="Limit results")
    parser.add_argument("--offset", type=int, default=0, help="Offset for pagination")
    parser.add_argument("--raw", "-r", action="store_true", help="Output raw content only")
    parser.add_argument("--json", "-j", action="store_true", help="Output as JSON")
    parser.add_argument("--url-only", action="store_true", help="Output only the URL")
    parser.add_argument("--api-url", default=DEFAULT_API_URL, help="API URL")
    parser.add_argument("--api-key", default=DEFAULT_API_KEY, help="API key")
    parser.add_argument("--version", "-V", action="version", version=f"%(prog)s {__version__}")
    
    args = parser.parse_args()
    
    if not args.api_url:
        print("Error: API URL not set. Use --api-url or set PASTE_API_URL", file=sys.stderr)
        sys.exit(1)
    
    guest_ok = not (args.get or args.delete or args.update or args.list or args.search or args.user or args.delete_upload or args.album)
    if not args.api_key and not guest_ok:
        print("Error: API key not set. Use --api-key or set PASTE_API_KEY", file=sys.stderr)
        sys.exit(1)
    
    client = PasteClient(args.api_url, args.api_key)
    result = None
    
    if args.get:
        result = client.get_paste(args.get, password=args.password, raw=args.raw)
        if args.raw:
            if isinstance(result, str):
                sys.stdout.write(result)
                return
            print(format_output(result, json_output=args.json), file=sys.stderr)
            sys.exit(1)
    elif args.upload:
        if not Path(args.upload).is_file():
            print(f"Error: File not found: {args.upload}", file=sys.stderr)
            sys.exit(1)
        result = client.upload_file(args.upload, strip_exif=not args.keep_exif, expiry=args.expiry,
                                    title=args.title)
        if args.url_only and result.get("success"):
            print(result["upload"].get("file", ""))
            return
    elif args.delete_upload:
        result = client.delete_upload(args.delete_upload)
    elif args.album:
        result = client.make_album(args.album, title=args.title, description=args.description, expiry=args.expiry)
        if args.url_only and result.get("success"):
            print(result["album"].get("url", ""))
            return
    elif args.delete:
        result = client.delete_paste(args.delete)
    elif args.update:
        content = None
        if args.file:
            with open(args.file, "r") as f:
                content = f.read()
        elif not sys.stdin.isatty():
            content = sys.stdin.read()
        result = client.update_paste(args.update, content=content, title=args.title,
                                     syntax=args.syntax, visibility=args.visibility)
    elif args.list:
        result = client.list_pastes(limit=args.limit, offset=args.offset)
    elif args.search:
        result = client.search_pastes(args.search, limit=args.limit)
    elif args.user:
        result = client.get_user()
    else:
        content = None
        title = args.title
        syntax = args.syntax
        
        if args.file:
            filepath = Path(args.file)
            if not filepath.exists():
                print(f"Error: File not found: {args.file}", file=sys.stderr)
                sys.exit(1)
            with open(filepath, "r") as f:
                content = f.read()
            if not title:
                title = filepath.name
            if not syntax:
                syntax = detect_syntax(args.file)
        elif not sys.stdin.isatty():
            content = sys.stdin.read()
        else:
            parser.print_help()
            sys.exit(0)
        
        if not content or not content.strip():
            print("Error: No content to paste.", file=sys.stderr)
            sys.exit(1)
        
        result = client.create_paste(content=content, title=title, syntax=syntax,
                                     visibility=args.visibility or "public", expiry=args.expiry,
                                     password=args.password)
        
        if args.url_only and result.get("success") and "paste" in result:
            print(result["paste"].get("url", ""))
            return
    
    if result:
        print(format_output(result, json_output=args.json))
        if not result.get("success"):
            sys.exit(1)


if __name__ == "__main__":
    main()
