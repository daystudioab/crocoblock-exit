# Crocoblock-avveckling

*Avvecklar även CSS Hero (från version 1.2).*

WordPress-tillägg som tar bort beroendet av Crocoblock (JetElements, JetTabs, JetBlocks, JetBlog, JetTricks, JetMenu, JetSearch, JetPopup, JetThemeCore, JetEngine m.fl.) och ersätter det med Elementor och Elementor Pro.

Samma tillägg installeras på varje sajt. Det inventerar först vad just den sajten använder och konverterar sedan det som går – med provkörning, förhandsgranskning, backup per sida och återställning.

**Nytt i 1.6: uppdateras från GitHub.** Nya versioner syns under Tillägg som vanliga uppdateringar. **Nytt i 1.5: tre knappar.** Testa → Genomför → Stäng av Crocoblock, plus Ångra allt. Se [CHANGELOG.md](CHANGELOG.md).

## Installation och uppdateringar

Ladda ner `crocoblock-exit.zip` från senaste versionen under [Releases](https://github.com/daystudioab/crocoblock-exit/releases) och installera den under Tillägg → Lägg till → Ladda upp.

Därefter uppdateras tillägget härifrån: WordPress frågar GitHub (var sjätte timme, eller direkt med "Kontrollera igen" under Instrumentpanel → Uppdateringar) och visar nya versioner som vanliga uppdateringar, med "Uppdatera nu" och möjlighet att slå på automatiska uppdateringar för tillägget.

En ny version skapas automatiskt när versionsnumret i `crocoblock-exit.php` höjs på `main` och **alla tester har gått igenom** (`.github/workflows/tests.yml`). Underkänns testerna skapas ingen version, så sajterna kan aldrig få en version som inte klarat testerna. Versionsnumret ska stå på två ställen i `crocoblock-exit.php` (huvudet och `CBX_VERSION`) och ha ett avsnitt i `CHANGELOG.md` – det kontrolleras också.

## Så används det (tre knappar)

Installera `crocoblock-exit.zip` (Tillägg → Lägg till → Ladda upp) och öppna **Verktyg → Crocoblock-avveckling**. Ta en full databasbackup först.

1. **Testa** – går igenom sajten och jämför varje sida som ändras, före och efter, i dator-, surfplatte- och mobilbredd. Ingenting ändras på sajten. Resultatet: *Godkänt*, *Avvikelser* (med exakt vad som skiljer) eller *Stoppat*.
2. **Genomför** – testar igen, konverterar sidorna och jämför den publicerade sajten med originalet. **Vid minsta fel återställs allt automatiskt.** Crocoblock är kvar och påslaget. Val: *Avveckla även CSS Hero* (förvalt om CSS Hero finns) och *Godta små avvikelser*.
3. **Stäng av Crocoblock** – går bara när inget längre använder Crocoblock (annars visas vad som återstår). Sajten mäts, Crocoblock stängs av och de nya sidhuvudena/sidfötterna/popuperna publiceras, och sajten mäts igen. **Skiljer något slås Crocoblock på igen automatiskt.**

**Du får alltid ta ställning.** Vid avvikelser eller fel pausar autopiloten, visar rapporten och frågar: efter testet (*Avbryt* / *Konvertera ändå*), efter konverteringen (*Återställ allt* / *Behåll konverteringen*) och efter avstängningen (*Slå på Crocoblock igen* / *Behåll avstängt*). När Crocoblock är avstängt kan besökarna se felet, så där räknas det säkra valet ner och körs automatiskt om du inte svarar inom 2 minuter.

**Ångra allt** lägger tillbaka originalsidorna, mallarna och CSS Hero och slår på Crocoblock igen.

Låt det här tillägget vara aktivt efteråt: det skriver ut översatt CSS, kör nyhetslistornas egna frågor och registrerar eventuella JetEngine-innehållstyper. Crocoblock-tilläggen kan tas bort när sajten fungerat ett tag.

Under **Avancerat – alla steg för hand** finns de enskilda stegen (inventering med CSV, förhandsgranskning per sida, konvertering av en enskild sida, sajtens CSS, CSS Hero, återställning).

### Hur kontrollen går till

- Varje sida och mall som ändras laddas två gånger i osynliga ramar – originalet och samma sida med den konverterade kopian **på plats** – i 1440, 768 och 375 px bredd. "På plats" betyder att adressen, sidans ID, body-klasser, temat och det dynamiska innehållet är exakt desamma; bara Elementor-innehållet byts (även sidhuvud, sidfot och inläggs-/arkivmallar, som visas på en sida där deras visningsvillkor gäller).
- Varje ändrat element mäts: position och storlek, **varje textrad** (plats, typsnitt, storlek, vikt, färg, versaler, understrykning) och **varje bild**. Animationer fryses och bilder laddas fullt först.
- **OK** = identiskt inom 3 px. **Avvikelse** = t.ex. ändrat avstånd eller typsnitt. **Fel** = element saknas, syns inte eller har tappat texter/bilder.
- Popuper, loop-mallar och sparade sektioner jämförs inte visuellt.
- **Positionsberoende CSS** (t.ex. CSS Heros `html > body > … > div:nth-child(2) > a`) slutar gälla när strukturen ändras – och konverteringen ändrar den, t.ex. försvinner JetElements parallax-lager som låg först i sektionerna. Testet slår upp vilka element sådana regler träffar och skapar stabila kopior som pekar på elementets Elementor-ID (samma deklarationer, högre specificitet). Kopiorna skrivs ut från och med konverteringen.

## Arbetsgång per sajt (för hand)

Ta en full backup av databasen innan du börjar (hos webbhotellet eller med t.ex. UpdraftPlus). Gör gärna första sajten på en staging-kopia.

1. **Installera** `crocoblock-exit.zip` under Tillägg → Lägg till → Ladda upp. Låt Crocoblock vara aktivt.
2. Gå till **Verktyg → Crocoblock-avveckling**.
3. **Steg 1 – Inventering.** Visar alla Crocoblock-widgets, hur många som ersätts automatiskt, vad som kräver handpåläggning och på vilka sidor. Ladda ner CSV:n om du vill planera arbetet.
4. **Steg 2 – Innehållstyper** (bara om JetEngine används). Spara ögonblicksbilden. Då fortsätter egna inläggstyper och taxonomier att fungera när JetEngine stängs av, och metafälten får en enkel redigeringsruta.
5. **Steg 3 – Mallar.** Kopierar JetEngine-listningar, JetPopup och JetThemeCore till Elementor Pro-mallar. Originalen rörs inte.
6. **Steg 4 – Sajtens egen CSS.** "Sök och översätt" hittar regler mot Crocoblocks klasser och skapar översatta kopior. De gäller bara konverterade widgetar och kan därför vara på redan nu.
7. **Steg 5 – CSS Hero** (bara om CSS Hero finns). Flyttar all CSS som CSS Hero levererar till Utseende → Anpassa → Ytterligare CSS och avaktiverar CSS Hero. Utseendet blir detsamma. Steg 4 körs om automatiskt så att CSS Heros Crocoblock-regler också översätts.
8. **Steg 6 – Provkör.** Klicka på **förhandsgranska** vid de viktigaste sidorna (startsida, sidhuvud) och jämför med originalet i en annan flik. Förhandsvisningen är en konverterad kopia som utkast – originalet rörs inte. Mallar som sidhuvudet visas ensamma på en tom sida.
9. **Konvertera.**
10. **Bygg om det manuella** som inventeringen pekar ut (medan Crocoblock fortfarande är aktivt, så att du ser hur det såg ut).
11. Kör **inventeringen igen** – målet är att bara kända, medvetna rester finns kvar.
12. **Steg 7 – Slutför bytet.** Publicerar sidhuvud/sidfot/popuper och avaktiverar Crocoblock. Steget gör en färsk kontroll först och stoppar om något fortfarande använder Crocoblock. Kontrollera sajten direkt.
13. Ta bort Crocoblock-tilläggen när allt fungerat ett tag. **Låt det här tillägget vara kvar** – det skriver ut den översatta sajt-CSS:en.

Om något går fel: **Steg 8 – Återställ** lägger tillbaka originalen och aktiverar Crocoblock igen automatiskt. Där kan du också ta bort de migrerade mallarna och ångra CSS Hero-flytten.

> Låt det här tillägget vara aktivt efter bytet. Det skriver ut den översatta sajt-CSS:en och kör nyhetslistornas egna frågor.

> Konvertera medan Crocoblock är aktivt. Stilen läses från den CSS Elementor genererar för Jet-widgetarna, och den finns bara så länge Crocoblock är igång.

> Låt det här tillägget vara aktivt efter bytet om sajten har JetEngine-inläggstyper – det är det som registrerar dem. Alternativt: ladda ner PHP-koden i steg 2 och lägg den i `wp-content/mu-plugins/`.

## CSS Hero

CSS Hero levererar sina ändringar som en statisk CSS-fil (`uploads/…/csshero-static-style-<tema>.css`) eller inbäddad CSS. Steg 5 läser exakt det besökarna får och:

- lägger CSS:en i **Utseende → Anpassa → Ytterligare CSS**, mellan markeringarna `/* cbx:csshero:start … */` och `/* cbx:csshero:end */`. Det du redan har där behålls, och `@import` flyttas överst som CSS kräver.
- avaktiverar CSS Hero.
- översätter regler som pekar på Crocoblock (via steg 4).
- flaggar **sköra selektorer** – regler som pekar på en position i sidans struktur, t.ex. `html > body > #content > div > … div:nth-child(2)`. De flyttas med men slutar gälla så fort layouten ändras; byt hellre till en CSS-klass på elementet.

Ångra tar bara bort CSS Hero-blocket ur Ytterligare CSS och aktiverar CSS Hero igen – ändringar du gjort efteråt finns kvar.

På tidningenm.se: 36 regler, varav 2 pekar på Crocoblock och 1 är skör. Typsnitten (Publico, Gilroy) kommer från Elementors egna typsnitt och påverkas inte.

## Vad ersätts automatiskt

| Crocoblock | Ersätts med | Kräver Pro |
|---|---|---|
| jet-posts, jet-blog-smart-listing, jet-blog-smart-tiles | Posts | ja |
| jet-tabs, jet-switcher | Tabs | – (mallinnehåll kräver Pro) |
| jet-accordion | Accordion / Toggle | – |
| jet-button, jet-download-button, jet-popup-action-button | Button | – |
| jet-headline | Heading | – |
| jet-animated-text | Animated Headline | ja (annars Heading) |
| jet-banner, jet-team-member | Image Box | – |
| jet-services | Icon Box / Image Box | – |
| jet-testimonials | Testimonial Carousel | ja (annars Testimonial) |
| jet-carousel, jet-brands | Image Carousel / Gallery | – |
| jet-slider | Slides | ja (annars Image Carousel) |
| jet-images-layout, jet-portfolio | Gallery | – |
| jet-progress-bar | Progress Bar | – |
| jet-circle-progress | Counter | – |
| jet-countdown-timer | Countdown | ja |
| jet-pricing-table, jet-price-list | Price Table, Price List | ja |
| jet-video, jet-audio, jet-map | Video, HTML-ljud, Google Maps | – |
| jet-table, jet-timeline, jet-unfold | Text (HTML) | – |
| jet-inline-svg | Image | – |
| jet-animated-box | Flip Box | ja |
| jet-logo, jet-nav-menu, jet-mega-menu, jet-custom-menu, jet-mobile-menu | Site Logo/Title, Nav Menu | ja |
| jet-search, jet-ajax-search | Search Form | ja (annars WP-sök) |
| jet-login, jet-blocks-cart, jet-blog-posts-navigation | Login, Menu Cart, Post Navigation | ja |
| jet-listing-grid | Loop Grid (efter steg 3) | ja |
| jet-listing-dynamic-field/-image/-link/-terms/-meta | Widgets med Elementor Pros dynamiska taggar | ja |
| Sticky-sektion (JetBlocks) | Elementor Pros sticky | ja |
| Popup-koppling på knapp/bild/rubrik | Dynamisk länk → Popup | ja |
| JetEngine-taggen "custom field" | Post Custom Field | ja |
| jet-listing-dynamic-field med ACF-källa | Elementor Pros ACF-tagg | ja |
| JetPopup | Elementor-popup med utlösare | ja |
| JetThemeCore | Theme Builder-mall med visningsvillkor | ja |

Innehåll, länkar, bilder, antal och frågeinställningar följer med, liksom gemensamma inställningar som marginal, padding, CSS-klasser, responsiv döljning och rörelseeffekter.

## Hur utseendet följer med

1. **Widgetens egen stil.** Elementor genererar CSS för varje Jet-widget utifrån dess inställningar. Tillägget läser den, översätter Crocoblocks klasser till den nya widgetens (t.ex. `.jet-posts .entry-title` → `.elementor-post__title`) och lägger resultatet som widgetens **Custom CSS** (Avancerat → Custom CSS i Elementor Pro). Utan Pro läggs samma CSS in i sidans Elementor-CSS.
2. **Grundstil.** Där Crocoblock och Elementor har olika standardutseende läggs en grundstil till som efterliknar Crocoblock – t.ex. menyns avstånd och färger (JetMenus `--jmm-…`-variabler), ingen understrykning under menypunkter, och nyhetslistornas bilder i naturligt format i stället för beskurna 3:2.
3. **Sajtens egen CSS** (steg 4) får översatta kopior, avgränsade till de konverterade widgetarna via klassen `cbx-from-<jet-typ>` som läggs på varje ny widget.

Stilöversättning finns för menyer (JetMenu, JetBlocks Nav Menu, Custom Menu), Posts, Smart Listing, Button, Headline, Tabs, Accordion, Search, Logo, Banner och Services. För övriga widgets ligger Crocoblocks värden kvar som en kommentar i widgetens CSS, så att de går att föra över för hand.

Testat mot tidningenm.se: huvudmenyn (JetMenu + `m-design/sidhuvud.css` + CSS Hero) och startsidans nyhetslista blir likadana som originalet.

## Vad blir kvar som manuellt

Inventeringen och provkörningen ger ett konkret råd för varje fall. De vanligaste:

- **JetSmartFilters** – Elementor Pros "Taxonomy Filter" (för Loop Grid) eller ett filtertillägg.
- **JetWooBuilder** – Elementor Pros WooCommerce-widgets och -mallar.
- **Kortkoder** `[jet_engine …]` och Gutenberg-block `wp:jet-…`.
- **Mega-menyer** (JetMenu-innehåll i menypunkter).
- **JetEngine** Custom Content Types, relationer, options-sidor, formulär och bildfält från metafält.
- Diagram, väder, Instagram, hotspots, bildjämförelse, horisontell tidslinje m.fl.

Widgets som inte kan konverteras lämnas orörda, så de syns så länge Crocoblock är aktivt.

## Säkerhet

- Provkörningen ändrar ingenting.
- Varje sida får en backup av sin Elementor-data vid första konverteringen. Senare körningar skriver aldrig över den, så återställning går alltid tillbaka till originalet.
- Mallar migreras som kopior. Sidhuvud, sidfot och popuper publiceras först i steg 7.
- Crocoblock kan inte avaktiveras av tillägget medan publicerat innehåll fortfarande använder det (om du inte uttryckligen kryssar i att du vill det).
- Elementors CSS-cache töms automatiskt efter konvertering.

## WP-CLI

```
wp crocoblock-exit scan                 # inventering (--format=csv|json)
wp crocoblock-exit snapshot             # innehållstyper (--export=fil.php)
wp crocoblock-exit templates            # mallar
wp crocoblock-exit site-css             # sajtens egen CSS
wp crocoblock-exit csshero --dry-run    # CSS Hero (utan --dry-run: flytta; --undo: ångra)
wp crocoblock-exit convert --dry-run    # provkörning (--verbose, --post=ID)
wp crocoblock-exit convert
wp crocoblock-exit switch               # publicera mallar + avaktivera Crocoblock (stoppar om Jet-widgets finns kvar; --force)
wp crocoblock-exit restore --templates  # återställ sidor + mallar, aktiverar Crocoblock igen (--no-reactivate)
wp crocoblock-exit autopilot            # alla steg som test (ändrar inget synligt)
wp crocoblock-exit autopilot --apply --switch   # genomför, kontrollera HTML, återställ vid fel
```

## Krav

WordPress 6.0+, PHP 7.4+, Elementor. Elementor Pro 3.8+ för Loop Grid; utan Pro konverteras bara det som går med gratisversionen.

## Tomma dynamiska fält

Widgets vars dynamiska innehåll (t.ex. ett ACF-fält) är tomt döljs vid
visning, tillsammans med rutor som därmed blir helt tomma – samma beteende
som Jets widgets hade. I Elementors redigerare visas allt som vanligt.

## Tester

```
php tests/run-tests.php     # konverteringskärnan
php tests/css-tests.php     # CSS-översättningen, med verklig CSS från tidningenm.se
php tests/integration.php   # hela flödet mot en WordPress-attrapp med SQLite, inkl. autopiloten
node tests/browser/autopilot-test.js   # autopilotens mätning, jämförelse och körning i Chromium (Playwright)
```

WP-CLI-autopiloten kör allt utom den visuella jämförelsen (den kräver en webbläsare); den publicerade HTML:en kontrolleras i stället.
