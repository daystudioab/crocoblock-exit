# Ändringar

## 1.6.3
- Marginal, padding, ram och skugga från Avancerat-fliken dubbleras inte längre i den överförda stilen (de återskapas redan från inställningarna). Dubbleringen gjorde t.ex. relaterade artiklar 20 px smalare och förskjutna 10 px.
- Sektionerna runt en karusell jämförs också löst – deras mätning innehåller samma rullande innehåll.

## 1.6.2
- Widgets som medvetet byter layout (Jet-karusell, Smart Listing/Tiles → rutnät) jämförs till stilen i stället för ruta för ruta. En karusell med autospelning hinner rulla vidare under mätningen och visade därför "fel" innehåll mot rutnätet – nu stoppar den inte längre Genomför. Rapporten anger att elementet jämförts löst.

## 1.6.1
- Genomför stoppas inte längre av dokument som inte går att nå på någon egen adress (t.ex. en mall vars sida inte kan gissas, eller en sida vars adress går till inloggningen). De kontrollerades aldrig före konverteringen och hoppas nu över i HTML-kontrollen efteråt – ett dokument som syntes före konverteringen men inte efter stoppar fortfarande.

## 1.6.0
- Uppdateringar från GitHub: nya versioner syns under Tillägg som vanliga uppdateringar ("Uppdatera nu" och automatiska uppdateringar). En version publiceras först när alla tester har gått igenom.

## 1.5.1
- Autopiloten bekräftar varje skillnad med en ny mätning och väntar tills sidan står still – inga falsklarm från bilder och skript som laddar sent.
- Positionsberoende CSS (CSS Hero) stabiliseras bara i de skärmbredder där originalet träffade.
- Relaterade inlägg (Jet-karusell): rätt bildhöjd från CSS Hero, ingen negativ marginal på listan, pilmarginalen på listan i stället för widgeten.

## 1.5.0
- Förenklad sida: Testa → Genomför → Stäng av Crocoblock, plus Ångra allt. De enskilda stegen finns under Avancerat.

## 1.4.1
- Förhandsvisning på plats: original och konverterad version jämförs på exakt samma sida.
- Stabila kopior av positionsberoende CSS-regler (t.ex. CSS Hero).
- Mallar jämförs på en sida där deras visningsvillkor gäller.

## 1.4.0
- Autopilot: alla steg i följd med visuell jämförelse i tre skärmbredder och automatisk återställning.

## 1.3
- Spärr mot att stänga av Crocoblock medan något fortfarande använder det; Återställ slår på Crocoblock igen.
- Egna WP_Query-frågor (JSON) följer med; kolumner, avstånd och antal som originalet.

## 1.2
- Avveckling av CSS Hero.

## 1.1
- Utseendet följer med: stil, sajtens egen CSS, menyer och karuseller.

## 1.0
- Första versionen: inventering, konvertering, mallar, innehållstyper, backup och återställning.
