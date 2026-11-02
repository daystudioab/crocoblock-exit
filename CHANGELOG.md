# Ändringar

## 1.10.1

- Viktig rättelse: när en förhandsvisning (originalsidan med kopian inbytt)
  laddades medan Elementors CSS-cache var tom, byggde Elementor originalets
  LIVE CSS-fil av kopians innehåll. Besökarna kunde då se originalets widgets
  med fel stil tills cachen byggdes om. Originalets CSS-fil byggs nu alltid
  av originalet, och Testa rensar originalens CSS-cache när det startar så
  att filer som redan blivit fel byggs om rätt.

## 1.10.0

- Lottie-animationer (jet-lottie) konverteras nu automatiskt till Elementor
  Pros Lottie-widget: JSON-fil eller extern adress, start (direkt, hover,
  klick, i bild, scroll), loop, antal varv, hastighet, baklänges, renderare,
  bredd och justering per skärmstorlek. Jets standard – spela direkt, i loop,
  vänsterjusterat – sätts uttryckligen, eftersom Pros standard är en annan.

## 1.9.6

- Sista höjdskillnaden i omdömeskarusellen: Pro-skinnets cite-element
  (namnraden) har 25px toppmarginal som Jet saknade – den nollas nu som
  reservregel, så karusellen blir exakt lika hög som originalet.

## 1.9.5

- Omdömeskarusellens höjd matchar nu originalet: Pro-karusellens standardmått
  (padding på sliden, marginal under kortets fot) nollas som reservregler, och
  Jet-skinnets 5px-marginal på kommentaren följer med. Tidigare blev den
  konverterade karusellen ~80px högre och knuffade innehållet under sig.

## 1.9.4

- Omdömeskarusellens reservregler (typografi/färg ur widgetens inställningar)
  skrivs nu med :where(), så att stilen som översätts från Elementors
  genererade CSS alltid vinner över dem. Tidigare kunde reservens
  color:inherit slå ut den översatta färgen.

## 1.9.3

- Stilöversättning för omdömeskaruseller: Elementors genererade CSS för
  jet-testimonials (färger, typografi m.m. satta i widgetens stilflikar)
  översätts nu till Pro-karusellens klasser och följer med som Custom CSS,
  precis som för jet-posts och övriga karterade widgets.

## 1.9.2

- Mallar som inte syns på sin gissade adress – t.ex. ett allmänt sidhuvud när
  startsidan har ett eget – jämförs nu på en annan sida där de faktiskt visas,
  i stället för att lämnas ojämförda.

## 1.9.1

- Omdömeskaruseller (jet-testimonials) konverteras nu med utseendet intakt:
  typografi, färger och marginaler för text och namn följer med till Elementor
  Pros karusell, liksom tonings-effekten (fade). Stjärnbetygen ritas med CSS
  när alla omdömen har samma betyg. Karusellen jämförs översiktligt av
  autopiloten, eftersom innehållet växlar av sig självt.

## 1.9.0

- Tomma dynamiska fält döljs nu vid visning, som Jet gjorde: listpunkter och
  widgets (rubrik, textredigerare, ikonlista) vars dynamiska innehåll (t.ex.
  ett ACF-fält) är tomt tas bort, och rutor – sektioner och kolumner – som
  därmed blir helt tomma döljs med dem. Statiskt innehåll berörs aldrig, och
  i Elementors redigerare visas allt som vanligt.

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
