"""Moodle course authoring MCP server for Claude.

Talks to the local_claudeapi plugin of a (local, development) Moodle 5.0 site.

Environment:
  MOODLE_URL       e.g. http://192.168.1.50/moodle   (required)
  MOODLE_TOKEN     web service token printed by local/claudeapi/cli/setup.php   (required)
  BACKUP_DIR       where .mbz files are written (default ~/MoodleBackups)
  AUTO_BACKUP      1 = back up an existing course before the first change in a session (default 1)
"""

from __future__ import annotations

import base64
import json
import logging
import mimetypes
import os
import re
import time
from pathlib import Path
from typing import Any

import httpx
from mcp.server.mcpserver import MCPServer
from mcp.server.mcpserver.exceptions import ToolError
from mcp.types import ToolAnnotations

MOODLE_URL = os.environ.get("MOODLE_URL", "").rstrip("/")
MOODLE_TOKEN = os.environ.get("MOODLE_TOKEN", "")
BACKUP_DIR = Path(os.path.expanduser(os.environ.get("BACKUP_DIR", "~/MoodleBackups")))
AUTO_BACKUP = os.environ.get("AUTO_BACKUP", "1") not in ("0", "false", "no", "")
CHUNK = 4 * 1024 * 1024  # bytes per upload/download chunk

INSTRUCTIONS = """\
Tools for building and editing courses in the user's LOCAL development Moodle 5.0 (admin rights).
Finished courses are exported with backup_course (.mbz) and moved to production by hand.

Working rules:
- Before editing an existing course, call get_course to see its real structure (cmids, section numbers, settings).
- Text fields (summary, intro, page content, chapter content) are HTML. Write clean semantic HTML
  (<h3>, <p>, <ul>, <ol>, <table>, <pre><code>). The course language is usually Finnish.
- Images: use add_image (local file path or SVG markup) – it uploads the file and inserts a <figure> with alt text.
  Always give meaningful alt text.
- Questions: import_questions takes GIFT (default) or Moodle XML. Use XML when questions need images.
- Before deleting anything or making bulk changes, list exactly what will change and get the user's OK.
- An automatic .mbz backup of an existing course is taken before the first change in each session.
- Activity fields go straight to the module's DB table (e.g. assign: duedate, allowsubmissionsfromdate, grade,
  assignsubmission_onlinetext_enabled, assignsubmission_file_enabled; quiz: timelimit (s), attempts, grademethod,
  questionsperpage; url: externalurl; forum: type). Timestamps are Unix seconds.
- Completion (course must have completion enabled, new courses do): cm fields completion=2 (automatic),
  completionview=1, completionpassgrade=1, completionexpected=<ts>; module fields e.g. assign.completionsubmit=1,
  quiz: cm.completionpassgrade=1 + completiongradeitemnumber=0.
- Anything the course tools do not cover (users, enrolments, groups, grades, forum posts, categories, calendar,
  cohorts, badges, ...): find a Moodle web service function with ws_functions, then call it with ws_call.
  Site/plugin settings: get_config / set_config.
- Availability JSON examples:
  after activity completed: {"op":"&","c":[{"type":"completion","cm":<cmid>,"e":1}],"showc":[true]}
  from a date: {"op":"&","c":[{"type":"date","d":">=","t":<unix ts>}],"showc":[true]}
"""

logging.getLogger("httpx").setLevel(logging.WARNING)

mcp = MCPServer("moodle", instructions=INSTRUCTIONS, version="0.3.0")

_backed_up: set[int] = set()
_created: set[int] = set()


class MoodleError(ToolError):
    pass


# --------------------------------------------------------------------------------------------------
# Low level
# --------------------------------------------------------------------------------------------------

def call(action: str, args: dict[str, Any] | None = None, timeout: float = 300) -> dict[str, Any]:
    if not MOODLE_URL or not MOODLE_TOKEN:
        raise MoodleError("MOODLE_URL and MOODLE_TOKEN must be set in the MCP server environment.")
    try:
        resp = httpx.post(
            f"{MOODLE_URL}/webservice/rest/server.php",
            data={
                "wstoken": MOODLE_TOKEN,
                "wsfunction": "local_claudeapi_execute",
                "moodlewsrestformat": "json",
                "action": action,
                "args": json.dumps(args or {}, ensure_ascii=False),
            },
            timeout=timeout,
        )
    except httpx.HTTPError as e:
        raise MoodleError(f"Cannot reach Moodle at {MOODLE_URL}: {e}") from e
    if resp.status_code != 200:
        raise MoodleError(f"HTTP {resp.status_code} from {MOODLE_URL}: {resp.text[:500]}")
    try:
        data = resp.json()
    except ValueError:
        raise MoodleError(f"Non-JSON response from Moodle: {resp.text[:1000]}")
    if isinstance(data, dict) and "exception" in data:
        msg = f"{data.get('errorcode')}: {data.get('message')}"
        if data.get("debuginfo"):
            msg += f"\n{data['debuginfo']}"
        raise MoodleError(msg)
    if isinstance(data, str):
        return json.loads(data)
    return data


def b64_file(path: str) -> tuple[str, str]:
    p = Path(os.path.expanduser(path))
    if not p.is_file():
        raise MoodleError(f"File not found: {p}")
    return p.name, base64.b64encode(p.read_bytes()).decode()


def file_payload(path: str, name: str | None = None) -> dict[str, Any]:
    """Send a local file to Moodle: inline base64 when small, otherwise in CHUNK-sized pieces
    (begin_upload + append_upload) so size is not limited by PHP post_max_size or memory.
    Returns {"filename", "content_b64"} or {"filename", "upload_id"}."""
    p = Path(os.path.expanduser(path))
    if not p.is_file():
        raise MoodleError(f"File not found: {p}")
    filename = name or p.name
    if p.stat().st_size <= CHUNK:
        return {"filename": filename, "content_b64": base64.b64encode(p.read_bytes()).decode()}
    up = call("begin_upload")["upload_id"]
    with open(p, "rb") as fh:
        while chunk := fh.read(CHUNK):
            call("append_upload", {"upload_id": up, "content_b64": base64.b64encode(chunk).decode()})
    return {"filename": filename, "upload_id": up}


def download_stored_file(file_id: int, target: Path) -> Path:
    """Download a stored Moodle file to `target` in CHUNK-sized pieces."""
    target.parent.mkdir(parents=True, exist_ok=True)
    offset = 0
    with open(target, "wb") as fh:
        while True:
            part = call("read_file", {"fileid": file_id, "offset": offset, "length": CHUNK})
            data = base64.b64decode(part["content_b64"])
            fh.write(data)
            offset += len(data)
            if part["eof"] or not data:
                break
    return target


def ensure_backup(course_id: int) -> str | None:
    """Back up an existing course once per session before the first modification."""
    if not AUTO_BACKUP or course_id in _backed_up or course_id in _created:
        return None
    path = download_backup(course_id, BACKUP_DIR / "auto")
    _backed_up.add(course_id)
    return str(path)


def course_of_cm(cm_id: int) -> int:
    return int(call("resolve_cm", {"cmid": cm_id})["courseid"])


def download_backup(course_id: int, out_dir: Path) -> Path:
    info = call("backup_course", {"courseid": course_id}, timeout=1800)
    out_dir.mkdir(parents=True, exist_ok=True)
    stamp = time.strftime("%Y%m%d-%H%M%S")
    target = out_dir / f"course{course_id}-{stamp}-{info['filename']}"
    return download_stored_file(int(info["fileid"]), target)


def with_backup(result: dict[str, Any], backup: str | None) -> dict[str, Any]:
    if backup:
        result["auto_backup"] = backup
    return result


def normalize_gift(text: str) -> str:
    """GIFT needs a blank line between questions; add one before every '::Title::' line that lacks it."""
    out: list[str] = []
    for line in text.replace("\r\n", "\n").split("\n"):
        if line.lstrip().startswith("::") and out and out[-1].strip() != "":
            out.append("")
        out.append(line)
    return "\n".join(out)


def clean_svg_name(name: str | None, default: str) -> str:
    name = name or default
    return re.sub(r"[^\w.\-]+", "_", name)


# --------------------------------------------------------------------------------------------------
# Read
# --------------------------------------------------------------------------------------------------

RO = ToolAnnotations(readOnlyHint=True)
DESTRUCTIVE = ToolAnnotations(destructiveHint=True)


@mcp.tool(annotations=RO)
def moodle_info() -> dict:
    """Check the connection: Moodle version, user and available plugin actions."""
    return call("ping")


@mcp.tool(annotations=RO)
def list_courses() -> dict:
    """List all courses (id, fullname, shortname, category)."""
    return call("list_courses")


@mcp.tool(annotations=RO)
def list_categories() -> dict:
    """List course categories."""
    return call("list_categories")


@mcp.tool(annotations=RO)
def get_course(course_id: int, include_content: bool = True) -> dict:
    """Full course structure: sections (number, name, summary), activities (cmid, type, settings, HTML content,
    files, availability, completion), book chapters and quiz questions. Use include_content=False for a quick outline."""
    return call("get_course_structure", {"courseid": course_id, "include_content": include_content})


# --------------------------------------------------------------------------------------------------
# Courses & sections
# --------------------------------------------------------------------------------------------------

@mcp.tool()
def create_course(fullname: str, shortname: str, category_id: int | None = None, summary: str = "",
                  num_sections: int = 0, extra: dict | None = None) -> dict:
    """Create a new course (topics format, completion tracking on). num_sections creates empty sections 1..n.
    extra: any other course fields, e.g. {"startdate": 1767225600, "format": "weeks", "lang": "fi"}."""
    args: dict[str, Any] = {"fullname": fullname, "shortname": shortname, "summary": summary,
                            "numsections": num_sections, **(extra or {})}
    if category_id:
        args["categoryid"] = category_id
    res = call("create_course", args)
    _created.add(int(res["courseid"]))
    return res


@mcp.tool()
def update_course(course_id: int, fields: dict) -> dict:
    """Update course fields, e.g. {"fullname": "...", "summary": "<p>..</p>", "visible": 0, "startdate": ts}."""
    b = ensure_backup(course_id)
    return with_backup(call("update_course", {"courseid": course_id, "fields": fields}), b)


@mcp.tool()
def add_section(course_id: int, name: str, summary: str = "", position: int = 0, visible: bool = True,
                availability: dict | None = None) -> dict:
    """Add a section (position 0 = at the end). Returns its section number."""
    b = ensure_backup(course_id)
    args: dict[str, Any] = {"courseid": course_id, "position": position, "name": name, "summary": summary,
                            "visible": int(visible)}
    if availability:
        args["availability"] = availability
    return with_backup(call("create_section", args), b)


@mcp.tool()
def update_section(course_id: int, section: int, fields: dict) -> dict:
    """Update a section by its number. fields: name, summary (HTML), visible (0/1), availability (JSON object)."""
    b = ensure_backup(course_id)
    return with_backup(call("update_section", {"courseid": course_id, "section": section, "fields": fields}), b)


@mcp.tool(annotations=DESTRUCTIVE)
def delete_section(course_id: int, section: int) -> dict:
    """Delete a section AND all activities in it. Confirm with the user first."""
    b = ensure_backup(course_id)
    return with_backup(call("delete_section", {"courseid": course_id, "section": section}), b)


@mcp.tool()
def move_section(course_id: int, section: int, destination: int) -> dict:
    """Move section number `section` to position `destination`."""
    b = ensure_backup(course_id)
    return with_backup(call("move_section", {"courseid": course_id, "section": section,
                                             "destination": destination}), b)


# --------------------------------------------------------------------------------------------------
# Activities
# --------------------------------------------------------------------------------------------------

@mcp.tool()
def add_activity(course_id: int, section: int, modname: str, fields: dict,
                 attachments: list[str] | None = None) -> dict:
    """Add any activity/resource to a section.
    modname: page, label, assign, quiz, book, url, resource, folder, forum, glossary, lesson, h5pactivity, ...
    fields: module + course-module fields. Common: name, intro (HTML), showdescription.
      page: content (HTML). label: intro is the visible text. url: externalurl.
      assign: duedate, allowsubmissionsfromdate, cutoffdate, grade, assignsubmission_onlinetext_enabled,
              assignsubmission_file_enabled, assignsubmission_file_maxfiles, completionsubmit.
      quiz: timelimit (seconds), attempts, grademethod, questionsperpage, preferredbehaviour ("deferredfeedback").
      forum: type ("general").  Completion: completion=2, completionview=1. visible=0 hides.
      availability: JSON object (see server instructions).
    attachments: local file paths for resource (first file shown) or folder."""
    b = ensure_backup(course_id)
    args: dict[str, Any] = {"courseid": course_id, "section": section, "modname": modname, "fields": fields}
    if attachments:
        args["draft_files"] = {"files": [file_payload(p) for p in attachments]}
    return with_backup(call("add_module", args), b)


@mcp.tool()
def update_activity(cm_id: int, settings: dict | None = None, cm: dict | None = None) -> dict:
    """Change an existing activity.
    settings: columns of the module table (name, intro, content, duedate, timelimit, ...).
    cm: course-module fields: visible, availability, completion, completionview, completionexpected,
        completionpassgrade, completiongradeitemnumber, indent, idnumber, showdescription, groupmode.
    Unknown keys are reported in 'ignored'."""
    b = ensure_backup(course_of_cm(cm_id))
    return with_backup(call("update_module", {"cmid": cm_id, "instance": settings or {}, "cm": cm or {}}), b)


@mcp.tool(annotations=DESTRUCTIVE)
def delete_activity(cm_id: int) -> dict:
    """Delete an activity. Confirm with the user first."""
    b = ensure_backup(course_of_cm(cm_id))
    return with_backup(call("delete_module", {"cmid": cm_id}), b)


@mcp.tool()
def move_activity(cm_id: int, section: int, before_cm_id: int | None = None) -> dict:
    """Move an activity to a section (number), optionally before another activity."""
    b = ensure_backup(course_of_cm(cm_id))
    args: dict[str, Any] = {"cmid": cm_id, "section": section}
    if before_cm_id:
        args["before_cmid"] = before_cm_id
    return with_backup(call("move_module", args), b)


@mcp.tool()
def duplicate_activity(cm_id: int) -> dict:
    """Duplicate an activity (copy appears right after it)."""
    b = ensure_backup(course_of_cm(cm_id))
    return with_backup(call("duplicate_module", {"cmid": cm_id}), b)


# --------------------------------------------------------------------------------------------------
# Images & files
# --------------------------------------------------------------------------------------------------

@mcp.tool()
def add_image(alt: str, path: str | None = None, svg: str | None = None, filename: str | None = None,
              cm_id: int | None = None, chapter_id: int | None = None, field: str | None = None,
              course_id: int | None = None, section: int | None = None, caption: str | None = None,
              width: int | None = None, position: str = "end", after_text: str | None = None) -> dict:
    """Upload an image and insert it as <figure> into HTML content.
    Source: `path` (local file on this computer) OR `svg` (SVG markup you write, e.g. a diagram).
    Target: cm_id (page content, or intro for other modules; field="intro" forces intro),
            cm_id + chapter_id (book chapter), or course_id + section (section summary).
    position: "end" | "start"; after_text: insert after the element containing this text."""
    if path:
        name, data = b64_file(path)
    elif svg:
        name = clean_svg_name(filename, f"kuva-{int(time.time())}.svg")
        if not name.lower().endswith(".svg"):
            name += ".svg"
        data = base64.b64encode(svg.encode("utf-8")).decode()
    else:
        raise MoodleError("Give path or svg")
    if filename and path:
        name = filename
    args: dict[str, Any] = {"filename": name, "content_b64": data, "alt": alt, "position": position}
    for k, v in (("caption", caption), ("width", width), ("after_text", after_text), ("field", field),
                 ("chapterid", chapter_id)):
        if v:
            args[k] = v
    if cm_id:
        args["cmid"] = cm_id
        b = ensure_backup(course_of_cm(cm_id))
    elif course_id is not None and section is not None:
        args.update({"courseid": course_id, "section": section})
        b = ensure_backup(course_id)
    else:
        raise MoodleError("Give cm_id, or course_id + section")
    return with_backup(call("add_image", args), b)


@mcp.tool()
def upload_file(path: str, filearea: str, cm_id: int | None = None, course_id: int | None = None,
                component: str | None = None, itemid: int = 0, filepath: str | None = None) -> dict:
    """Low-level: put a local file (any type, any size – large files go in chunks) into any file area.
    Returns @@PLUGINFILE@@ placeholder for use in HTML.
    E.g. page content: cm_id + filearea "content"; intro: filearea "intro"; book chapter: filearea "chapter" +
    itemid=chapterid; section summary: course_id + filearea "section" + itemid=section id;
    resource file: cm_id + filearea "content" + itemid 0; folder: cm_id + filearea "content" + filepath."""
    args: dict[str, Any] = {"filearea": filearea, "itemid": itemid, **file_payload(path)}
    if filepath:
        args["filepath"] = filepath
    if cm_id:
        args["cmid"] = cm_id
    elif course_id:
        args["courseid"] = course_id
    else:
        raise MoodleError("Give cm_id or course_id")
    if component:
        args["component"] = component
    return call("upload_file", args)


@mcp.tool(annotations=RO)
def list_files(cm_id: int | None = None, course_id: int | None = None) -> dict:
    """List stored files of an activity or course (fileid, area, name, size)."""
    return call("list_files", {"cmid": cm_id} if cm_id else {"courseid": course_id})


@mcp.tool()
def download_file(file_id: int, out_path: str | None = None) -> dict:
    """Download any stored Moodle file (file_id from list_files) to this computer, in chunks (any size).
    out_path: target file or directory (default BACKUP_DIR/files/). Existing files are not overwritten."""
    target = Path(os.path.expanduser(out_path)) if out_path else BACKUP_DIR / "files"
    if out_path is None or target.is_dir() or (out_path or "").endswith(("/", os.sep)):
        first = call("read_file", {"fileid": file_id, "offset": 0, "length": 0})
        target = target / first["filename"]
    if target.exists():
        stem, suffix = target.stem, target.suffix
        target = target.with_name(f"{stem}-{time.strftime('%Y%m%d-%H%M%S')}{suffix}")
    download_stored_file(file_id, target)
    return {"saved_to": str(target), "size_bytes": target.stat().st_size}


@mcp.tool(annotations=DESTRUCTIVE)
def delete_file(file_id: int) -> dict:
    """Delete a stored file by id (see list_files). Remember to remove its <img> from the HTML too."""
    return call("delete_file", {"fileid": file_id})


# --------------------------------------------------------------------------------------------------
# Book
# --------------------------------------------------------------------------------------------------

@mcp.tool()
def add_book_chapter(cm_id: int, title: str, content: str, subchapter: bool = False,
                     pagenum: int | None = None) -> dict:
    """Add a chapter (HTML content) to a book activity. Appends by default; pagenum inserts at a position."""
    b = ensure_backup(course_of_cm(cm_id))
    args: dict[str, Any] = {"cmid": cm_id, "title": title, "content": content, "subchapter": subchapter}
    if pagenum:
        args["pagenum"] = pagenum
    return with_backup(call("add_book_chapter", args), b)


@mcp.tool()
def update_book_chapter(cm_id: int, chapter_id: int, fields: dict) -> dict:
    """Update a book chapter: fields title, content (HTML), hidden, subchapter."""
    b = ensure_backup(course_of_cm(cm_id))
    return with_backup(call("update_book_chapter", {"cmid": cm_id, "chapterid": chapter_id, "fields": fields}), b)


@mcp.tool(annotations=DESTRUCTIVE)
def delete_book_chapter(cm_id: int, chapter_id: int) -> dict:
    """Delete a book chapter."""
    b = ensure_backup(course_of_cm(cm_id))
    return with_backup(call("delete_book_chapter", {"cmid": cm_id, "chapterid": chapter_id}), b)


# --------------------------------------------------------------------------------------------------
# Quiz
# --------------------------------------------------------------------------------------------------

@mcp.tool()
def import_questions(cm_id: int, questions: str | None = None, path: str | None = None, format: str = "gift",
                     add_to_quiz: bool = True, max_mark: float | None = None,
                     questions_per_page: int | None = None) -> dict:
    """Import questions into a quiz's own question bank and (by default) add them to the quiz.
    questions: GIFT text (format="gift") or Moodle XML (format="xml"); or path to such a file.
    GIFT: separate questions with a blank line; give every question a ::Title::.
    GIFT example:  ::K1:: Mikä komento listaa tiedostot? {=ls ~cd ~pwd}
    Use XML for questions with images (base64 <file> elements)."""
    if path:
        _, data = b64_file(path)
        args: dict[str, Any] = {"content_b64": data}
    elif questions:
        args = {"content": normalize_gift(questions) if format == "gift" else questions}
    else:
        raise MoodleError("Give questions or path")
    args.update({"cmid": cm_id, "format": format, "add_to_quiz": add_to_quiz})
    if max_mark is not None:
        args["maxmark"] = max_mark
    if questions_per_page:
        args["questions_per_page"] = questions_per_page
    b = ensure_backup(course_of_cm(cm_id))
    return with_backup(call("import_questions", args), b)


@mcp.tool(annotations=DESTRUCTIVE)
def remove_quiz_question(cm_id: int, slot: int) -> dict:
    """Remove the question in `slot` from a quiz (it stays in the question bank)."""
    b = ensure_backup(course_of_cm(cm_id))
    return with_backup(call("remove_quiz_slot", {"cmid": cm_id, "slot": slot}), b)


# --------------------------------------------------------------------------------------------------
# Backup / restore
# --------------------------------------------------------------------------------------------------

@mcp.tool()
def backup_course(course_id: int, out_dir: str | None = None) -> dict:
    """Export a course as .mbz (no user data) to this computer – for moving it to the production Moodle."""
    target = download_backup(course_id, Path(os.path.expanduser(out_dir)) if out_dir else BACKUP_DIR)
    return {"saved_to": str(target), "size_bytes": target.stat().st_size}


@mcp.tool()
def restore_course(mbz_path: str, category_id: int | None = None, fullname: str | None = None,
                   shortname: str | None = None) -> dict:
    """Restore a .mbz file from this computer as a NEW course (e.g. a production course to edit locally)."""
    p = Path(os.path.expanduser(mbz_path))
    if not p.is_file():
        raise MoodleError(f"File not found: {p}")
    up = call("begin_upload")["upload_id"]
    with open(p, "rb") as fh:
        while chunk := fh.read(CHUNK):
            call("append_upload", {"upload_id": up, "content_b64": base64.b64encode(chunk).decode()})
    args: dict[str, Any] = {"upload_id": up}
    for k, v in (("categoryid", category_id), ("fullname", fullname), ("shortname", shortname)):
        if v:
            args[k] = v
    res = call("restore_course", args, timeout=1800)
    _created.add(int(res["courseid"]))
    return res


# --------------------------------------------------------------------------------------------------
# Any Moodle web service function + site/plugin settings
# --------------------------------------------------------------------------------------------------

@mcp.tool(annotations=RO)
def ws_functions(search: str | None = None, component: str | None = None, name: str | None = None) -> dict:
    """Find Moodle's own web service functions (hundreds: users, enrolments, groups, grades, forums,
    calendar, cohorts, badges, competencies, categories, ...), or describe one function's parameters.
    search: part of the name, e.g. "enrol", "core_group_", "mod_forum_". name: full function name to describe.
    Typical flow: ws_functions(search=...) -> ws_functions(name=...) -> ws_call(...)."""
    args: dict[str, Any] = {}
    for k, v in (("search", search), ("component", component), ("name", name)):
        if v:
            args[k] = v
    return call("ws_functions", args)


@mcp.tool()
def ws_call(function: str, params: dict | None = None) -> dict:
    """Call any Moodle web service function as the site admin, e.g.
    core_user_create_users, enrol_manual_enrol_users, core_group_create_groups, core_course_create_categories,
    mod_forum_add_discussion, core_calendar_create_calendar_events, core_cohort_create_cohorts.
    params: exactly the function's parameters (check them with ws_functions(name=...)).
    Creating/deleting users, enrolments or data in bulk: tell the user what will happen and get an OK first."""
    return call("ws_call", {"function": function, "params": params or {}}, timeout=900)


@mcp.tool(annotations=RO)
def get_config(plugin: str | None = None, name: str | None = None, show_secrets: bool = False) -> dict:
    """Read site or plugin settings (what Site administration pages show).
    plugin: empty/"core" for core settings, or a component like "mod_assign", "theme_boost", "enrol_self".
    name: one setting; without it, all settings of the plugin (passwords masked unless show_secrets)."""
    args: dict[str, Any] = {"show_secrets": show_secrets}
    if plugin:
        args["plugin"] = plugin
    if name:
        args["name"] = name
    return call("get_config", args)


@mcp.tool()
def set_config(name: str, value: str | int | float | bool | None, plugin: str | None = None,
               purge_caches: bool = False) -> dict:
    """Change a site or plugin setting (logged to the config changes report). value=None unsets it.
    plugin: empty/"core" for core, or e.g. "mod_quiz". Check the current value with get_config first and
    tell the user what you change. purge_caches=True for settings that affect themes/language/caching."""
    args: dict[str, Any] = {"name": name, "value": value, "purge_caches": purge_caches}
    if plugin:
        args["plugin"] = plugin
    return call("set_config", args)


# --------------------------------------------------------------------------------------------------
# Escape hatch
# --------------------------------------------------------------------------------------------------

@mcp.tool()
def raw_action(action: str, args: dict | None = None) -> dict:
    """Call any local_claudeapi action directly (see moodle_info for the list). For cases the other tools miss."""
    return call(action, args or {})


def main() -> None:
    mcp.run("stdio")


if __name__ == "__main__":
    main()
