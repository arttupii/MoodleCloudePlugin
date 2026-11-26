import asyncio, json, os, sys
from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import stdio_client

"""End-to-end test of the MCP server against a DEVELOPMENT Moodle.

    MOODLE_URL=http://moodle.local MOODLE_TOKEN=xxx mcp-server/.venv/bin/python tests/e2e_test.py
"""
import tempfile, zlib, struct
from pathlib import Path

HERE = Path(__file__).resolve().parent
SRV = HERE.parent / "mcp-server"
TMP = Path(tempfile.mkdtemp(prefix="moodle-mcp-test-"))
params = StdioServerParameters(command=sys.executable, args=[str(SRV / "moodle_mcp.py")],
    env={"MOODLE_URL": os.environ["MOODLE_URL"], "MOODLE_TOKEN": os.environ["MOODLE_TOKEN"],
         "BACKUP_DIR": str(TMP / "backups"), "PATH": os.environ.get("PATH", "")})


def png(w, h, rgb):
    raw = b"".join(b"\x00" + bytes(rgb) * w for _ in range(h))
    def ch(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return (b"\x89PNG\r\n\x1a\n" + ch(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + ch(b"IDAT", zlib.compress(raw)) + ch(b"IEND", b""))


IMG = TMP / "kytkentä.png"
IMG.write_bytes(png(120, 80, (30, 120, 200)))
PDF = TMP / "ohje.pdf"
PDF.write_bytes(b"%PDF-1.4\n%test\n")

GIFT = """::K1:: Mikä komento listaa hakemiston tiedostot? {=ls ~cd ~pwd ~mkdir}
::K2:: Komento pwd näyttää nykyisen hakemiston polun. {T}
::K3:: Millä komennolla vaihdetaan hakemistoa? {=cd =chdir}
"""
SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="120"><rect x="10" y="10" width="120" height="60" fill="#cde" stroke="#036"/><text x="20" y="45">Kytkin</text><line x1="130" y1="40" x2="250" y2="40" stroke="#036" stroke-width="3"/></svg>'

async def main():
    async with stdio_client(params) as (r, w):
        async with ClientSession(r, w) as s:
            await s.initialize()
            tools = await s.list_tools()
            print("TOOLS", len(tools.tools), [t.name for t in tools.tools])
            async def t(_tool, **a):
                res = await s.call_tool(_tool, a)
                txt = res.content[0].text if res.content else ""
                print(f"\n== {_tool} {'ERROR' if res.is_error else 'ok'}: {txt[:400]}")
                if res.is_error: sys.exit(1)
                return json.loads(txt) if txt.startswith("{") else txt
            await t("moodle_info")
            c = await t("create_course", fullname="Linux-perusteet", shortname="LNX"+str(os.getpid()), summary="<p>Testikurssi</p>", num_sections=2)
            cid = c["courseid"]
            sec = await t("add_section", course_id=cid, name="Komentorivi", summary="<p>Peruskomennot</p>")
            await t("update_section", course_id=cid, section=1, fields={"name": "Johdanto", "summary": "<p>Mitä Linux on?</p>"})
            page = await t("add_activity", course_id=cid, section=1, modname="page", fields={"name": "Mikä on Linux", "content": "<h3>Linux</h3><p>Avoin käyttöjärjestelmä.</p>", "completion": 2, "completionview": 1})
            await t("add_image", alt="Kytkentäkaavio", path=str(IMG), cm_id=page["cmid"], caption="Kuva 1")
            await t("add_image", alt="SVG kaavio", svg=SVG, filename="kaavio.svg", cm_id=page["cmid"], position="start")
            await t("add_image", alt="Osion kuva", path=str(IMG), course_id=cid, section=1)
            asg = await t("add_activity", course_id=cid, section=1, modname="assign", fields={"name": "Tehtävä 1", "intro": "<p>Palauta vastaus</p>", "duedate": 1767225600, "assignsubmission_onlinetext_enabled": 1, "assignsubmission_file_enabled": 1, "grade": 100})
            await t("add_activity", course_id=cid, section=1, modname="label", fields={"intro": "<p><strong>Huom!</strong> Lue ensin.</p>"})
            await t("add_activity", course_id=cid, section=1, modname="url", fields={"name": "Ubuntu", "externalurl": "https://ubuntu.com"})
            await t("add_activity", course_id=cid, section=1, modname="resource", fields={"name": "Ohje PDF"}, attachments=[str(PDF)])
            await t("add_activity", course_id=cid, section=1, modname="forum", fields={"name": "Keskustelu", "type": "general"})
            q = await t("add_activity", course_id=cid, section=sec["section"], modname="quiz", fields={"name": "Komentotentti", "timelimit": 1200, "attempts": 2, "availability": {"op":"&","c":[{"type":"completion","cm":page["cmid"],"e":1}],"showc":[True]}})
            await t("import_questions", cm_id=q["cmid"], questions=GIFT, max_mark=2, questions_per_page=1)
            book = await t("add_activity", course_id=cid, section=sec["section"], modname="book", fields={"name": "Komento-opas"})
            ch1 = await t("add_book_chapter", cm_id=book["cmid"], title="ls", content="<p>Listaa tiedostot</p>")
            ch2 = await t("add_book_chapter", cm_id=book["cmid"], title="cd", content="<p>Vaihda hakemistoa</p>")
            await t("add_book_chapter", cm_id=book["cmid"], title="Alkuun", content="<p>Esittely</p>", pagenum=1)
            await t("add_image", alt="ls-kuva", path=str(IMG), cm_id=book["cmid"], chapter_id=ch1["chapterid"])
            await t("update_book_chapter", cm_id=book["cmid"], chapter_id=ch2["chapterid"], fields={"title": "cd – hakemiston vaihto"})
            # edits on existing course in a "new session" scenario: simulate by restarting? just call update
            await t("update_activity", cm_id=asg["cmid"], settings={"name": "Tehtävä 1 (päivitetty)", "duedate": 1769904000, "completionsubmit": 1, "bogus": 1}, cm={"completion": 2, "visible": 0})
            dup = await t("duplicate_activity", cm_id=page["cmid"])
            await t("move_activity", cm_id=dup["cmid"], section=2)
            await t("delete_activity", cm_id=dup["cmid"])
            await t("move_section", course_id=cid, section=3, destination=2)
            await t("delete_section", course_id=cid, section=3)
            st = await t("get_course", course_id=cid)
            for sct in st["sections"]:
                print(" S", sct["section"], sct["name"], [ (m["cmid"], m["modname"], m["name"]) for m in sct["modules"]])
                for m in sct["modules"]:
                    if m["modname"]=="quiz": print("   Q", [(x["slot"], x["page"], x["name"], x["maxmark"]) for x in m["questions"]])
                    if m["modname"]=="book": print("   B", [(x["pagenum"], x["title"]) for x in m["chapters"]])
                    if m["modname"]=="page": print("   P", m["settings"]["content"][:300], m["files"])
            bk = await t("backup_course", course_id=cid)
            rs = await t("restore_course", mbz_path=bk["saved_to"], fullname="Linux-perusteet (palautettu)", shortname="R"+str(os.getpid()))
            st2 = await t("get_course", course_id=rs["courseid"], include_content=False)
            print(" restored sections:", [(x["section"], x["name"], len(x["modules"])) for x in st2["sections"]])
            print("\nALL OK – test courses:", cid, rs["courseid"])
asyncio.run(main())
