# Ändringar

## 1.8.1

- Uppdateringskontrollen har fått en reservväg: när GitHubs API inte går att nå
  från servern (t.ex. ett webbhotell vars delade IP-adress slagit i GitHubs
  anropstak) hämtas senaste versionen i stället via github.com:s omdirigering
  av releases/latest, som inte är begränsad på samma sätt. Uppdateringar syns
  därmed även på sådana servrar.

## 1.8.0
- Dynamiska fält som visar ACF-fält (JetEngine "Dynamic Field" med källan ACF) konverteras nu automatiskt till Elementor Pros ACF-tagg – rubrik eller text beroende på fältets HTML-tagg. Tidigare krävde de handpåläggning och spärrade avstängningen av Crocoblock.

## 1.7.2
- När en bild rapporteras som dold anges orsaken: vilken egenskap (display, visibility, opacity, aria-hidden eller hopfälld behållare) och på vilket element.

## 1.7.1
- En sida som laddades före en uppdatering av tillägget körde gammal logik i webbläsaren (t.ex. utan beslutspauserna). Nu upptäcks det när en körning startar: sidan laddas om och du klickar igen.
- När bilder saknas i jämförelsen namnges de, och rapporten säger om bilden är borta ur sidkoden eller finns men är dold – avgörande för felsökning.

## 1.7.0
- **Du får ta ställning.** Vid avvikelser eller fel pausar autopiloten och frågar i stället för att avgöra själv: efter testet (Avbryt / Konvertera ändå), efter konverteringen (Återställ allt / Behåll konverteringen) och efter avstängningen av Crocoblock (Slå på igen / Behåll avstängt). När Crocoblock är avstängt och sajten kan se trasig ut räknas det säkra valet ner och körs automatiskt om du inte svarar inom 2 minuter. Beslutet antecknas i rapporten.
- **Redan migrerade mallar uppdateras.** Mallar som migrerades med en äldre version av tillägget fick aldrig senare konverteringsrättelser – t.ex. kunde sidhuvudets logga saknas när det gamla migrerade sidhuvudet publicerades. Nu konverteras innehållet om (id, status och visningsvillkor rörs inte) varje gång mallsteget körs, och "Stäng av Crocoblock" gör det alltid först.


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
