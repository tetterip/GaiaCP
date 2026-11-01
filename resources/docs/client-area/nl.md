## Inloggen

Log in met uw e-mailadres en uw wachtwoord. Bent u het wachtwoord vergeten, druk dan op "Wachtwoord vergeten?" op de inlogpagina en wij e-mailen u een link om een nieuw wachtwoord te kiezen. De link werkt één keer.

<!-- if:google_login -->
Is uw e-mailadres een Google-account, dan kunt u op de inlogpagina ook op **Doorgaan met Google** drukken in plaats van uw wachtwoord te typen.
<!-- endif -->

<!-- if:email_link -->
In plaats van het wachtwoord kunt u op **Stuur mij een inloglink** drukken: we e-mailen u een link die één keer werkt, 15 minuten lang. Open hem en druk op de pagina die opent op **Inloggen**.
<!-- endif -->

<!-- if:passkeys -->
Met **passkeys** logt u in met uw vingerafdruk, gezicht of schermvergrendeling in plaats van uw wachtwoord. Voeg er op elke telefoon of computer een toe in uw profiel met **Passkey op dit apparaat toevoegen** en druk daarna op de inlogpagina op **Inloggen met een passkey**. De inlogpagina markeert de manier waarop u het laatst op dit apparaat inlogde met "Laatst gebruikt".
<!-- endif -->

In uw profiel (het menu met uw naam, onderaan het menu of rechtsboven) wijzigt u uw e-mailadres, telefoonnummer, taal en wachtwoord, en kunt u tweestapsverificatie aanzetten voor extra veiligheid.

<!-- if:notifications -->
De **bel** bovenaan de pagina laat zien wat er is gebeurd sinds u voor het laatst keek: een nieuw betalingsverzoek, een betalingsherinnering of achterstandsmelding, een betaling die we hebben ontvangen, een dienst die klaar, opgeschort of beëindigd is, een domein dat binnenkort wordt verlengd, een nieuwe prijs, ons antwoord op uw ticket of op een verzoek. Een getal erop toont hoeveel u nog niet hebt gelezen; druk op een melding om de pagina ervan te openen. Op een telefoon of tablet veegt u een melding naar links of rechts om die te verwijderen. Wilt u ze niet meer, zet dan **Meldingen** uit in uw profiel. We blijven u zoals voorheen e-mailen.

<!-- endif -->
<!-- if:email_history -->
**E-mailgeschiedenis**, in hetzelfde menu, toont de e-mails die we u stuurden, nieuwste eerst: druk op een e-mail om hem opnieuw te lezen. E-mails met een wachtwoord of een inloglink worden zonder tekst vermeld; gebruik in plaats daarvan "Wachtwoord vergeten?" op de inlogpagina.

<!-- endif -->
**Apparaten en aanmeldingen**, in hetzelfde menu, toont de browsers die bij uw account zijn aangemeld en uw recente aanmeldingen, verkeerde wachtwoorden inbegrepen. Druk op **Uitloggen** naast een apparaat dat u niet meer gebruikt, of op **Andere apparaten uitloggen** om overal behalve hier af te melden. Ziet u een aanmelding die u niet herkent, wijzig dan ook uw wachtwoord.

<!-- if:cookies -->
"Cookie-instellingen" onderaan elke pagina toont de cookiekeuze opnieuw, zodat u die kunt wijzigen.
<!-- endif -->

<!-- if:welcome_tour -->
De eerste keer dat u inlogt, laat een korte rondleiding zien wat u hier kunt doen. Het menu onder uw naam → **Rondleiding** toont hem opnieuw.
<!-- endif -->

## Home

Home toont wat u moet betalen, hoeveel diensten actief zijn en wat als eerste wordt verlengd, met een link naar elk. Onze mededelingen, zoals gepland werk aan uw server, verschijnen bovenaan Home zolang ze van toepassing zijn.

## Diensten en domeinen

**Diensten** toont uw hosting en andere diensten met hun status, prijs en volgende vervaldatum. Het menu verdeelt ze in **Hosting** en **Andere diensten**. De pagina van een hostingaccount toont de betaalperiode en heeft een knop **Website openen**. **Domeinen** toont uw domeinen met hun verloopdatum, nameservers en of ze automatisch worden verlengd. Hebt u diensten met meer dan één status, dan tonen de knoppen boven elke lijst alleen die met één status (Actief, Opgeschort, …), met hoeveel het er zijn. Eenmalig werk, zoals het verhuizen van uw website, wordt één keer in rekening gebracht en verschijnt als **Voltooid** nadat u het hebt betaald.

<!-- if:hosting -->
Bij een actief hostingaccount kunt u:

<!-- if:hosting_login -->
- **Inloggen op configuratiescherm** (cPanel of Plesk) zonder het wachtwoord te typen: de knop opent het in een nieuw tabblad.
- De **Snelkoppelingen** (bij cPanel en Plesk) gebruiken om een pagina van het configuratiescherm direct te openen, zoals e-mailaccounts, bestandsbeheer of databases, al ingelogd.
<!-- endif -->
<!-- if:hosting_usage -->
- **Zien hoeveel schijfruimte en dataverkeer het gebruikt** deze maand, tegenover wat uw pakket toestaat.
<!-- endif -->
<!-- if:hosting_uptime -->
- **De uptime van de server zien**: hoeveel van de laatste 30 dagen en 24 uur de server waarop uw account staat online was. In de lijst Hosting toont een stip vóór het domein de huidige status van de server: groen zolang hij werkt, rood zolang hij niet reageert, oranje tijdens onderhoud. Wijs ernaar om de uptime van de laatste 30 dagen te zien.
<!-- endif -->
<!-- if:vps_power -->
- **Een VPS starten, afsluiten of herstarten** met de knoppen op de pagina ervan. Afsluiten en Herstarten stoppen de programma's die erop draaien, dus sla eerst uw werk op. Om de console te gebruiken, drukt u op **Inloggen op Proxmox VE** en meldt u zich aan met de gebruikersnaam en het wachtwoord van de dienst (realm "Proxmox VE authentication server"): u ziet alleen uw eigen VPS.
<!-- endif -->
<!-- if:hosting_password -->
- **Het wachtwoord wijzigen** van het account. Gebruik minstens 10 tekens met hoofdletters, kleine letters en cijfers.
<!-- endif -->
<!-- endif -->

Om een dienst naar een groter of kleiner pakket te verplaatsen, drukt u op **Upgrade / downgrade** op de pagina ervan en kiest u het nieuwe pakket; elk toont de prijs voor uw betaalperiode. Zodra wij het goedkeuren, geldt het nieuwe pakket meteen en de nieuwe prijs vanaf uw volgende vervaldatum (we kunnen ook een betalingsverzoek sturen voor de rest van de huidige periode). We bevestigen per e-mail; tot dan toont de pagina het verzoek en trekt **Verzoek pakketwijziging intrekken** het in.

<!-- if:orders -->
Zijn er add-ons beschikbaar voor een dienst (zoals dagelijkse back-ups), druk dan op **Een add-on toevoegen** op de pagina ervan en kies er een; elke add-on toont de prijs voor uw betaalperiode en wat u nu betaalt. U betaalt nu alleen voor de dagen tot de volgende vervaldatum van de dienst (plus eventuele installatiekosten), en daarna wordt de add-on samen met de dienst verlengd, op hetzelfde betalingsverzoek. Het betalingsverzoek opent meteen; de add-on wordt actief zodra hij is betaald, en de pagina toont hem onder **Add-ons** (met een link naar het verzoek zolang het wacht).

<!-- endif -->
Om een hostingaccount of een andere dienst te stoppen, drukt u op **Opzegging aanvragen** op de pagina ervan. Vermeld desgewenst waarom, en kies of de dienst eindigt **aan het einde van de betaalperiode** (u gebruikt hem tot de volgende vervaldatum) of **onmiddellijk** (het account en de bestanden worden verwijderd). Hebt u een domein met dezelfde naam, dan kunt u ons ook vragen het niet te verlengen. We bevestigen per e-mail zodra we het hebben uitgevoerd; tot dan toont de pagina het verzoek en trekt **Opzegverzoek intrekken** het in. Zet voor een domein in plaats daarvan automatisch verlengen uit.

Wanneer we de prijs van een dienst wijzigen, e-mailen we u vooraf, en de pagina ervan toont de nieuwe prijs onder **Prijs** met de datum vanaf wanneer die geldt (bijvoorbeeld *Vanaf 01/12/2026: €66,00*). Verlengingen die vóór die datum vervallen, behouden de huidige prijs, en al uitgegeven betalingsverzoeken veranderen niet.

<!-- if:incident_notice -->
Heeft een server waarop uw hosting staat een probleem of is hij in onderhoud, dan meldt een bericht bovenaan elke pagina dit en noemt het de betrokken diensten. Het verdwijnt vanzelf zodra alles weer werkt, dus u hoeft er geen contact met ons over op te nemen.

<!-- endif -->
<!-- if:ssl -->
Een **SSL-certificaat** wordt besteld voor het domein dat u opgeeft bij het bestellen, zodra het is betaald. De pagina ervan toont wat u moet doen om te bewijzen dat het domein van u is (de certificaatautoriteit controleert dit voordat er iets wordt uitgegeven), en we e-mailen het u ook:

- **DNS-record (CNAME):** voeg het getoonde record (naam, type en waarde) toe aan de DNS-zone van het domein.
- **Bestand op de website:** maak het getoonde bestand aan, met precies deze inhoud, zodat het op het getoonde adres opent.
- **E-mail aan de beheerder van het domein:** open de link in de e-mail die naar admin@ (of het gekozen adres) is gestuurd en keur goed.

Is het domein een hostingaccount dat u bij ons heeft, dan doen wij dit voor u: de pagina ervan zegt dat het al geregeld is, en zodra het certificaat is uitgegeven installeren we het ook op dat account, zodat u niets hoeft te downloaden of te installeren.

**Validatie wijzigen** kiest een andere manier (of een ander goedkeuringsadres) en **Opnieuw controleren** vraagt de certificaatautoriteit nu te kijken (**De goedkeuringsmail opnieuw verzenden** als het per e-mail gaat). Zodra het certificaat is uitgegeven, e-mailen we u, en **Certificaat downloaden** geeft een zip met het certificaat, de CA-bundel en, als wij die hebben gemaakt, de privésleutel, klaar om op de server te installeren. Een certificaat is maximaal 200 dagen geldig, dus zolang uw dienst loopt, geven we vóór het einde van elk certificaat het volgende uit: u valideert het domein dan telkens opnieuw en downloadt het nieuwe certificaat.

<!-- endif -->
<!-- if:domains -->
Bij een actief domein dat via ons is geregistreerd, kunt u:

<!-- if:domain_nameservers -->
- **De nameservers wijzigen** (2 tot 4). De wijziging gaat meteen naar het register; het internet ziet haar binnen enkele uren.
<!-- endif -->
<!-- if:domain_epp -->
- **De EPP-code opvragen** die u nodig hebt om het domein naar een andere provider te verhuizen. Bij sommige domeinen wordt hij op het scherm getoond; bij andere wordt hij naar het e-mailadres van de domeineigenaar gemaild.
<!-- endif -->
<!-- if:domain_auto_renew -->
- **Automatisch verlengen uit- of aanzetten.** Staat het uit, dan ontvangt u geen betalingsverzoeken meer voor het domein en eindigt het op de vervaldatum.
<!-- endif -->
<!-- if:domain_contacts -->
- **De contactgegevens wijzigen** (eigenaar, administratief, technisch en, bij sommige domeinen, financieel contact) zoals het register ze heeft. **Mijn accountgegevens gebruiken** vult een contact in vanuit uw profiel en **Zelfde als de eigenaar** kopieert die van de eigenaar. Sommige registers (zoals .gr) staan niet toe dat de naam van de eigenaar op deze manier wordt gewijzigd: neem daarvoor contact met ons op.
<!-- endif -->
<!-- if:domain_child_nameservers -->
- **Eigen nameservers registreren** genoemd naar uw domein (zoals ns1.uwdomein.gr) met hun IP-adres, hun IP wijzigen of ze verwijderen.
<!-- endif -->
<!-- if:domain_lock -->
- **Het domein vergrendelen of ontgrendelen** (registrarvergrendeling). Een vergrendeld domein kan niet naar een andere provider worden verhuisd; ontgrendel het pas vlak voor een verhuizing. Niet elk register heeft een vergrendeling.
<!-- endif -->
<!-- endif -->

## Betalingsverzoeken en betalingen

Wanneer een dienst vervalt, sturen we u per e-mail een **betalingsverzoek**, met de betaalinstructies. Het is een betalingsverzoek, geen factuur. **Betalingsverzoeken** toont ze allemaal: open er een om de regels te zien, het af te drukken of als PDF op te slaan. De knoppen boven de lijst tonen alleen de onbetaalde, betaalde of achterstallige. **Betalingen** toont de betalingen die we hebben geregistreerd. Nadat u hebt betaald, wordt het verzoek als betaald getoond zodra we uw betaling registreren.

<!-- if:invoices -->
Als wij facturen uitgeven, krijgt een betaald betalingsverzoek ook een **factuur**: we e-mailen die als pdf, het betaalde verzoek heeft een knop om hem te downloaden, en **Facturen** (onder Financiën) toont ze allemaal. Een **creditnota** daar, met een negatief totaal, maakt een factuur ongedaan, bijvoorbeeld wanneer een betaling wordt terugbetaald.
<!-- endif -->

<!-- if:pay_card -->
Om met een kaart te betalen, opent u een onbetaald betalingsverzoek en drukt u op **Betalen met kaart**. U betaalt op de beveiligde betaalpagina van Stripe (wij zien uw kaartgegevens nooit), en het verzoek wordt als betaald getoond zodra de betaling is gelukt.
<!-- endif -->

<!-- if:pay_paypal -->
Om met PayPal te betalen, opent u een onbetaald betalingsverzoek en drukt u op **Betalen met PayPal**. U keurt de betaling goed op de pagina van PayPal, met uw PayPal-saldo of een kaart, en het verzoek wordt als betaald getoond zodra PayPal het bevestigt.
<!-- endif -->

<!-- if:credit -->
## Offertes

Wanneer we u een offerte sturen, ontvangt u een e-mail met een link ernaartoe, en **Offertes** (onder Financiën) toont de offertes die we u stuurden, met hun eerste betaling en hoe lang ze geldig zijn. Druk op een offerte om hem te openen: daar kunt u hem afdrukken. **Accepteren** plaatst de bestelling tegen de prijzen van de offerte (met onze servicevoorwaarden, als we u vragen die te accepteren), en het betalingsverzoek wordt u gemaild met de betaalinstructies. **Afwijzen** laat ons weten dat u hem niet wilt; vermelden waarom helpt ons. Is de datum verstreken, dan kan een offerte niet meer worden geaccepteerd: beantwoord onze e-mail en we sturen u een nieuwe.

## Tegoed

**Tegoed** toont uw tegoed en de geschiedenis ervan. Om tegoed te storten, drukt u op **Tegoed storten** en voert u een bedrag in: we maken er een betalingsverzoek voor aan, dat u betaalt zoals elk ander. Zodra het is betaald, wordt het bedrag aan uw tegoed toegevoegd. U kunt tegoed storten zodra u een actieve dienst hebt.

Tegoed betaalt uw betalingsverzoeken regel voor regel: een regel (de verlenging van een dienst) wordt alleen betaald als uw tegoed hem volledig dekt, en wat het niet dekt, blijft op het verzoek staan om zoals gewoonlijk te betalen.
<!-- if:credit_auto -->
We gebruiken uw tegoed automatisch voor nieuwe betalingsverzoeken, en voor onbetaalde wanneer u tegoed stort.
<!-- endif -->
<!-- if:credit_manual -->
Om het te gebruiken, opent u een onbetaald betalingsverzoek en drukt u op **Betalen met tegoed**.
<!-- endif -->
<!-- endif -->

<!-- if:orders -->
## Bestellen

**Domein zoeken** (in het menu, na Domeinen) vindt een naam voor u: typ hem (mijnwinkel, of mijnwinkel.gr) en u ziet hem met elke extensie die we registreren, of hij vrij of bezet is, en de prijs. **In winkelwagen** zet een vrije naam in de winkelwagen (**Met SSL** voegt er ook een certificaat voor toe, als we dat aanbieden); voor een naam die van u is maar ergens anders staat, opent **Van mij: verhuizen** de bestelling voor de verhuizing. Mogelijk wordt u eerst gevraagd te bevestigen dat u geen robot bent. “Waarschijnlijk beschikbaar” betekent dat het register op dat moment niet kon worden bevraagd; we controleren het opnieuw wanneer we de naam registreren. Als we het aanbieden, toont **Andere namen voorstellen** onder de resultaten vrije namen die op de uwe lijken, voorgesteld door AI, met dezelfde extensie.

<!-- if:domain_prices -->
**Bekijk de prijzen van elke domeinextensie** onder het zoekvak toont elke extensie die we verkopen met de prijs voor registreren, verhuizen en verlengen.
<!-- endif -->

**Bestelling** toont wat u kunt bestellen; **Een nieuwe dienst bestellen** boven uw Diensten opent het ook (**Een domein bestellen** boven uw Domeinen opent domein zoeken). Verkopen we meerdere soorten diensten, kies dan eerst de soort (zoals gedeelde hosting of een VPS, of domeinnamen), daarna een pakket: **Pakketten vergelijken** eronder toont naast elkaar wat elk pakket omvat, met de prijs per betaalperiode, en de knoppen **Kiezen** kiezen ook een pakket. Badges naast de naam van een pakket (zoals Populair of Nieuw) wijzen op wat we aanraden of wat nieuw is. Kies een dienst en de betaalperiode, en eventuele opties die hij biedt (zoals een locatie of extra IP-adressen); elke optie toont wat ze aan de prijs toevoegt. Add-ons ervoor (zoals dagelijkse back-ups) kunt u hieronder aanvinken; ze staan op hetzelfde betalingsverzoek en worden er samen mee verlengd. Geef bij hosting aan of u het domein al hebt, een nieuw wilt registreren of er een wilt verhuizen dat u elders hebt (daarvoor hebt u de EPP-code nodig). U kunt ook los een domein bestellen, met een SSL-certificaat ervoor als we dat aanbieden (vink het aan onder het domein). Zodra u een domein typt om te registreren of te verhuizen, ziet u ernaast of het vrij is; een bezet domein kan alleen worden verhuisd, en een nog niet geregistreerd domein alleen geregistreerd.

Druk op **In winkelwagen** en de **Winkelwagen** opent (het winkelwagenpictogram bovenaan elke pagina opent hem ook, met het aantal items erin). Druk op **Iets anders bestellen** om meer toe te voegen, zoals twee domeinen en drie hostingpakketten, of op de prullenbak naast een item om het eruit te halen. Alles in de winkelwagen wordt één bestelling met één betalingsverzoek.

Heeft u een **kortingscode**, typ die dan in de winkelwagen: u ziet wat er afgaat voordat u de bestelling plaatst (een link met een code vult hem voor u in). Een kortingscode kan alleen de eerste betaling verlagen of ook elke verlenging, en verlaagt nooit installatiekosten.

Wat in de **aanbieding** is, toont de badge Aanbieding en de normale prijs doorgestreept. De aanbiedingsprijs geldt voor de eerste periode van wat u bestelt zolang de aanbieding loopt; verlengingen zijn tegen de normale prijs. Een kortingscode verlaagt een aanbiedingsprijs mogelijk niet verder.

<!-- if:terms -->
Lees voordat u de bestelling plaatst onze servicevoorwaarden (de link naast het vakje) en vink **Ik accepteer de servicevoorwaarden** aan.
<!-- endif -->

Wanneer u de bestelling plaatst, e-mailen we u het betalingsverzoek. We richten de diensten in zodra de bestelling klaar is, en controleren en registreren dan het domein.
<!-- endif -->

## Uw gegevens

Uw naam, bedrijf, btw-gegevens en adres staan op uw betalingsverzoeken en als eigenaar van uw domeinen. U kunt vanuit uw profiel vragen ze te wijzigen; wij bekijken de wijziging en keuren haar goed. E-mail en telefoon wijzigen meteen, net als de extra gegevens die we eventueel vragen onder Meer gegevens.

Ons nieuws en onze aanbiedingen eindigen met een link om ze te stoppen; e-mails over uw diensten, betalingsverzoeken en herinneringen blijven komen.

<!-- if:whois -->
## WHOIS

**WHOIS** in het menu zoekt een domeinnaam op: of hij geregistreerd is, bij welke registrar, wanneer hij geregistreerd werd en wanneer hij verloopt, zijn statussen en zijn nameservers. De contactgegevens van de houder worden niet getoond. Is de naam vrij, dan brengt **Registreren** u naar de domeinzoeker.
<!-- endif -->

<!-- if:knowledge_base -->
## Kennisbank

Onder Support → Kennisbank vindt u antwoorden op veelgestelde vragen, gegroepeerd per onderwerp. Typ een paar woorden in het zoekvak om een artikel te vinden; het vindt ze met of zonder accenten. Laat ons aan het einde van elk artikel weten of het hielp.
<!-- if:tickets -->
Terwijl u het onderwerp van een nieuw ticket schrijft, verschijnen eronder artikelen die uw vraag misschien al beantwoorden.
<!-- endif -->

<!-- endif -->
<!-- if:downloads -->
## Downloads

Onder Ondersteuning → **Downloads** vindt u bestanden die we met u delen, zoals handleidingen en software, per onderwerp. Sommige staan er alleen zolang u een bepaalde dienst hebt. Klik op **Downloaden** om er een op te slaan.
<!-- endif -->
<!-- if:tickets -->
## Supporttickets

Onder Support → Tickets gebruikt u **Een ticket openen** om ons iets te vragen: kies de afdeling en de dienst waarover het gaat, schrijf uw vraag en voeg bestanden toe, zoals schermafbeeldingen. We antwoorden op het ticket en per e-mail; druk op **Reageren** om terug te schrijven. Sluit het als u niets meer nodig hebt. Een gesloten ticket gaat weer open als u reageert, en een ticket dat we hebben beantwoord, sluit vanzelf als we een paar dagen niets van u horen.
<!-- endif -->
<!-- if:ticket_mail -->
U kunt onze ticketmails ook vanuit uw eigen mailbox beantwoorden: uw antwoord wordt aan het ticket toegevoegd, met de bijgevoegde bestanden. Een e-mail die u naar ons supportadres stuurt, opent een nieuw ticket.
<!-- endif -->

<!-- if:announcements -->
## Mededelingen

Onder Support → Mededelingen plaatsen we nieuws over onze diensten: nieuwe diensten, prijswijzigingen en gepland onderhoud, nieuwste eerst.
<!-- endif -->
<!-- if:server_status -->
## Serverstatus

Support → Serverstatus toont of onze servers op dit moment werken, met de vakjes van de laatste 30 dagen en 24 uur. Heeft er een een probleem, dan staat die bovenaan met wat we erover weten.
<!-- endif -->
<!-- if:contact_page -->
## Contact

Support → Contact bevat ons adres, telefoonnummer, e-mailadres en onze openingstijden, zoals wij ze invullen. Druk op een telefoonnummer of e-mailadres om te bellen of te schrijven, en op **Openen in kaarten** om ons te vinden.
<!-- endif -->

## Vragen

Schrijf ons op {company_email}.
