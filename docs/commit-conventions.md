# Convenzioni dei commit

I commit seguono Conventional Commits:

```
tipo(ambito): descrizione
```

La descrizione è in inglese, all'imperativo, in minuscolo, senza punto finale e al massimo di 100 caratteri, tipo e ambito compresi.

## Tipo

| Tipo | Quando |
| --- | --- |
| `feat` | Supporto a una proprietà o a un valore che prima veniva ignorato |
| `fix` | Un comportamento già supportato che produceva un risultato sbagliato |
| `perf` | Stesso risultato, più veloce |
| `refactor` | Stesso risultato, codice riorganizzato |
| `test` | Solo test |
| `docs` | Solo documentazione |
| `build` | `composer.json`, dipendenze, pubblicazione del pacchetto |
| `ci` | Pipeline di integrazione continua |

## Ambito

La modifica cambia un comportamento definito da una specifica?

- **Sì**: l'ambito è la sigla W3C della specifica, senza livello: `css-tables`, `css-images`, `css-sizing`, `css-writing-modes`, `css-position`, `css-inline`, `html`, `svg`. Per CSS 2.1 si usa il modulo moderno che ne ha preso il posto (es. `vertical-align` → `css-inline`).
- **No**: l'ambito è il componente del motore toccato, con il nome della cartella o classe in `src/`: `cellmap`, `line-box`, `renderer`, `fonts`, `cpdf`, `parser`.

Gli ambiti che iniziano con `css-`, `html` o `svg` sono riservati alle specifiche. Un componente del motore non inizia mai così.

Se un commit tocca sia una specifica sia il motore, l'ambito è la specifica.

`test`, `docs`, `build` e `ci` non hanno ambito.

## Esempi

```
feat(css-images): support object-fit fill, contain and cover
fix(css-tables): stretch rows to fill the specified table height
fix(cellmap): keep the row index when a cell is split
perf(renderer): cache the decoded images
build: publish the package as karim-tao/dompdf
```
