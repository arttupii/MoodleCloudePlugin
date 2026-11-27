# Moodle + Claude: kurssien rakentaminen paikalliseen Moodleen

Claude rakentaa ja muokkaa Moodle-kursseja MCP-työkaluilla: kurssit, osiot, sivut, tehtävät, tentit, kirjat, kuvat, suoritusehdot ja varmuuskopiot.

```
Claude Desktop / Claude Code ──stdio──► mcp-server/moodle_mcp.py ──REST + token──► Moodle (local_claudeapi)
```

| Kansio | Sisältö |
|---|---|
| `moodle-plugin/claudeapi/` | Moodle-lisäosa `local_claudeapi` |
| `mcp-server/` | Python-MCP-palvelin |
| `tests/e2e_test.py` | Päästä päähän -testi oikeaa Moodlea vastaan |
| `scripts/build-plugin-zip.sh` | Tekee asennettavan `local_claudeapi.zip`-paketin |

Kaksi osaa:

1. **local_claudeapi** – Moodle 5.x -lisäosa, joka lisää yhden web-palvelufunktion (`local_claudeapi_execute`). Sillä voi luoda ja muokata kursseja, osioita, aktiviteetteja, kuvia, kirjan lukuja ja tenttikysymyksiä sekä ottaa ja palauttaa varmuuskopioita. Toimii ylläpitäjän oikeuksin.
2. **mcp-server** – Python-MCP-palvelin, jonka kautta Claude Desktop tai Claude Code käyttää lisäosaa.

> ⚠️ Asenna lisäosa **vain omaan kehitys-Moodleen**. Token antaa täydet ylläpitäjän oikeudet. Valmis kurssi siirretään tuotantoon .mbz-varmuuskopiona.

Testattu: Moodle 5.0.12 ja 5.1.9, PostgreSQL 16, PHP 8.3, Python 3.11, mcp 2.3.

---

## 1. Lisäosan asennus Moodleen

Moodle 5.1:stä alkaen lisäosat ovat kansiossa `public/`. Esimerkit olettavat, että Moodle on hakemistossa `/var/www/moodle`.

**Komentorivillä (suositus):**
```bash
cd /tmp && unzip -o ~/local_claudeapi.zip   # scripts/build-plugin-zip.sh tekee tämän
# Moodle 5.1+:
sudo cp -r /tmp/claudeapi /var/www/moodle/public/local/
sudo chown -R www-data:www-data /var/www/moodle/public/local/claudeapi
# (Moodle 5.0: /var/www/moodle/local/)
cd /var/www/moodle
sudo -u www-data php admin/cli/upgrade.php --non-interactive
```

**Selaimella:** *Ylläpito → Lisäosat → Asenna lisäosa*. Tämä toimii vain, jos www-data saa kirjoittaa `local`-kansioon. Yleensä se ei saa, ja hyvä niin.

## 2. Web-palvelut ja token

Aja palvelimella Moodlen juurihakemistossa (käyttäjänä on oltava sivuston ylläpitäjä):
```bash
cd /var/www/moodle
sudo -u www-data php public/local/claudeapi/cli/setup.php --username=admin   # 5.1+
# 5.0: sudo -u www-data php local/claudeapi/cli/setup.php --username=admin
```
Docker-asennuksessa:
```bash
docker exec -u www-data <kontti> php <moodle-juuri>/public/local/claudeapi/cli/setup.php --username=admin
```
Skripti ottaa käyttöön web-palvelut ja REST-protokollan, valtuuttaa käyttäjän *Claude API* -palveluun ja tulostaa:
```
MOODLE_URL=http://moodle.local
MOODLE_TOKEN=0123456789abcdef...
```
Vinkki: lokeista näkee helpommin, mitä Claude on tehnyt, jos luot sille oman ylläpitäjäkäyttäjän, esimerkiksi `claude-bot`.

## 3. MCP-palvelin Macille

```bash
git clone https://github.com/arttupii/MoodleCloudePlugin.git ~/MoodleCloudePlugin
cd ~/MoodleCloudePlugin/mcp-server
python3.11 -m venv .venv
.venv/bin/pip install -r requirements.txt
```
Testaa yhteys:
```bash
MOODLE_URL=http://moodle.local MOODLE_TOKEN=xxx .venv/bin/python -c \
  "import moodle_mcp as m; print(m.call('ping')['release'])"
```

### Claude Desktop
Avaa *Settings → Developer → Edit Config* (`~/Library/Application Support/Claude/claude_desktop_config.json`) ja lisää:
```json
{
  "mcpServers": {
    "moodle": {
      "command": "/Users/KÄYTTÄJÄ/MoodleCloudePlugin/mcp-server/.venv/bin/python",
      "args": ["/Users/KÄYTTÄJÄ/MoodleCloudePlugin/mcp-server/moodle_mcp.py"],
      "env": {
        "MOODLE_URL": "http://moodle.local",
        "MOODLE_TOKEN": "TÄHÄN_TOKEN",
        "BACKUP_DIR": "/Users/KÄYTTÄJÄ/MoodleBackups"
      }
    }
  }
}
```
Käynnistä Claude Desktop uudelleen. Työkalut löytyvät 🔧-valikosta nimellä *moodle*.

### Claude Code
```bash
claude mcp add moodle \
  --env MOODLE_URL=http://moodle.local \
  --env MOODLE_TOKEN=TÄHÄN_TOKEN \
  -- ~/MoodleCloudePlugin/mcp-server/.venv/bin/python ~/MoodleCloudePlugin/mcp-server/moodle_mcp.py
```

---

## Mitä Claude osaa (työkalut)

| Alue | Työkalut |
|---|---|
| Lukeminen | `get_course` (koko rakenne, sisältö, asetukset, tiedostot, kirjan luvut, tentin kysymykset), `list_courses`, `list_categories`, `list_files` |
| Kurssi ja osiot | `create_course`, `update_course`, `add_section`, `update_section`, `move_section`, `delete_section` |
| Aktiviteetit | `add_activity` (page, label, assign, quiz, book, url, resource, folder, forum, glossary, lesson, …), `update_activity`, `move_activity`, `duplicate_activity`, `delete_activity` |
| Kuvat | `add_image`: tiedosto tai Clauden piirtämä SVG sivulle, kuvaukseen, kirjan lukuun tai osion yhteenvetoon, alt-teksteineen |
| Kirja | `add_book_chapter`, `update_book_chapter`, `delete_book_chapter` |
| Tentit | `import_questions` (GIFT / Moodle XML, lisää suoraan tenttiin), `remove_quiz_question` |
| Varmuuskopiot | `backup_course` (.mbz Macille), `restore_course` (.mbz → uusi kurssi muokattavaksi) |
| Tiedostot | `upload_file` (mikä tahansa tiedosto mille tahansa tiedostoalueelle, isot tiedostot 4 Mt:n paloissa), `download_file` (mikä tahansa tallennettu tiedosto koneelle), `list_files`, `delete_file` |
| Muut | `raw_action` (mikä tahansa lisäosan toiminto) |

Myös suoritusehdot (`completion`, `completionview`, `completionsubmit`, …) ja pääsyrajoitukset (`availability`) toimivat.

**Turvaverkko:** ennen ensimmäistä muutosta olemassa olevaan kurssiin MCP tallentaa siitä automaattisesti .mbz-varmuuskopion kansioon `BACKUP_DIR/auto/`, kerran istuntoa kohden. Ominaisuuden voi poistaa käytöstä asetuksella `AUTO_BACKUP=0`.

## Siirto toiseen Moodleen
1. Pyydä Claudea: *"ota kurssista X varmuuskopio"*. Tiedosto tallentuu kansioon `~/MoodleBackups`.
2. Kohde-Moodlessa: *Kurssin hallinta → Palauta → lataa .mbz*.
3. Paikallinen Moodle ei saa olla uudempi kuin kohde-Moodle, koska uudemmasta versiosta otettu varmuuskopio ei palaudu vanhempaan.

## Esimerkkipyyntöjä
- *"Tee kurssi Linux-perusteet, 5 osiota. Jokaiseen osioon teoriasivu, palautustehtävä ja 5 kysymyksen tentti. Tentti aukeaa, kun sivu on luettu."*
- *"Piirrä osion 2 sivulle SVG-kaavio, jossa näkyy hakemistorakenne / → home → opiskelija."*
- *"Siirrä kaikkien tehtävien palautuspäiviä 2 viikkoa eteenpäin."*
- *"Palauta ~/Downloads/kurssi.mbz ja korjaa siitä kaikki vanhentuneet linkit."*

## Vianetsintä
- `accessexception` / `invalidtoken`: aja `setup.php` uudelleen ja tarkista token.
- `Cannot reach Moodle`: tarkista, että MOODLE_URL aukeaa Macin selaimessa. Osoitteen pitää olla sama kuin `$CFG->wwwroot` Moodlen `config.php`:ssä.
- Yli 4 Mt:n tiedostot (`upload_file`, liitteet, `restore_course`, `download_file`, `backup_course`) siirretään 4 Mt:n paloissa, joten PHP:n `post_max_size` ei rajoita kokoa.
- MCP-palvelimen 0.2.0-versio vaatii lisäosan 0.2.0-version (`upload_id`-tuki). Päivitä molemmat yhtä aikaa.

## Testaus
```bash
cd mcp-server && .venv/bin/pip install -r requirements.txt
MOODLE_URL=http://moodle.local MOODLE_TOKEN=xxx .venv/bin/python ../tests/e2e_test.py
```
Testi luo kurssin, johon tulee kaikki aktiviteettityypit, kuvat, tentti ja kirja. Sen jälkeen se muokkaa kurssia, ottaa varmuuskopion ja palauttaa sen uutena kurssina. **Aja testi vain kehitys-Moodlessa.**

## Lisenssi
GPL v3 tai uudempi (sama kuin Moodlella), katso `LICENSE`.
