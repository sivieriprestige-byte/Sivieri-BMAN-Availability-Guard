# Registro modifiche

## 1.7.0 — 17 ottobre 2026

- Recupero degli invii Bman che tentano di ricreare uno SKU già presente, senza duplicare la scheda.
- Ripristino controllato dei vecchi prodotti nel cestino, in bozza con conservazione dello slug originale e ingresso nella coda pubblicazione.
- Riparazione mirata dei soli record SKU orfani appartenenti al prodotto richiesto.
- Protezione di contenuti editoriali, immagini, metadati SEO e regole manuali durante il ricollegamento.
- Controlli su padre delle varianti, combinazioni colore/taglia, tipo prodotto, conflitti URL e codici ambigui.
- Protezione non bloccante dagli invii simultanei e storico amministrativo dei recuperi.
- Test di regressione PHP per i nuovi comportamenti e le regole di rientro in stock.

## Base di produzione 1.6.2

La versione 1.7.0 parte dalla copia 1.6.2 verificata sul sito il 17 ottobre 2026. Il repository conteneva precedentemente la 1.4.0; la base di produzione viene conservata in un commit separato per distinguere le modifiche di recupero dalle funzionalità già installate.
