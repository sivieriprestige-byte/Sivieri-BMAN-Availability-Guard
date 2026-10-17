# Sivieri BMAN Availability Guard

Versione 1.7.0. Gestisce visibilità e rientri di disponibilità del catalogo WooCommerce, conservando le schede pubblicate e i relativi URL. I nuovi prodotti Bman restano in bozza fino alla pubblicazione manuale.

## Recupero della sincronizzazione

Il recupero interviene sulle richieste **POST singole** delle API WooCommerce v3, per prodotti e varianti, autenticate con una chiave WooCommerce denominata **BMAN**, con permesso lettura/scrittura. Le richieste di altri strumenti e gli aggiornamenti ordinari mantengono il comportamento precedente. Nessuna credenziale viene registrata o incorporata nel codice.

| Situazione | Comportamento |
| --- | --- |
| Prodotto pubblicato esaurito che viene rifornito | Stessa scheda e URL; torna visibile secondo le regole di disponibilità. |
| Bman tenta di creare uno SKU che ha già una scheda | Riutilizza l'ID esistente e restituisce una risposta compatibile con la creazione, così Bman recupera il collegamento. |
| Vecchia scheda nel cestino, stesso SKU e struttura compatibile | Ripristino nativo WordPress; prodotto padre in bozza nella Coda pubblicazione, con slug originale conservato. |
| Prodotto cancellato definitivamente con riga SKU residua | Rimuove soltanto la riga di indice relativa allo SKU richiesto, e soltanto se non esiste più il relativo post. La nuova scheda segue la normale creazione in bozza. |
| Variante privata o cestinata dello stesso padre | Riutilizza l'ID; la visibilità viene poi gestita dal controllo di disponibilità. |
| Invii concorrenti sullo stesso SKU/padre | Blocco temporaneo non bloccante, senza attese SQL; il secondo invio riceve un errore che richiede di riprovare. |
| Codice ambiguo, altro padre, tipo incompatibile, URL già occupato o struttura attributi diversa | Nessuna fusione automatica. Errore leggibile e voce nello storico per verifica. |

Durante il ricollegamento il payload viene limitato a stock, prezzi, imposte e opzioni aggiuntive degli attributi già esistenti. Nomi, descrizioni, slug, immagini, categorie, tag, metadati SEO e impostazioni manuali non vengono sostituiti. Il flag “Fuori assortimento definitivo” continua a impedire la visibilità automatica.

Lo storico degli ultimi 50 recuperi/conflitti compare in **WooCommerce → Sivieri BMAN Availability → Recupero sincronizzazioni Bman**. Contiene solo data, SKU, ID ed esito. Non registra il payload o i clienti.

## Validazione

```sh
php -n -l sivieri-bman-availability-guard.php
php -n tests/recovery-test.php
```

I test di regressione eseguono il codice PHP del plugin, simulando i servizi WordPress/WooCommerce e il database. Coprono recupero, permessi, conservazione dei contenuti, indice orfano, concorrenza, conflitti e il ciclo esaurimento/rifornimento. Non sostituiscono la verifica del connettore reale Bman dopo l'installazione.

Le operazioni batch e le API WooCommerce v1/v2 non sono intercettate da questo recupero: il connettore del sito usa le richieste singole v3.
