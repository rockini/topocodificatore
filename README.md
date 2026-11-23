# Topocodificatore

Geocoder per l'Italia su PostgreSQL/PostGIS, con API in PHP.

- **Dati**: civici [OpenAddresses](https://openaddresses.io) e OpenStreetMap, località significative
  del DBSN IGM (`loc_sg`), confini comunali ISTAT.
- **Funzione PHP** `geocodifica($indirizzo)`: lista di posizioni candidate con probabilità.
- **Ricerca a cascata**: civico → via → frazione/toponimo IGM → comune, con un punteggio di confidenza.
- **API**: `/search` e `/reverse` (KNN PostGIS), risposte in GeoJSON.

Il geocoder TIGER incluso in PostGIS non si usa: funziona solo con i dati del censimento USA.

## Requisiti

- PostgreSQL 13+ con PostGIS 3, `pg_trgm`, `unaccent` e `fuzzystrmatch` (le ultime tre sono in `postgresql-contrib`)
- PHP 8.2+ con `pdo_pgsql` e `mbstring`
- `ogr2ogr` (pacchetto `gdal-bin`), solo per importare file (Shapefile, GeoPackage, GeoJSON...)

Nessuna dipendenza Composer: `src/bootstrap.php` fa l'autoload. `composer.json` c'è per chi preferisce
`vendor/autoload.php`.

## Avvio rapido

```bash
cp config/config.example.php config/config.php   # connessione al database e fonti dei dati
php bin/schema.php                                # estensioni, funzioni, tabelle (si può rieseguire)
php bin/importa.php tutto                         # comuni, poi civici (e strade), poi toponimi
php -S localhost:8080 -t public public/index.php  # API di sviluppo
```

```bash
curl 'http://localhost:8080/search?q=Via+Garibaldi+12/A,+20121+Milano'
curl 'http://localhost:8080/search?via=Corso+Italia&civico=16&comune=Cortina+d%27Ampezzo'
curl 'http://localhost:8080/reverse?lat=45.4806&lon=9.1873&raggio=50&limit=3'
curl 'http://localhost:8080/health'
```

## Uso da PHP

```php
require 'src/bootstrap.php';   // oppure vendor/autoload.php

$candidati = \Topocodificatore\geocodifica('Via Roma 12, 50123 Firenze', 5);
foreach ($candidati as $c) {
    printf("%.6f %.6f  p=%.2f  %s (%s)\n", $c['lat'], $c['lon'], $c['probabilita'], $c['etichetta'], $c['livello']);
}
```

Ogni candidato ha `lat`, `lon`, `probabilita` (peso rispetto agli altri candidati restituiti, le
probabilità sommano a 1), `confidenza` (quanto il candidato corrisponde all'indirizzo, da 0 a 1,
indipendente dagli altri), `livello`, `etichetta`, i campi dell'indirizzo e la `fonte`
(`openaddresses`, `osm`, `dbsn`, `istat`, `strade` per il centro di una via, `interpolazione`).
Se l'unico candidato è il centro del comune, la probabilità è 1 ma la confidenza resta bassa: per
decidere se fidarsi del risultato guarda la confidenza.

## Struttura

| Percorso | Contenuto |
|---|---|
| `sql/01_estensioni.sql` | estensioni e schemi `geocoder` e `staging` |
| `sql/02_funzioni.sql` | normalizzazione di nomi e civici (`geocoder.normalizza`, `geocoder.civico`, `geocoder.dug`, `geocoder.denominazione`) |
| `sql/03_schema.sql` | tabelle e indici GiST / trigram |
| `sql/04_dbsn.sql` | dominio dei tipi di località DBSN e calcolo dell'importanza |
| `scripts/osm_civici.sql` | estrazione dei civici da un import osm2pgsql (`--hstore`) |
| `sql/import/*.sql` | trasformazione da staging alle tabelle definitive |
| `bin/schema.php`, `bin/importa.php` | script da riga di comando |
| `src/` | importatore, analizzatore degli indirizzi, geocoder, API |
| `public/index.php` | punto di ingresso HTTP |
| `tests/` | test e dati di prova sintetici |

## Database

Schema `geocoder`, tutto in WGS84 (EPSG:4326):

- **`comuni`**: codice ISTAT, nome, sigla della provincia, regione, confine (MultiPolygon) e un punto interno.
- **`civici`**: i civici di tutte le fonti con via e civico originali, via normalizzata, civico scomposto
  (`numero`, `esponente`, `snc`), interno, CAP, frazione, comune (ricavato dalla posizione), strada e fonte.
- **`strade`**: aggregate dai civici per comune e nome normalizzato. I civici con lo stesso nome ma lontani
  più di ~1 km (DBSCAN) diventano strade diverse, così due "Via Roma" in frazioni diverse non si fondono.
  Il punto della strada è il civico più vicino al baricentro.
- **`toponimi_igm`**: nome, tipo, comune, geometria originale (punto, linea o area), un punto sulla
  geometria, importanza (0–1, decide tra toponimi omonimi), fonte e gli attributi della fonte che si
  vogliono tenere (`jsonb`).
- **`importazioni`**: registro degli import.

Indici: GiST sulle geometrie (anche per il KNN di `/reverse`), GIN trigram (`pg_trgm`) sui nomi normalizzati.

### Normalizzazione

È scritta una volta sola, in SQL, e la usano sia l'import sia l'API: dati e domande vengono normalizzati
sempre allo stesso modo.

| Ingresso | `geocoder.normalizza` |
|---|---|
| `P.zza S. Maria Maggiore` | `piazza s maria maggiore` |
| `V.le XX Settembre` | `viale 20 settembre` |
| `Via Sant'Antonio` | `via s antonio` |
| `Via Prof. Gaetano Salvemini` | `via gaetano salvemini` |
| `S.S. 16 Adriatica` | `ss 16 adriatica` |
| `Località Cà di Sopra` | `localita ca di sopra` |

Minuscole, niente accenti né punteggiatura, abbreviazioni espanse (V., V.le, P.zza, C.so, L.go, Loc., Fraz.,
F.lli...), San/Santo/Santa/S. uniformati, titoli tolti (Dott., Prof., Avv....), numeri romani convertiti.
`geocoder.denominazione` toglie inoltre la DUG (via, piazza...), articoli, preposizioni e iniziali:
è la chiave con cui si confrontano le strade (`Via G. Garibaldi` → `garibaldi`).

`geocoder.civico` scompone i civici: `12`, `12/A`, `12 a`, `12a`, `12 bis`, `12/1`, `12r` o `12 rosso`
(civici rossi di Genova), `SNC` / `s.n.c.`.

## Import

`bin/importa.php <comuni|civici|toponimi|tutto>` sostituisce ogni volta i dati del dataset, dentro una
transazione: se l'import fallisce, restano i dati precedenti. Importa prima i comuni: civici e toponimi
ricavano il comune dalla posizione. Dopo aver reimportato i comuni, reimporta anche civici e toponimi.

Ogni fonte si descrive in `config/config.php` con un **tipo** e una **mappatura** dalle colonne canoniche
alla fonte. Un dataset può avere più fonti, in ordine di priorità: per i civici, quando lo stesso civico
compare in due fonti (stesso numero, via con nome simile, entro 30 m) si tiene quello della prima. Così lo schema delle tabelle sorgente non è cablato nel codice. Vedi `config/config.example.php`.

| Tipo | Fonte | La mappatura contiene |
|---|---|---|
| `tabella` | tabella o query nello stesso database | espressioni SQL sull'alias `t` |
| `file` | qualunque file letto da `ogr2ogr` (Shapefile, GeoPackage, GeoJSON, CSV...) | espressioni SQL sull'alias `t` (colonne in minuscolo) |
| `pdo` | tabella o query in un altro database (qualunque driver PDO) | espressioni SQL della fonte; geometria come testo WKT/EWKT/WKB esadecimale, oppure lon/lat |
| `csv` | file CSV di OpenAddresses, anche `.gz` e con glob | nomi di colonna (predefiniti per OpenAddresses) |
| `geojsonl` | file GeoJSON a una feature per riga (formato attuale di OpenAddresses) | nomi di proprietà (predefiniti) |

Colonne canoniche (più `geom` oppure `lon` e `lat`; `srid` indica il sistema della fonte, es. 3003/3004 Monte
Mario, 6707/6708 RDN2008, 32632/32633 UTM):

- `comuni`: `istat`, `nome`, `provincia`, `regione`
- `civici`: `fonte_id`, `via`, `civico` (con l'esponente: `12/A`), `interno` (scala, interno), `frazione`,
  `comune`, `provincia`, `cap`
- `toponimi`: `fonte_id`, `nome`, `tipo`, `importanza` (0–1), `attributi` (un'espressione che restituisce JSON)

Oltre alla mappatura, ogni fonte ha un nome (`'fonte' => 'osm'`) che finisce nei risultati.

### Le tre fonti previste

- **OpenAddresses Italia**: `NUMBER` contiene già l'esponente, `UNIT` è l'interno e non entra nel civico,
  `DISTRICT` diventa la frazione (aiuta a scegliere tra vie omonime dello stesso comune). Il nome della
  strada mostrato è la grafia più frequente, preferendo quelle non tutte maiuscole.
- **OpenStreetMap**: la tabella `osm_civici` di `scripts/osm_civici.sql`, con priorità più bassa di
  OpenAddresses.
- **DBSN, località significative (`loc_sg`)**: `loc_sg_top` è il nome; `geocoder.dbsn_tipo_localita(loc_sg_ty)`
  traduce il codice del tipo; `geocoder.dbsn_importanza(loc_sg_ty, loc_sg_sgn)` dà più peso alle località
  di primo ordine e ai luoghi abitati rispetto alle aree geografiche (monti, valli...).

Righe senza geometria leggibile, senza via o senza nome vengono scartate; i civici duplicati (stesso comune,
via, civico e punto) vengono tenuti una volta sola. Il conteggio finisce in `geocoder.importazioni`.

## API

### `GET /search`

Testo libero (`q`) oppure parametri strutturati (`via`, `civico`, `localita`, `cap`, `comune`, `provincia`),
che prevalgono su ciò che si ricava da `q`. `limit` (1–50, predefinito 5).

L'analizzatore riconosce CAP, provincia (`(MI)` o `MI` in fondo), civico e comune anche senza virgole
(`via XX Settembre 5 Genova`); i numeri che fanno parte del nome (`Via 20 Settembre`, `Corso 22 Marzo`,
`Strada Provinciale 12`) non vengono presi per civici.

Cascata:

1. **comune** dal nome (esatto, abbreviato come `Cortina`, o con refusi), dal CAP o dalla provincia;
   se il nome non è un comune viene trattato come frazione/località;
2. **via**: confronto trigram sulla denominazione, anche parziale (`Garibaldi` per `Giuseppe Garibaldi`)
   e, dentro il comune, con distanza di Levenshtein per i refusi (`Via Rome`);
3. **civico** sulla via trovata: esatto, stesso numero con altro esponente, **interpolato** tra il civico
   precedente e il successivo dello stesso lato (stessa parità), oppure il più vicino;
4. **toponimo** (località DBSN) se non c'è una via (o se la via è una località: `Località Pocol`);
5. **comune** come ultima risorsa.

Ogni risultato è una `Feature` con `livello` (`civico`, `civico_interpolato`, `civico_vicino`, `via`,
`toponimo`, `comune`) e `confidenza` (0–1): quanto il risultato corrisponde alla richiesta. Un risultato
meno preciso del richiesto perde confidenza (−20% per ogni livello), così come un comune non indicato
o indovinato.

```json
{
  "type": "FeatureCollection",
  "features": [{
    "type": "Feature",
    "geometry": {"type": "Point", "coordinates": [9.187, 45.48065]},
    "properties": {
      "livello": "civico", "confidenza": 0.834,
      "etichetta": "Via Giuseppe Garibaldi 13, 20121 Milano (MI)",
      "via": "Via Giuseppe Garibaldi", "civico": "13", "cap": "20121",
      "comune": "Milano", "provincia": "MI", "istat": "015146",
      "fonte": "openaddresses", "id": 15, "strada_id": 6
    }
  }],
  "query": {"testo": "Via Garibaldi 13, Milano", "componenti": {"via": "Via Garibaldi", "civico": "13", "comune": "Milano"}}
}
```

### `GET /reverse`

`lat`, `lon`, `raggio` in metri (predefinito 100, massimo 5000), `limit` (predefinito 1). Restituisce i
civici più vicini (KNN con `<->` sull'indice GiST, poi distanza in metri sul geoide); se non ce ne sono
nel raggio, il toponimo più vicino entro 2 km; altrimenti il comune che contiene il punto.

### `GET /health`

Numero di righe di ogni tabella.

## Test

```bash
php tests/esegui.php     # solo l'analizzatore degli indirizzi

# anche normalizzazione, import e API: serve un database DEDICATO, i dati vengono cancellati
GEOCODER_TEST_DSN="pgsql:host=localhost;dbname=geocoder_test" GEOCODER_TEST_UTENTE=... GEOCODER_TEST_PASSWORD=... \
    php tests/esegui.php
```

I dati in `tests/fixtures` sono sintetici (poche vie e confini rettangolari), servono solo ai test.

## Limiti noti e prossimi passi

- Le mappature di `config/config.example.php` seguono le strutture descritte di OpenAddresses, di
  `osm_civici` e di `loc_sg`: vanno controllati i nomi esatti di tabelle e colonne nel database reale.
- Le prestazioni vanno misurate sul dataset completo (`EXPLAIN ANALYZE` delle query di `/search`).
- Durante un import le tabelle restano bloccate in lettura fino al commit.
- Le sigle delle province non sono nello shapefile ISTAT dei comuni: si ricavano con un'espressione
  nella mappatura (vedi l'esempio di configurazione).
